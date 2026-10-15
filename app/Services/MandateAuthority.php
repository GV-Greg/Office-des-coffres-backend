<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CouncilMandate;
use App\Models\MayorMandate;
use App\Models\Province;
use App\Models\ProvinceMinePrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * SEUL point d'entrée des modules pour savoir qui a le droit d'écrire au titre d'un poste
 * (admin/strategies/donnees-utilisateur.md §5quinquies). Ne répond que « maintenant » : pas de
 * paramètre de date (Q10).
 *
 * ⚠️ DEUX LIMITES À NE PAS « RÉPARER » (tour 05 du fil admin/echanges/mandats-lot1) :
 *
 * 1. Une province SANS AUCUNE autorité de poste est un état LÉGITIME. À chaque élection d'un
 *    dirigeant, tous les postes sont vidés jusqu'à ses nominations — un à deux jours tous les
 *    60 jours, et plus si le dirigeant démissionne. Un repli qui donnerait l'autorité à tous les
 *    conseillers pendant cette fenêtre serait un TROU DE SÉCURITÉ.
 *
 * 2. Une réponse négative veut dire « l'Office ne connaît pas de Juge », PAS « il n'y a pas de
 *    Juge ». L'Office ne connaît que les mandats déclarés par les joueurs (2 ou 3 conseillers sur
 *    12). Un module ne doit jamais conclure à un siège vacant depuis une absence d'information.
 */
class MandateAuthority
{
    public function activeMayorMandate(Character $character): ?MayorMandate
    {
        return MayorMandate::query()->inOfficeNow()
            ->where('character_id', $character->id)
            ->first();
    }

    /** Appartenance au conseil, quel que soit le poste. N'autorise rien à elle seule (§5quinquies). */
    public function activeCouncilMandate(Character $character): ?CouncilMandate
    {
        return CouncilMandate::query()->inOfficeNow()
            ->where('character_id', $character->id)
            ->first();
    }

    /**
     * Le personnage détient-il MAINTENANT ce poste titré ? Clé obligatoire (Q9) : l'autorisation se
     * vérifie par titre, jamais par appartenance. Le poste détenu est la période en vigueur de
     * council_office_periods (Q17) — sur un mandat lui-même en fonction.
     */
    public function holdsCouncilOffice(Character $character, string $officeKey): bool
    {
        return $this->councilMandateHoldingOffice($character, [$officeKey])->exists();
    }

    /**
     * Postes qui gèrent les mines d'une province (roadmap, « Registre des mines » ; Greg, 03/10/2026) :
     * le commissaire aux mines ET le bailli — le jeu le prévoit, et le bailli déclare gérer « les
     * mines et les carrières ». Sans condition d'absence du commissaire, délibérément : « aucun
     * commissaire déclaré » veut dire « l'Office n'en connaît pas » (limite 2 ci-dessus).
     */
    public const MINE_OFFICES = ['commissaire_mines', 'bailli'];

    /**
     * Province dont le personnage peut gérer les mines MAINTENANT, ou null (fil
     * admin/echanges/registre-mines, R1). C'est la province du MANDAT qui porte le poste, jamais la
     * résidence — un mandat peut être tenu hors de la province de résidence. Vérifiée par le TITRE,
     * jamais par l'appartenance au conseil (§5quinquies). Un personnage n'a qu'un mandat de conseil
     * en cours et un poste à la fois : au plus une province.
     */
    public function canManageMines(Character $character): ?Province
    {
        return $this->councilMandateHoldingOffice($character, self::MINE_OFFICES)->first()?->province;
    }

    /**
     * Postes qui CONSULTENT le Registre des mines : ceux qui le tiennent, plus le dirigeant de la
     * province (comte, duc…) — Greg, 08/10/2026 : « c'est le chef de la province et devrait pouvoir
     * voir tout ce qui concerne sa province ». Lecture seule : l'écriture reste à MINE_OFFICES.
     * Remplace le « le comte n'y accède pas » du brief Registre §4. Les autres conseillers, jamais.
     */
    public const MINE_READER_OFFICES = [...self::MINE_OFFICES, 'commissaire_commerce', 'leader'];

    /**
     * Province dont le personnage peut CONSULTER le Registre des mines maintenant, ou null. Même règle
     * que canManageMines (province du mandat, par le titre, maintenant seulement), élargie au dirigeant.
     */
    public function canReadMines(Character $character): ?Province
    {
        return $this->mineReaderMandate($character)?->province;
    }

    /**
     * Mandat en fonction par lequel le personnage consulte le Registre des mines MAINTENANT, ou null.
     * Les lectures s'y calent (fil registre-mines, R4) : mi-mandat et fin de mandat à partir de SON
     * `in_office_from`, prédécesseur = relevés d'autres personnages dans les 30 jours qui précèdent.
     */
    public function mineReaderMandate(Character $character): ?CouncilMandate
    {
        return $this->councilMandateHoldingOffice($character, self::MINE_READER_OFFICES)->with('province')->first();
    }

