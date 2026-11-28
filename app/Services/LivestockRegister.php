<?php

namespace App\Services;

use App\Exceptions\MandateRefusal;
use App\Models\Character;
use App\Models\CouncilMandate;
use App\Models\LivestockBirth;
use App\Models\Province;
use App\Models\ProvinceLivestockSetting;
use App\Support\Livestock;
use App\Support\MandateCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * SEUL écrivain des naissances et des espèces d'une province (Livre des naissances). L'autorisation vient de MandateAuthority ;
 * le contrôleur ne valide que la forme.
 *
 * Règles (politique §5) : une naissance s'inscrit pour un jour du mandat, jusqu'à aujourd'hui, attente du successeur comprise ;
 * elle se retire le jour du jeu où elle a été inscrite, par celui qui l'a inscrite, sans être effacée. Aucun lecteur n'apprend
 * qui l'a inscrite.
 */
class LivestockRegister
{
    /** Nombre de naissances servies à la lecture, les plus récentes d'abord. */
    private const SERVED = 500;

    /** @return array<int, string>|null espèces réglées pour la province, null si rien n'a jamais été réglé */
    public function species(int $provinceId): ?array
    {
        return ProvinceLivestockSetting::query()->where('province_id', $provinceId)->first()?->species;
    }

    /** @param  array<int, string>  $species */
    public function setSpecies(Province $province, array $species): array
    {
        // Ordre du référentiel, sans doublon.
        $ordered = array_values(array_intersect(array_keys(Livestock::SPECIES), $species));
        ProvinceLivestockSetting::updateOrCreate(['province_id' => $province->id], ['species' => $ordered]);

        return $ordered;
    }

    /**
     * @param  array{born_on: string, species: string, ingredient: string, quantity: int, taken: int, wasted: numeric}  $data
     *
     * @throws MandateRefusal
     */
    public function record(Character $actor, CouncilMandate $mandate, array $data): LivestockBirth
    {
        $first = MandateCalendar::gameDayOf($mandate->in_office_from);
        if ($data['born_on'] > MandateCalendar::today() || $data['born_on'] < $first) {
            throw MandateRefusal::make('born_on', 'livestock.api.birth_outside_term');
        }

        $raised = $this->species($mandate->province_id);
        if (! $raised) {
            throw MandateRefusal::make('species', 'livestock.api.not_configured');
        }
        if (! in_array($data['species'], $raised, true)) {
            throw MandateRefusal::make('species', 'livestock.api.species_not_raised');
        }
        if (! in_array($data['ingredient'], Livestock::SPECIES[$data['species']], true)) {
            throw MandateRefusal::make('ingredient', 'livestock.api.bad_feed');
        }

        return LivestockBirth::create([
            'province_id' => $mandate->province_id,
            'born_on' => $data['born_on'],
            'character_id' => $actor->id,
            'payload' => [
                'species' => $data['species'],
                'ingredient' => $data['ingredient'],
                'quantity' => (int) $data['quantity'],
                'taken' => (int) $data['taken'],
                'wasted' => (float) $data['wasted'],
            ],
        ]);
    }

    /** Le jour du jeu même, par celui qui l'a inscrite. */
    public function canWithdraw(Character $actor, LivestockBirth $birth): bool
    {
        return $birth->withdrawn_at === null
            && $birth->character_id === $actor->id
            && MandateCalendar::gameDayOf($birth->created_at) === MandateCalendar::today();
    }

    /** @throws MandateRefusal */
    public function withdraw(Character $actor, LivestockBirth $birth): void
    {
        if (! $this->canWithdraw($actor, $birth)) {
            throw MandateRefusal::make('birth', 'livestock.api.withdraw_closed');
        }

        $birth->update(['withdrawn_at' => Carbon::now(), 'withdrawn_by_character_id' => $actor->id]);
    }

    /**
     * Les naissances comptées (jamais les retirées), les plus récentes d'abord, entre deux jours inclus.
     * `$writer` : le personnage qui peut écrire, pour dire ce qu'il peut retirer.
     */
    public function births(int $provinceId, ?string $from = null, ?string $to = null, ?Character $writer = null): Collection
    {
        return LivestockBirth::query()
            ->where('province_id', $provinceId)
            ->whereNull('withdrawn_at')
            ->when($from, fn ($query) => $query->whereDate('born_on', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('born_on', '<=', $to))
            ->orderByDesc('born_on')->orderByDesc('id')
            ->limit(self::SERVED)
            ->get()
            ->map(fn (LivestockBirth $birth) => $this->present($birth, $writer))
            ->values();
    }

    /** @return array<string, mixed> */
    public function present(LivestockBirth $birth, ?Character $writer = null): array
    {
        return [
            'id' => $birth->id,
            'born_on' => $birth->born_on->toDateString(),
        ] + $birth->payload + [
            'can_withdraw' => $writer !== null && $this->canWithdraw($writer, $birth),
        ];
    }

    /**
     * Fenêtre de lecture d'un mandat PASSÉ : de l'entrée en fonction à sa fin enregistrée, étendue aux naissances que le
     * personnage a lui-même inscrites pendant l'attente de son successeur. Rien de postérieur.
     *
     * @return array{0: string, 1: string}
     */
    public function pastWindow(Character $character, CouncilMandate $mandate): array
    {
        $from = MandateCalendar::gameDayOf($mandate->in_office_from);
        $end = MandateCalendar::shiftGameDay(MandateCalendar::gameDayOf($mandate->holds_until), -1);
        $limit = MandateCalendar::addGameDays($mandate->valid_until, (int) config('mandates.council_waiting_days') + 1);

        $own = LivestockBirth::query()
            ->where('province_id', $mandate->province_id)
            ->where('character_id', $character->id)
            ->whereDate('born_on', '>=', $from)
            ->where('created_at', '<=', $limit)
            ->max('born_on');

        return [$from, max($end, $own ? substr((string) $own, 0, 10) : $end)];
    }
}
