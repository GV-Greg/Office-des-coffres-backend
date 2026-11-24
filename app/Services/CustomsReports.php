<?php

namespace App\Services;

use App\Exceptions\MandateRefusal;
use App\Models\Character;
use App\Models\City;
use App\Models\CustomsReport;
use App\Support\CustomsPseudo;
use App\Support\MandateCalendar;
use Illuminate\Support\Facades\DB;

/**
 * SEUL écrivain des bilans de défense (Registre de douane ; fil admin/echanges/module-securite, décisions de Greg du 22/11/2026).
 *
 * - UN bilan par ville et par jour du jeu, tenu PAR LA BASE (`active`). Un douanier qui arrive sur une ville déjà saisie voit le bilan et n'en
 *   saisit pas un second (Q5). Le MÊME auteur COMPLÈTE et CORRIGE le sien le même jour (24/11/2026) ; remplacer le bilan d'un autre, ou saisir un
 *   jour passé (jusqu'à 7), revient au prévôt et au connétable (P1, P6). Un bilan ne s'efface jamais : il se remplace.
 * - Le serveur reçoit le JSON analysé PAR LE FRONTEND et le brut, VALIDE LA FORME (contrôleur), ne re-parse JAMAIS (décision de Greg, comme les
 *   mines). Brut et analyse sont conservés dans le payload chiffré, avec `parser_version` par section.
 * - La LECTURE ne sert jamais le brut ni l'auteur : l'auteur d'un bilan n'est jamais affiché (politique §6, `by_me` seulement). La coloration
 *   rouge/verte est calculée à la lecture, en minuscules, à partir de la date d'ajout des entrées ; le différentiel du guet compare au dernier
 *   bilan antérieur de la ville et dit lequel.
 * - La saisie par un douanier remet son compteur d'inactivité à zéro (CustomsOfficers::recordReport) — seule action qui le fait.
 */
class CustomsReports
{
    public const SECTIONS = ['guet', 'visions', 'armies_groups'];

    /** Le guet ne se retire pas : un bilan se fonde sur lui (un premier bilan l'exige). */
    private const REMOVABLE_SECTIONS = ['visions', 'armies_groups'];

    /** Version minimale du parseur du frontend acceptée : un client plus ancien est invité à recharger. */
    public const MIN_PARSER_VERSION = 1;

    public function __construct(
        private readonly CustomsAccess $access,
        private readonly CustomsWatchlist $watchlist,
        private readonly CustomsOfficers $officers,
    ) {}

    /**
     * @param  array<string, array{raw: string, parser_version: int, report: array}>  $sections  sections VALIDÉES en forme (guet, visions, armies_groups)
     * @param  array<int, string>  $remove  sections à RETIRER du bilan existant (visions, armies_groups : jamais le guet) — Greg, 24/11/2026 : une section inscrite
     *                                      par mégarde ; seulement par qui peut corriger, la version précédente reste en archive
     * @return array{report: CustomsReport, replaced: ?CustomsReport, mode: string}
     *
     * @throws MandateRefusal
     */
    public function record(Character $actor, City $city, array $sections, ?string $date = null, array $remove = []): array
    {
        $access = $this->access->for($actor);
        if (! $access || ! $access['write_reports']) {
            throw MandateRefusal::make('character', 'security.api.reports_not_authorized');
        }
        if (! $this->access->canWriteIn($access, $city)) {
            throw MandateRefusal::make('city', 'security.api.city_not_in_scope');
        }

        $today = MandateCalendar::today();
        $day = $date ?? $today;
        if ($day !== $today) {
            if (! $access['correct_reports']) {
                throw MandateRefusal::make('date', 'security.api.report_date_not_allowed');
            }
            if ($day > $today || $day < $this->access->earliestDay($access)) {
                throw MandateRefusal::make('date', 'security.api.report_date_out_of_range', ['days' => CustomsAccess::BACKDATE_DAYS]);
            }
        }

        $sections = array_intersect_key($sections, array_flip(self::SECTIONS));
        $remove = array_values(array_diff(array_intersect($remove, self::REMOVABLE_SECTIONS), array_keys($sections)));
        foreach ($sections as $section) {
            if (($section['parser_version'] ?? 0) < self::MIN_PARSER_VERSION) {
                throw MandateRefusal::make('sections', 'security.api.parser_outdated');
            }
        }

        $result = DB::transaction(function () use ($actor, $city, $sections, $remove, $day, $today, $access) {
            $existing = CustomsReport::query()->where('city_id', $city->id)->whereDate('reported_on', $day)->where('active', true)->lockForUpdate()->first();

            if (! $existing) {
                if (! isset($sections['guet'])) {
                    throw MandateRefusal::make('guet', 'security.api.report_guet_required');
                }
                $mode = 'created';
                $merged = $sections;
            } else {
                $current = $existing->payload['sections'] ?? [];
                $mine = $existing->character_id !== null && $existing->character_id === $actor->id;

                if ($access['correct_reports']) {
                    $mode = 'corrected';
                    $merged = array_replace($current, $sections);
                } elseif ($mine && $day === $today) {
                    // L'AUTEUR corrige le sien le jour du jeu même (Greg, 24/11/2026 : ses groupes avaient changé après sa première saisie) : il complète les
                    // sections manquantes ET remplace les siennes ; l'ancienne version reste en archive (ajout seul). Le lendemain, plus personne sauf le trio.
                    $mode = array_intersect_key($sections, $current) === [] ? 'completed' : 'corrected';
                    $merged = array_replace($current, $sections);
                } else {
                    throw MandateRefusal::make('city', 'security.api.report_exists');
                }

                // Une section retirée l'est du bilan EN VIGUEUR ; l'ancienne version, qui la contient, reste en archive (ajout seul).
                $removed = array_values(array_intersect($remove, array_keys($merged)));
                foreach ($removed as $key) {
                    unset($merged[$key]);
                }
                if ($removed !== []) {
                    $mode = 'corrected';
                }
                if ($merged === $current && $sections === []) {
                    throw MandateRefusal::make('sections', 'security.api.report_nothing_to_change');
                }

                $existing->update(['active' => null]);
            }

            $report = CustomsReport::create([
                'city_id' => $city->id,
                'reported_on' => $day,
                'character_id' => $actor->id,
                'author_role' => $access['role'],
                'payload' => ['sections' => $merged],
            ]);
            $existing?->update(['replaced_by_id' => $report->id, 'replaced_at' => now()]);

            return ['report' => $report, 'replaced' => $existing, 'mode' => $mode];
        });

        // Seule la saisie d'un bilan remet le compteur d'inactivité du douanier à zéro (brief §4.2).
        if ($access['customs']) {
            $this->officers->recordReport($access['customs']);
        }

        return $result;
    }

