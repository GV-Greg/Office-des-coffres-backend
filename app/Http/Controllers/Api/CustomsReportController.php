<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\CustomsAccess;
use App\Services\CustomsProvinceOverview;
use App\Services\CustomsReports;
use App\Support\MandateApiErrors;
use App\Support\MandateCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API des bilans de défense (Registre de douane, module Sécurité). Ne valide que la FORME : les droits vivent dans App\Services\CustomsAccess,
 * les règles dans App\Services\CustomsReports. Le frontend envoie le JSON analysé ET le brut ; le serveur ne re-parse jamais (décision de Greg,
 * 22/11/2026, comme les mines). Un personnage d'un autre compte répond 404, jamais 403.
 */
class CustomsReportController extends Controller
{
    private const PSEUDO = ['required', 'string', 'regex:/^\S{1,40}$/u'];

    public function __construct(
        private readonly CustomsAccess $access,
        private readonly CustomsReports $reports,
        private readonly CustomsProvinceOverview $overview,
    ) {}

    /**
     * Ce que le personnage peut faire MAINTENANT et où : l'écran l'affiche AVANT tout envoi. Dit aussi le jour du jeu (`today`) du bilan que le
     * client doit filtrer (les visions de l'en-tête J, C1/C2) et `has_security_trio` — « aucun membre de la sécurité n'est connu de l'Office
     * pour votre province » est l'état COURANT d'une province.
     */
    public function access(Request $request, int $character): JsonResponse
    {
        $own = $request->user()->characters()->find($character);
        if (! $own) {
            return MandateApiErrors::error(404, 'mandates.api.character_not_found');
        }

        $access = $this->access->for($own);
        if (! $access) {
            return response()->json(['success' => true, 'access' => null]);
        }

        $city = fn (City $c) => ['id' => $c->id, 'name' => $c->city_name];

        return response()->json(['success' => true, 'access' => [
            'province' => ['id' => $access['province']->id, 'name' => $access['province']->province_name],
            'role' => $access['role'],
            'has_security_trio' => $access['trio_known'],
            'today' => MandateCalendar::today(),
            'earliest_day' => $this->access->earliestDay($access),
            'read_reports' => $access['read_reports'],
            'write_reports' => $access['write_reports'],
            'correct_reports' => $access['correct_reports'],
            'read_lists' => $access['read_lists'],
            'write_lists' => $access['write_lists'],
            'add_red_only' => $access['add_red_only'],
            'writable_cities' => $this->access->writableCities($access)->map($city)->values(),
            'readable_cities' => $this->access->readableCities($access)->map($city)->values(),
            'min_parser_version' => CustomsReports::MIN_PARSER_VERSION,
        ]]);
    }