    /**
     * Postes qui gèrent les PRIX des mines d'une province (brief admin/content/brief-prix-registre.md ;
     * Greg, 14/10/2026) : le commissaire au commerce, le commissaire aux mines et le bailli, TOUS ÉGAUX
     * — ni hiérarchie, ni repli. Le dirigeant lit, il n'écrit pas.
     */
    public const PRICE_OFFICES = ['commissaire_commerce', 'commissaire_mines', 'bailli'];

    /** Qui CONSULTE les prix : les trois postes plus le dirigeant (même principe que le registre). */
    public const PRICE_READER_OFFICES = [...self::PRICE_OFFICES, 'leader'];

    /** Province dont le personnage peut gérer les prix MAINTENANT (province du POSTE), ou null. */
    public function canManagePrices(Character $character): ?Province
    {
        return $this->councilMandateHoldingOffice($character, self::PRICE_OFFICES)->with('province')->first()?->province;
    }

    /** Province dont le personnage peut consulter les prix maintenant, ou null. */
    public function canReadPrices(Character $character): ?Province
    {
        return $this->councilMandateHoldingOffice($character, self::PRICE_READER_OFFICES)->with('province')->first()?->province;
    }

    /**
     * Le personnage peut-il écrire un jeu de prix daté de `$date` dans cette province ? Deux conditions :
     * 1. il TENAIT l'un des trois postes dans la province ce jour-là (recouvrement du jour de Paris par
     *    une période de poste d'un mandat APPROUVÉ — un mandat révoqué ne donne plus rien, Greg,
     *    15/10/2026) ;
     * 2. cette période n'est pas terminée depuis plus de `price_edit_grace_days` : passé ce délai, les
     *    prix de la période sont verrouillés.
     */
    public function canEditPriceOn(Character $character, Province $province, string $date): bool
    {
        $timezone = config('mandates.timezone');
        $dayStart = Carbon::parse($date, $timezone)->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();
        $limit = Carbon::now()->subDays((int) config('mandates.price_edit_grace_days'));

        return CouncilMandate::query()
            ->where('status', CouncilMandate::APPROVED)
            ->where('character_id', $character->id)
            ->where('province_id', $province->id)
            ->whereHas('officePeriods', fn ($periods) => $periods
                ->where('started_at', '<', $dayEnd)
                ->where('ended_at', '>', $dayStart)
                ->where('ended_at', '>=', $limit)
                ->whereHas('office', fn ($office) => $office->whereIn('key', self::PRICE_OFFICES)))
            ->exists();
    }

    /** Le personnage a-t-il, un jour, tenu l'un des trois postes dans cette province (mandat approuvé) ? */
    public function everHeldPriceOffice(Character $character, Province $province): bool
    {
        return CouncilMandate::query()
            ->where('status', CouncilMandate::APPROVED)
            ->where('character_id', $character->id)
            ->where('province_id', $province->id)
            ->whereHas('officePeriods.office', fn ($office) => $office->whereIn('key', self::PRICE_OFFICES))
            ->exists();
    }

    public function canEditPrice(Character $character, ProvinceMinePrice $price): bool
    {
        return $this->canEditPriceOn($character, $price->province, $price->effective_from->toDateString());
    }

    /**
     * Personnages qui occupent MAINTENANT l'un de ces postes dans la province (destinataires du résumé
     * des prix). Aucune conclusion sur un siège vacant : seuls les mandats déclarés sont connus.
     *
     * @param  array<int, string>  $officeKeys
     * @return Collection<int, Character>
     */
    public function currentHolders(Province $province, array $officeKeys): Collection
    {
        $now = Carbon::now();

        return CouncilMandate::query()->inOfficeNow()
            ->where('province_id', $province->id)
            ->whereHas('officePeriods', fn ($periods) => $periods
                ->where('started_at', '<=', $now)
                ->where('ended_at', '>', $now)
                ->whereHas('office', fn ($office) => $office->whereIn('key', $officeKeys)))
            ->with('character.user')
            ->get()
            ->map(fn (CouncilMandate $mandate) => $mandate->character)
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * Mandat de conseil en fonction dont la période de poste EN VIGUEUR (council_office_periods) porte
     * l'un de ces titres — sur un mandat lui-même en fonction (Q17).
     *
     * @param  array<int, string>  $officeKeys
     */
    private function councilMandateHoldingOffice(Character $character, array $officeKeys): Builder
    {
        $now = Carbon::now();

        return CouncilMandate::query()->inOfficeNow()
            ->where('character_id', $character->id)
            ->whereHas('officePeriods', fn ($periods) => $periods
                ->where('started_at', '<=', $now)
                ->where('ended_at', '>', $now)
                ->whereHas('office', fn ($office) => $office->whereIn('key', $officeKeys)));
    }
}