    /** Jours du jeu qui ont un bilan en vigueur pour cette ville, du plus récent au plus ancien. */
    public function days(City $city, int $limit = 90): array
    {
        return CustomsReport::query()->where('city_id', $city->id)->where('active', true)->orderByDesc('reported_on')->limit($limit)
            ->pluck('reported_on')->map(fn ($day) => $day->toDateString())->all();
    }

    public function current(City $city, string $day): ?CustomsReport
    {
        return CustomsReport::query()->where('city_id', $city->id)->whereDate('reported_on', $day)->where('active', true)->first();
    }

    /**
     * Le bilan tel qu'on le LIT : jamais le brut, jamais l'auteur (`by_me` seulement), couleurs à la date du bilan, différentiel du guet.
     *
     * @param  array<string, mixed>  $access  CustomsAccess::for()
     * @return array<string, mixed>
     */
    public function present(CustomsReport $report, Character $viewer, array $access): array
    {
        $day = $report->reported_on->toDateString();
        $colours = $this->watchlist->colourMap($access['province'], $day);
        $sections = $report->payload['sections'] ?? [];
        $out = [];

        if (isset($sections['guet'])) {
            $guet = $sections['guet']['report'];
            $villagers = collect($guet['villagers'] ?? []);
            $out['guet'] = [
                'villagers' => $villagers->map(fn ($v) => $v + ['colour' => $colours[CustomsPseudo::key($v['pseudo'])] ?? null])->all(),
                'declared_total' => $guet['declared_total'] ?? null,
                'read_total' => $villagers->count(),
                'total_matches' => ($guet['declared_total'] ?? null) === null ? null : $guet['declared_total'] === $villagers->count(),
                'diff' => $this->diff($report, $villagers->pluck('pseudo')->all(), $colours),
            ];
        }

        if (isset($sections['visions'])) {
            $visions = $sections['visions']['report'];
            $out['visions'] = [
                'entries' => collect($visions['entries'] ?? [])->map(fn ($entry) => [
                    'singles' => $this->coloured($entry['singles'] ?? [], $colours),
                    'groups' => collect($entry['groups'] ?? [])->map(fn ($group) => $this->coloured($group, $colours))->all(),
                ])->all(),
                'other_days' => $visions['other_days'] ?? 0,
            ];
        }

        if (isset($sections['armies_groups'])) {
            $block = $sections['armies_groups']['report'];
            $out['armies_groups'] = [
                'armies' => collect($block['armies'] ?? [])->map(fn ($army) => [
                    'name' => $army['name'],
                    'commander' => ['pseudo' => $army['commander'], 'colour' => $colours[CustomsPseudo::key($army['commander'])] ?? null],
                    'status' => $army['status'] ?? '',
                ])->all(),
                'groups' => collect($block['groups'] ?? [])->map(fn ($group) => [
                    'type' => $group['type'],
                    // Seul « Groupe de PSEUDO » alerte : « groupe à surveiller », jamais « brigand » (P8).
                    'alert' => $group['type'] === 'groupe',
                    'members' => $this->coloured($group['members'] ?? [], $colours),
                    'status' => $group['status'] ?? '',
                ])->all(),
                'zone' => $block['zone'] ?? null,
            ];
        }

        return [
            'id' => $report->id,
            'city_id' => $report->city_id,
            'reported_on' => $day,
            'sections' => $out,
            'sections_present' => array_keys($sections),
            'by_me' => $report->character_id !== null && $report->character_id === $viewer->id,
            'can_complete' => $access['write_reports'] && $report->character_id === $viewer->id && $day === MandateCalendar::today(),
            // Le trio corrige tout ; l'auteur corrige le sien le jour du jeu même (même condition que `can_complete`).
            'can_correct' => (bool) $access['correct_reports'] || ($access['write_reports'] && $report->character_id === $viewer->id && $day === MandateCalendar::today()),
        ];
    }