    /**
     * Vue d'ensemble de la province (PR 5, maquette validée le 23/11/2026) : couverture par ville et, fusionnés, les pseudos marqués, les groupes à surveiller et
     * les armées, chaque ligne datée de son jour de bilan. LECTURE SEULE, aucun droit nouveau : seules les villes que le rôle lit déjà y figurent, et la vue
     * n'a de sens qu'à partir de DEUX villes lisibles (un maire simple a le Registre pour sa ville) — sinon 403, comme un rôle qui ne lit rien.
     */
    public function province(Request $request, int $character): JsonResponse
    {
        $own = $request->user()->characters()->find($character);
        if (! $own) {
            return MandateApiErrors::error(404, 'mandates.api.character_not_found');
        }

        $access = $this->access->for($own);
        if (! $access || $this->access->readableCities($access)->count() < 2) {
            return MandateApiErrors::error(403, 'security.api.province_not_readable');
        }

        // `date` facultative : la situation AU jour D (jamais le futur, ramené à aujourd'hui) ; absente = aujourd'hui.
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['success' => true] + $this->overview->build($own, $access, $data['date'] ?? null));
    }

    /** Les jours qui ont un bilan pour la ville et, si `date` est donnée, le bilan de ce jour. */
    public function index(Request $request, int $character): JsonResponse
    {
        $own = $request->user()->characters()->find($character);
        if (! $own) {
            return MandateApiErrors::error(404, 'mandates.api.character_not_found');
        }

        $data = $request->validate(['city_id' => ['required', 'integer', 'exists:rk_cities,id'], 'date' => ['nullable', 'date_format:Y-m-d']]);
        $city = City::findOrFail($data['city_id']);
        $access = $this->access->for($own);
        if (! $access || ! $this->access->canReadIn($access, $city)) {
            return MandateApiErrors::error(403, 'security.api.reports_not_readable');
        }

        $report = isset($data['date']) ? $this->reports->current($city, $data['date']) : null;

        return response()->json([
            'success' => true,
            'days' => $this->reports->days($city),
            'report' => $report ? $this->reports->present($report, $own, $access) : null,
        ]);
    }

    /**
     * Couleurs des pseudos collés et dernier guet de la ville, SANS rien enregistrer (Relevé unique). Forme seulement ici ; les droits vivent dans
     * `CustomsReports::preview`. Plafonné : au plus 2000 pseudos, chacun sans espace (comme à l'enregistrement).
     */
    public function preview(Request $request, int $character): JsonResponse
    {
        $own = $request->user()->characters()->find($character);
        if (! $own) {
            return MandateApiErrors::error(404, 'mandates.api.character_not_found');
        }

        $data = $request->validate([
            'city_id' => ['nullable', 'integer', 'exists:rk_cities,id'],
            'pseudos' => ['present', 'array', 'max:2000'],
            'pseudos.*' => self::PSEUDO,
        ]);

        $result = $this->reports->preview($own, $data['pseudos'], isset($data['city_id']) ? City::findOrFail($data['city_id']) : null);

        return response()->json(['success' => true] + $result);
    }

    public function store(Request $request, int $character): JsonResponse
    {
        $own = $request->user()->characters()->find($character);
        if (! $own) {
            return MandateApiErrors::error(404, 'mandates.api.character_not_found');
        }

        $data = $request->validate($this->rules());
        $city = City::findOrFail($data['city_id']);

        ['report' => $report, 'replaced' => $replaced, 'mode' => $mode] = $this->reports->record($own, $city, $data['sections'] ?? [], $data['date'] ?? null, $data['remove_sections'] ?? []);

        return response()->json([
            'success' => true,
            'mode' => $mode,
            'report' => ['id' => $report->id, 'city_id' => $report->city_id, 'reported_on' => $report->reported_on->toDateString()],
            'replaced' => $replaced !== null,
        ], 201);
    }

    /**
     * Règles de FORME. Les champs d'une section ne sont obligatoires QUE si la section est envoyée (`required_with` / `present_with`) : un
     * premier envoi peut ne porter que le guet. Plafonds serrés : le serveur ne se fie à rien d'autre que la forme.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        $with = fn (string $section) => 'required_with:sections.'.$section;
        $present = fn (string $section) => 'present_with:sections.'.$section;
        $text = fn (int $max) => ['nullable', 'string', 'max:'.$max];
        $common = fn (string $section) => [
            "sections.$section" => ['sometimes', 'array'],
            "sections.$section.raw" => [$with($section), 'string', 'max:100000'],
            "sections.$section.parser_version" => [$with($section), 'integer', 'min:1', 'max:1000'],
            "sections.$section.report" => [$with($section), 'array'],
        ];

        return [
            'city_id' => ['required', 'integer', 'exists:rk_cities,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            // Au moins une section à inscrire OU une section à retirer (Greg, 24/11/2026 : une case vidée retire la section du relevé existant).
            'sections' => ['required_without:remove_sections', 'array'],
            'remove_sections' => ['sometimes', 'array', 'max:2'],
            'remove_sections.*' => ['string', 'in:visions,armies_groups'],

            ...$common('guet'),
            'sections.guet.report.villagers' => [$with('guet'), 'array', 'min:1', 'max:1000'],
            'sections.guet.report.villagers.*.pseudo' => self::PSEUDO,
            'sections.guet.report.villagers.*.noblesse' => $text(300),
            'sections.guet.report.villagers.*.reputation' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'sections.guet.report.declared_total' => ['nullable', 'integer', 'min:0', 'max:100000'],

            ...$common('visions'),
            'sections.visions.report.entries' => [$present('visions'), 'array', 'max:500'],
            'sections.visions.report.entries.*.singles' => ['present', 'array', 'max:100'],
            'sections.visions.report.entries.*.singles.*' => self::PSEUDO,
            'sections.visions.report.entries.*.groups' => ['present', 'array', 'max:100'],
            'sections.visions.report.entries.*.groups.*' => ['array', 'max:100'],
            'sections.visions.report.entries.*.groups.*.*' => self::PSEUDO,
            'sections.visions.report.other_days' => ['nullable', 'integer', 'min:0', 'max:1000'],

            ...$common('armies_groups'),
            'sections.armies_groups.report.armies' => [$present('armies_groups'), 'array', 'max:100'],
            'sections.armies_groups.report.armies.*.name' => ['required', 'string', 'max:200'],
            'sections.armies_groups.report.armies.*.commander' => self::PSEUDO,
            'sections.armies_groups.report.armies.*.status' => $text(2000),
            'sections.armies_groups.report.groups' => [$present('armies_groups'), 'array', 'max:300'],
            'sections.armies_groups.report.groups.*.type' => ['required', 'string', 'in:lance,corps_armes,marechaux,groupe,milice'],
            'sections.armies_groups.report.groups.*.members' => ['present', 'array', 'max:100'],
            'sections.armies_groups.report.groups.*.members.*' => self::PSEUDO,
            'sections.armies_groups.report.groups.*.status' => $text(2000),
            'sections.armies_groups.report.zone' => $text(5000),
        ];
    }
}
