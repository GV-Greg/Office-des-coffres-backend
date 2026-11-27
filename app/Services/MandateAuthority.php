<?php

namespace App\Services;

use App\Models\Character;
use App\Models\City;
use App\Models\CouncilMandate;
use App\Models\CouncilOffice;
use App\Models\CustomsOfficer;
use App\Models\MayorMandate;
use App\Models\Province;
use App\Models\ProvinceMinePrice;
use App\Support\MandateCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * SEUL point d'entrée des modules pour savoir qui a le droit d'écrire au titre d'un poste. Ne répond que « maintenant » : pas de
 * paramètre de date (Q10).
 *
 * ⚠️ DEUX LIMITES À NE PAS « RÉPARER » (tour 05) :
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
     * Province dont le personnage peut gérer les mines MAINTENANT, ou null. C'est la province du MANDAT qui porte le poste, jamais la
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
     * Mandats de conseil PASSÉS par lesquels le personnage a consulté le Registre des mines (22/11/2026) :
     * mandats `approved` — JAMAIS `revoked`, une révocation retire l'accès à ce que le mandat a fait —, déjà entrés en
     * fonction, TERMINÉS (`holds_until` échu), qui ont eu au moins une période de poste de MINE_READER_OFFICES.
     * Pas de limite de temps : tant que les relevés existent, le bilan reste lisible — mais seulement ceux de la
     * fenêtre du mandat (MineRegistryReader). Ne dit pas « maintenant » : c'est une règle d'HISTORIQUE, à part des
     * méthodes ci-dessus qui restent « maintenant seulement » (Q10). Le cas d'un poste vidé pendant un mandat qui
     * CONTINUE (élection d'un dirigeant) n'est pas couvert ici (hors scope, décision de Greg).
     *
     * @return Collection<int, CouncilMandate> `province` et `officePeriods.office` chargées, du plus récent au plus ancien
     */
    public function pastMineReaderMandates(Character $character): Collection
    {
        // Un mandat qui attend encore son successeur est lu comme « en cours » : il n'est pas passé.
        $current = $this->mineReaderMandate($character);

        return CouncilMandate::query()
            ->when($current, fn ($query) => $query->whereKeyNot($current->id))
            ->where('character_id', $character->id)
            ->where('status', CouncilMandate::APPROVED)
            ->whereNotNull('in_office_from')
            ->where('holds_until', '<=', Carbon::now())
            ->whereHas('officePeriods', fn ($periods) => $periods->whereHas('office', fn ($office) => $office->whereIn('key', self::MINE_READER_OFFICES)))
            ->with(['province', 'officePeriods' => fn ($periods) => $periods->with('office')->orderByDesc('started_at')])
            ->orderByDesc('holds_until')
            ->get();
    }

    /**
     * Le DERNIER poste de MINE_READER_OFFICES tenu sur ce mandat (un poste peut changer pendant le mandat, par
     * réattribution), ou null. Lit les périodes chargées si elles le sont.
     */
    public function lastMineReaderOffice(CouncilMandate $mandate): ?CouncilOffice
    {
        return $mandate->officePeriods->loadMissing('office')
            ->sortByDesc('started_at')
            ->first(fn ($period) => in_array($period->office?->key, self::MINE_READER_OFFICES, true))
            ?->office;
    }

    /**
     * Postes qui gèrent les PRIX des mines d'une province (Greg, 14/10/2026) : le commissaire au commerce, le commissaire aux mines et le bailli, TOUS ÉGAUX
     * — ni hiérarchie, ni repli. Le dirigeant lit, il n'écrit pas.
     */
    public const PRICE_OFFICES = ['commissaire_commerce', 'commissaire_mines', 'bailli'];

    /** Qui CONSULTE les prix : les trois postes plus le dirigeant (même principe que le registre). */
    public const PRICE_READER_OFFICES = [...self::PRICE_OFFICES, 'leader'];

    /**
     * Province dont le personnage peut gérer les prix MAINTENANT (province du POSTE), ou null. Seulement jusqu'au TERME du mandat
     * (`valid_until`) : un conseiller qui attend son successeur garde les autres droits de son poste, jamais l'écriture des prix
     * (Greg, 27/11/2026).
     */
    public function canManagePrices(Character $character): ?Province
    {
        return $this->councilMandateHoldingOffice($character, self::PRICE_OFFICES)
            ->where('valid_until', '>', Carbon::now())
            ->with('province')->first()?->province;
    }

    /** Province dont le personnage peut consulter les prix maintenant, ou null. */
    public function canReadPrices(Character $character): ?Province
    {
        return $this->councilMandateHoldingOffice($character, self::PRICE_READER_OFFICES)->with('province')->first()?->province;
    }

    /**
     * Le personnage peut-il écrire un jeu de prix daté de `$date` dans cette province ? Deux conditions :
     * 1. il TENAIT l'un des trois postes dans cette province : son mandat de conseil APPROUVÉ (un mandat révoqué
     *    ne donne plus rien, Greg, 15/10/2026) couvre ce jour de Paris — de l'entrée en fonction au terme —
     *    et l'Office lui a connu l'un de ces postes (une période de poste, quelle que soit sa date) ;
     * 2. le terme de ce mandat n'est pas passé (et le mandat n'a pas pris fin plus tôt) : à son terme, les prix de la
     *    période sont verrouillés, même pendant l'attente du successeur (Greg, 27/11/2026).
     *
     * ⚠️ Pourquoi le MANDAT et non la période de poste (constaté en prod le 16/10/2026) : une période naît à la
     * VALIDATION du poste dans l'Office (council_office_periods.started_at), parfois des jours après son début réel
     * en jeu — le commissaire aux mines de Franche-Comté, en fonction le 29/09, ne pouvait pas dater un prix du 29/09
     * parce que son poste était connu depuis le 08/10. L'Office ne sait pas depuis quand le poste est tenu (limite 2
     * ci-dessus) : la fenêtre du mandat est la meilleure approximation.
     */
    public function canEditPriceOn(Character $character, Province $province, string $date): bool
    {
        return $this->heldOfficeDuringTermOn($character, $province, $date, self::PRICE_OFFICES);
    }

    /**
     * La règle d'écriture d'un prix daté, commune aux prix des mines et à ceux de la nourriture du cheptel.
     *
     * @param  array<int, string>  $officeKeys
     */
    private function heldOfficeDuringTermOn(Character $character, Province $province, string $date, array $officeKeys): bool
    {
        // Le jour du jeu va de 04:00 à 04:00 (heure de Paris) : bornes ramenées en UTC, car la base stocke en UTC et le query builder
        // formate un Carbon tel quel, sans conversion — sans cela, la comparaison glisse de 1 à 2 heures (constaté le 16/10/2026).
        $dayStart = MandateCalendar::startOfGameDay($date);
        $dayEnd = MandateCalendar::endOfGameDay($date);
        $now = Carbon::now();

        return CouncilMandate::query()
            ->where('status', CouncilMandate::APPROVED)
            ->where('character_id', $character->id)
            ->where('province_id', $province->id)
            ->whereNotNull('in_office_from')
            ->where('in_office_from', '<', $dayEnd)
            ->where('valid_until', '>', $dayStart)
            ->where('valid_until', '>', $now)
            ->where('holds_until', '>', $now)
            ->whereHas('officePeriods.office', fn ($office) => $office->whereIn('key', $officeKeys))
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
        return CouncilMandate::query()->inOfficeNow()
            ->where('province_id', $province->id)
            ->whereHas('officePeriods', fn ($periods) => $periods
                ->inForce()
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

    /**
     * Livre des naissances (Greg, 27/11/2026) : le BAILLI inscrit les naissances et tient le prix de la nourriture ; le
     * dirigeant lit. Les deux règlent les espèces élevées dans la province. Par le titre, province du poste ; l'attente du
     * successeur vaut pour tout, sauf pour écrire un prix.
     */
    public const LIVESTOCK_WRITER_OFFICES = ['bailli'];

    public const LIVESTOCK_READER_OFFICES = ['bailli', 'leader'];

    /** Mandat par lequel le personnage inscrit des naissances MAINTENANT, ou null. */
    public function birthWriterMandate(Character $character): ?CouncilMandate
    {
        return $this->councilMandateHoldingOffice($character, self::LIVESTOCK_WRITER_OFFICES)->with('province')->first();
    }

    /** Mandat par lequel le personnage lit le Livre de sa province (et en règle les espèces) MAINTENANT, ou null. */
    public function livestockReaderMandate(Character $character): ?CouncilMandate
    {
        return $this->councilMandateHoldingOffice($character, self::LIVESTOCK_READER_OFFICES)->with('province')->first();
    }

    /** Province dont le personnage peut écrire les prix de la nourriture : jusqu'au TERME du mandat seulement. */
    public function canManageFeedPrices(Character $character): ?Province
    {
        return $this->councilMandateHoldingOffice($character, self::LIVESTOCK_WRITER_OFFICES)
            ->where('valid_until', '>', Carbon::now())
            ->with('province')->first()?->province;
    }

    public function canEditFeedPriceOn(Character $character, Province $province, string $date): bool
    {
        return $this->heldOfficeDuringTermOn($character, $province, $date, self::LIVESTOCK_WRITER_OFFICES);
    }

    /** Le personnage a-t-il, un jour, tenu le poste qui écrit ces prix (mandat approuvé) ? */
    public function everHeldFeedPriceOffice(Character $character): bool
    {
        return CouncilMandate::query()
            ->where('status', CouncilMandate::APPROVED)
            ->where('character_id', $character->id)
            ->whereHas('officePeriods.office', fn ($office) => $office->whereIn('key', self::LIVESTOCK_WRITER_OFFICES))
            ->exists();
    }

    /**
     * Mandats PASSÉS par lesquels le personnage a lu le Livre : approuvés (jamais révoqués), entrés en fonction, terminés, avec
     * un poste de lecture. Un mandat qui attend encore son successeur est « en cours », pas passé.
     *
     * @return Collection<int, CouncilMandate>
     */
    public function pastLivestockReaderMandates(Character $character): Collection
    {
        $current = $this->livestockReaderMandate($character);

        return CouncilMandate::query()
            ->when($current, fn ($query) => $query->whereKeyNot($current->id))
            ->where('character_id', $character->id)
            ->where('status', CouncilMandate::APPROVED)
            ->whereNotNull('in_office_from')
            ->where('holds_until', '<=', Carbon::now())
            ->whereHas('officePeriods.office', fn ($office) => $office->whereIn('key', self::LIVESTOCK_READER_OFFICES))
            ->with('province')
            ->orderByDesc('holds_until')
            ->get();
    }

    /**
     * Inventaires (Greg, 11/11/2026).
     *
     * VILLE : le maire en fonction, lecture et écriture — la ville de son MANDAT. PROVINCE : le commissaire au commerce
     * SEUL écrit (ni le commissaire aux mines ni le bailli, à la différence des prix) ; il lit, avec le dirigeant de la
     * province (lecture seule). Province du POSTE, jamais la résidence ; par le titre, maintenant seulement.
     */
    public const INVENTORY_PROVINCE_OFFICES = ['commissaire_commerce'];

    public const INVENTORY_PROVINCE_READER_OFFICES = ['commissaire_commerce', 'leader'];

    /** Ville dont le personnage est le maire en fonction MAINTENANT, ou null. */
    public function inventoryCity(Character $character): ?City
    {
        return $this->activeMayorMandate($character)?->city;
    }

    /** Province dont le personnage peut ÉCRIRE l'inventaire (commissaire au commerce en fonction), ou null. */
    public function canManageProvinceInventory(Character $character): ?Province
    {
        return $this->provinceInventoryMandate($character)?->province;
    }

    /** Le mandat qui porte le poste d'ÉCRITURE de l'inventaire de la province (commissaire au commerce en fonction), ou null. */
    public function provinceInventoryMandate(Character $character): ?CouncilMandate
    {
        return $this->councilMandateHoldingOffice($character, self::INVENTORY_PROVINCE_OFFICES)->with('province')->first();
    }

    /** Le mandat qui porte le poste de LECTURE de l'inventaire de la province (commissaire au commerce ou dirigeant), ou null. */
    public function provinceInventoryReaderMandate(Character $character): ?CouncilMandate
    {
        return $this->councilMandateHoldingOffice($character, self::INVENTORY_PROVINCE_READER_OFFICES)->with('province')->first();
    }

    /** Province dont le personnage peut CONSULTER l'inventaire (commissaire au commerce ou dirigeant), ou null. */
    public function canReadProvinceInventory(Character $character): ?Province
    {
        return $this->provinceInventoryReaderMandate($character)?->province;
    }

    /**
     * Module Sécurité (Greg le 21/11/2026).
     *
     * TRIO de sécurité : prévôt des maréchaux, connétable, capitaine. VALIDATION des douaniers en cascade : niveau 1 = prévôt et/ou
     * connétable ; niveau 2 = capitaine, dirigeant (comte) ou maire d'une ville de la province, SEULEMENT si ni prévôt ni connétable
     * n'a de mandat en fonction ; niveau 3 = l'administrateur (hors de cette classe).
     *
     * ⚠️ Limite 2 de la classe : « aucun prévôt ni connétable » veut dire « l'Office n'en connaît pas », ce qui est l'état COURANT
     * (2 ou 3 conseillers sur 12 sont déclarés) — le repli est donc le mode normal d'une province, pas une exception (Greg, 21/11 :
     * conception conservée, dite à l'écran).
     */
    public const SECURITY_TRIO = ['prevot_des_marechaux', 'connetable', 'capitaine'];

    public const CUSTOMS_PRIMARY_OFFICES = ['prevot_des_marechaux', 'connetable'];

    public const CUSTOMS_FALLBACK_OFFICES = ['capitaine', 'leader'];

    /**
     * Niveau (1 ou 2) auquel le personnage valide ou révoque les douaniers de cette province MAINTENANT, ou null. Le niveau 2 s'éteint
     * dès qu'un prévôt ou un connétable est en fonction dans la province. Par le TITRE, jamais par l'appartenance au conseil.
     */
    public function customsDeciderLevel(Character $character, Province $province): ?int
    {
        if ($this->holdsOfficeInProvince($character, $province, self::CUSTOMS_PRIMARY_OFFICES)) {
            return 1;
        }

        if ($this->hasPrimaryCustomsDecider($province)) {
            return null;
        }

        $mayorHere = $this->activeMayorMandate($character)?->city?->province_id === $province->id;

        return $mayorHere || $this->holdsOfficeInProvince($character, $province, self::CUSTOMS_FALLBACK_OFFICES) ? 2 : null;
    }

    /** Province où le personnage valide les douaniers maintenant, avec son niveau, ou null. */
    public function customsDeciderScope(Character $character): ?array
    {
        $province = $this->councilMandateHoldingOffice($character, self::CUSTOMS_PRIMARY_OFFICES)->with('province')->first()?->province;
        if ($province) {
            return ['province' => $province, 'level' => 1];
        }

        $candidates = collect([
            $this->councilMandateHoldingOffice($character, self::CUSTOMS_FALLBACK_OFFICES)->with('province')->first()?->province,
            $this->activeMayorMandate($character)?->city?->province,
        ])->filter();

        foreach ($candidates as $candidate) {
            if ($this->customsDeciderLevel($character, $candidate) === 2) {
                return ['province' => $candidate, 'level' => 2];
            }
        }

        return null;
    }

    /** Un prévôt des maréchaux ou un connétable est-il en fonction dans cette province (connu de l'Office) ? */
    public function hasPrimaryCustomsDecider(Province $province): bool
    {
        return $this->currentHolders($province, self::CUSTOMS_PRIMARY_OFFICES)->isNotEmpty();
    }

    /** Le trio de sécurité (prévôt, connétable, capitaine) a-t-il au moins un occupant connu en fonction ? */
    public function provinceHasSecurityTrio(Province $province): bool
    {
        return $this->currentHolders($province, self::SECURITY_TRIO)->isNotEmpty();
    }

    /** Douanier EN VIGUEUR du personnage, ou null. */
    public function activeCustomsOfficer(Character $character): ?CustomsOfficer
    {
        return CustomsOfficer::query()->approved()->where('character_id', $character->id)->first();
    }

    /**
     * Postes du conseil qui donnent un accès au Registre de douane (matrice du brief §3, corrigée par Greg le 22/11/2026). Le trio de
     * sécurité (prévôt, connétable, capitaine) lit et écrit ; le juge n'ajoute qu'au rouge ; le procureur lit les listes ; le dirigeant lit tout.
     */
    public const SECURITY_COUNCIL_OFFICES = ['prevot_des_marechaux', 'connetable', 'capitaine', 'juge', 'procureur', 'leader'];

    /**
     * Ce que le personnage est, MAINTENANT, dans UNE province pour le Registre de douane, ou null s'il n'y a aucun rôle. Une province seulement :
     * le poste du conseil d'abord, puis la mairie, puis le statut de douanier ; les autres rôles ne comptent que dans la MÊME province. Par le
     * titre, jamais par l'appartenance au conseil (§5quinquies) ; réponse négative = « l'Office ne connaît pas » (limite 2).
     *
     * @return array{province: Province, office: ?string, mayor_city: ?City, customs: ?CustomsOfficer}|null
     */
    public function securityScope(Character $character): ?array
    {
        $mandate = $this->councilMandateHoldingOffice($character, self::SECURITY_COUNCIL_OFFICES)->with('province', 'officePeriods.office')->first();
        $office = $mandate?->officePeriods()->inForce()->with('office')->get()
            ->first(fn ($period) => in_array($period->office?->key, self::SECURITY_COUNCIL_OFFICES, true))
            ?->office?->key;

        $mayorCity = $this->activeMayorMandate($character)?->city?->loadMissing('province');
        $customs = $this->activeCustomsOfficer($character)?->loadMissing('province');

        $province = $mandate?->province ?? $mayorCity?->province ?? $customs?->province;
        if (! $province) {
            return null;
        }

        return [
            'province' => $province,
            'office' => $mandate?->province_id === $province->id ? $office : null,
            'mayor_city' => $mayorCity?->province_id === $province->id ? $mayorCity : null,
            'customs' => $customs?->province_id === $province->id ? $customs : null,
        ];
    }

    private function holdsOfficeInProvince(Character $character, Province $province, array $officeKeys): bool
    {
        return $this->councilMandateHoldingOffice($character, $officeKeys)->where('province_id', $province->id)->exists();
    }

    private function councilMandateHoldingOffice(Character $character, array $officeKeys): Builder
    {
        return CouncilMandate::query()->inOfficeNow()
            ->where('character_id', $character->id)
            ->whereHas('officePeriods', fn ($periods) => $periods
                ->inForce()
                ->whereHas('office', fn ($office) => $office->whereIn('key', $officeKeys)));
    }
}