    /** Le DERNIER bilan en vigueur de la ville, strictement antérieur au jour, qui a un guet (P7). */
    private function previousGuetReport(int $cityId, string $day): ?CustomsReport
    {
        return CustomsReport::query()->where('city_id', $cityId)->where('active', true)
            ->whereDate('reported_on', '<', $day)->orderByDesc('reported_on')->get()
            ->first(fn (CustomsReport $candidate) => isset($candidate->payload['sections']['guet']));
    }

    /**
     * Relevé unique (PR 3-2, fil module-securite 12 à 14) : ce que le Relevé du guet demande AVANT d'enregistrer quoi que ce soit — la couleur des
     * pseudos collés et le guet du dernier bilan de la ville. 🔴 **Rien n'est écrit ni journalisé** : seuls les pseudos arrivent, ils ne sont pas
     * gardés, et ne sont servis que ceux envoyés (jamais la liste elle-même). Les couleurs suivent le droit de LIRE les listes de la province
     * (Q4), le guet précédent le droit de LIRE les bilans de la ville choisie ; un simple joueur avec compte n'a ni l'un ni l'autre.
     *
     * @param  array<int, string>  $pseudos
     * @return array{today: string, colours: ?array<int, array{pseudo: string, colour: string}>, previous: ?array{compared_to: string, pseudos: array<int, string>}}
     *
     * @throws MandateRefusal
     */
    public function preview(Character $viewer, array $pseudos, ?City $city = null): array
    {
        $access = $this->access->for($viewer);
        if (! $access) {
            throw MandateRefusal::make('character', 'security.api.no_security_role');
        }

        $today = MandateCalendar::today();

        $colours = null;
        if ($access['read_lists']) {
            $map = $this->watchlist->colourMap($access['province'], $today);
            $colours = [];
            foreach (array_values(array_unique($pseudos)) as $pseudo) {
                $colour = $map[CustomsPseudo::key($pseudo)] ?? null;
                if ($colour !== null) {
                    $colours[] = ['pseudo' => $pseudo, 'colour' => $colour];
                }
            }
        }

        $previous = null;
        if ($city !== null) {
            if (! $this->access->canReadIn($access, $city)) {
                throw MandateRefusal::make('city_id', 'security.api.reports_not_readable');
            }
            $last = $this->previousGuetReport($city->id, $today);
            if ($last) {
                $previous = [
                    'compared_to' => $last->reported_on->toDateString(),
                    'pseudos' => collect($last->payload['sections']['guet']['report']['villagers'] ?? [])->pluck('pseudo')->values()->all(),
                ];
            }
        }

        return ['today' => $today, 'colours' => $colours, 'previous' => $previous];
    }

    /**
     * Arrivées et départs par rapport au DERNIER bilan antérieur de la ville qui a un guet (P7) : on dit lequel, jamais de différentiel inventé.
     *
     * @param  array<int, string>  $today
     * @param  array<string, string>  $colours
     * @return array{compared_to: string, arrivals: array, departures: array}|null
     */
    private function diff(CustomsReport $report, array $today, array $colours): ?array
    {
        $previous = $this->previousGuetReport($report->city_id, $report->reported_on->toDateString());
        if (! $previous) {
            return null;
        }

        $before = collect($previous->payload['sections']['guet']['report']['villagers'] ?? [])->pluck('pseudo')->all();
        $beforeKeys = array_map(CustomsPseudo::key(...), $before);
        $todayKeys = array_map(CustomsPseudo::key(...), $today);

        return [
            'compared_to' => $previous->reported_on->toDateString(),
            'arrivals' => $this->coloured(array_values(array_filter($today, fn ($p) => ! in_array(CustomsPseudo::key($p), $beforeKeys, true))), $colours),
            'departures' => $this->coloured(array_values(array_filter($before, fn ($p) => ! in_array(CustomsPseudo::key($p), $todayKeys, true))), $colours),
        ];
    }

    /**
     * @param  array<int, string>  $pseudos
     * @param  array<string, string>  $colours
     * @return array<int, array{pseudo: string, colour: ?string}>
     */
    private function coloured(array $pseudos, array $colours): array
    {
        return array_map(fn (string $pseudo) => ['pseudo' => $pseudo, 'colour' => $colours[CustomsPseudo::key($pseudo)] ?? null], $pseudos);
    }
}
