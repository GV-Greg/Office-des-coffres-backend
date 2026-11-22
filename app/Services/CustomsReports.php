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
 *   saisit pas un second (Q5). Le MÊME auteur COMPLÈTE les sections manquantes le même jour ; remplacer une section déjà saisie, ou saisir un
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

    /** Version minimale du parseur du frontend acceptée : un client plus ancien est invité à recharger. */
    public const MIN_PARSER_VERSION = 1;

    public function __construct(
        private readonly CustomsAccess $access,
        private readonly CustomsWatchlist $watchlist,
        private readonly CustomsOfficers $officers,
    ) {}

    /**
     * @param  array<string, array{raw: string, parser_version: int, report: array}>  $sections  sections VALIDÉES en forme (guet, visions, armies_groups)
     * @return array{report: CustomsReport, replaced: ?CustomsReport, mode: string}
     *
     * @throws MandateRefusal
     */
    public function record(Character $actor, City $city, array $sections, ?string $date = null): array
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
        foreach ($sections as $section) {
            if (($section['parser_version'] ?? 0) < self::MIN_PARSER_VERSION) {
                throw MandateRefusal::make('sections', 'security.api.parser_outdated');
            }
        }

        $result = DB::transaction(function () use ($actor, $city, $sections, $day, $today, $access) {
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
                    foreach (array_keys($sections) as $key) {
                        if (isset($current[$key])) {
                            throw MandateRefusal::make($key, 'security.api.report_section_exists');
                        }
                    }
                    $mode = 'completed';
                    $merged = $current + $sections;
                } else {
                    throw MandateRefusal::make('city', 'security.api.report_exists');
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
            'can_correct' => (bool) $access['correct_reports'],
        ];
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
        $previous = CustomsReport::query()->where('city_id', $report->city_id)->where('active', true)
            ->whereDate('reported_on', '<', $report->reported_on->toDateString())->orderByDesc('reported_on')->get()
            ->first(fn (CustomsReport $candidate) => isset($candidate->payload['sections']['guet']));
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
