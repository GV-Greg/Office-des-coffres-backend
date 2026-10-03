<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Models\CouncilMandate;
use App\Models\CouncilOffice;
use App\Models\Mandate;
use App\Models\MayorMandate;
use App\Services\MandateWorkflow;
use App\Support\MandateApiErrors;
use App\Support\MandateLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API joueur des mandats (lot 1). Toujours les personnages du porteur du jeton : un personnage ou
 * un mandat d'un autre compte répond 404, jamais 403 — on ne confirme pas son existence. Les règles
 * métier vivent dans MandateWorkflow ; ce contrôleur ne valide que la forme.
 */
class MandateController extends BaseController
{
    public function __construct(private readonly MandateWorkflow $workflow) {}

    /**
     * Référentiel des 10 postes titrés, avec leurs libellés FR/EN relevés en jeu (Q11 : le backend
     * les a déjà pour les emails, il les sert — une seule source, y compris pour les modules futurs).
     * Public : aucune donnée de compte.
     */
    public function offices(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'offices' => CouncilOffice::orderBy('position')->get(['key', 'position'])
                ->map(fn (CouncilOffice $office) => [
                    'key' => $office->key,
                    'position' => $office->position,
                    'label' => self::labels(fn (string $locale) => MandateLabels::office($office->key, $locale)),
                ]),
        ])->header('Cache-Control', 'public, max-age=3600');
    }

    /** Mandats et demandes de tous les personnages du compte, statut EFFECTIF calculé. */
    public function index(Request $request): JsonResponse
    {
        $characterIds = $request->user()->characters()->pluck('id');

        $mandates = collect()
            ->concat(MayorMandate::whereIn('character_id', $characterIds)->with(['character', 'city'])->get())
            ->concat(CouncilMandate::whereIn('character_id', $characterIds)
                ->with(['character', 'province', 'pendingOffice', 'officePeriods.office'])->get())
            ->sortByDesc('created_at')
            ->values()
            ->map(fn (Mandate $mandate) => $this->payload($mandate));

        // Par personnage et par niveau : peut-il demander un poste, et sinon pourquoi (Q6).
        $characters = $request->user()->characters()->orderBy('id')->get()->map(fn ($character) => [
            'id' => $character->id,
            'pseudo' => $character->pseudo,
            'is_validated' => $character->is_validated,
            'requestable' => [
                'mayor' => $this->workflow->requestability($character, 'mayor'),
                'council' => $this->workflow->requestability($character, 'council'),
            ],
        ]);

        return response()->json(['success' => true, 'mandates' => $mandates, 'characters' => $characters]);
    }

    public function store(Request $request, int $character): JsonResponse
    {
        $character = $request->user()->characters()->find($character);
        if (! $character) {
            return MandateApiErrors::error(404, 'mandates.api.character_not_found');
        }

        $validated = $request->validate([
            'level' => ['required', Rule::in(['mayor', 'council'])],
            'city_id' => ['required_if:level,mayor', 'prohibited_unless:level,mayor', 'nullable', 'integer', 'exists:rk_cities,id'],
            'province_id' => ['required_if:level,council', 'prohibited_unless:level,council', 'nullable', 'integer', 'exists:rk_provinces,id'],
            'council_office_key' => ['prohibited_unless:level,council', 'nullable', 'string', 'exists:council_offices,key'],
            'started_at' => ['required', 'date_format:Y-m-d'],
            'announcement_url' => ['required', 'url', 'starts_with:https://', 'max:500'],
        ]);

        $mandate = $this->workflow->request($character, $validated['level'], $validated);

        return response()->json(['success' => true, 'mandate' => $this->payload($mandate->fresh())], 201);
    }

    public function renew(Request $request, string $level, int $id): JsonResponse
    {
        $mandate = $this->workflow->findForUser($level, $id, $request->user()->id);
        if (! $mandate) {
            return MandateApiErrors::error(404, 'mandates.api.not_found');
        }

        $validated = $request->validate([
            'started_at' => ['required', 'date_format:Y-m-d'],
            'announcement_url' => ['required', 'url', 'starts_with:https://', 'max:500'],
        ]);

        $renewal = $this->workflow->renew($mandate, $validated['started_at'], $validated['announcement_url']);

        return response()->json(['success' => true, 'mandate' => $this->payload($renewal->fresh())], 201);
    }

    public function destroy(Request $request, string $level, int $id): JsonResponse
    {
        $mandate = $this->workflow->findForUser($level, $id, $request->user()->id);
        if (! $mandate) {
            return MandateApiErrors::error(404, 'mandates.api.not_found');
        }

        $this->workflow->cancel($mandate);

        return response()->json(['success' => true]);
    }

    /** « Déclarer mon poste » : council_office_key nul = devenir sans poste. */
    public function declareOffice(Request $request, int $id): JsonResponse
    {
        $mandate = $this->workflow->findForUser('council', $id, $request->user()->id);
        if (! $mandate instanceof CouncilMandate) {
            return MandateApiErrors::error(404, 'mandates.api.not_found');
        }

        $validated = $request->validate([
            'council_office_key' => ['present', 'nullable', 'string', 'exists:council_offices,key'],
        ]);

        $mandate = $this->workflow->declareOffice($mandate, $validated['council_office_key']);

        return response()->json(['success' => true, 'mandate' => $this->payload($mandate)]);
    }

    /**
     * Libellé dans les deux langues : aucune langue n'est stockée sur le compte (Q9, Q11).
     *
     * @return array{fr: string, en: string}
     */
    private static function labels(callable $label): array
    {
        return ['fr' => $label('fr'), 'en' => $label('en')];
    }

    /**
     * Ce que le joueur voit de ses mandats. JAMAIS la note interne. Les dates sont en ISO 8601
     * UTC (instants) ou Y-m-d (dates civiles déclarées).
     *
     * @return array<string, mixed>
     */
    private function payload(Mandate $mandate): array
    {
        $isCouncil = $mandate instanceof CouncilMandate;
        $place = $mandate->place;

        return [
            'id' => $mandate->id,
            'level' => $mandate::LEVEL,
            'character' => ['id' => $mandate->character_id, 'pseudo' => $mandate->character?->pseudo],
            'status' => $mandate->effectiveStatus(),
            'place' => [
                'id' => $place?->id,
                'name' => $isCouncil ? $place?->province_name : $place?->city_name,
            ],
            'office_key' => $officeKey = ($isCouncil ? $mandate->currentOfficeKey() : null),
            'office_label' => $isCouncil ? self::labels(fn (string $l) => MandateLabels::office($officeKey, $l)) : null,
            'office_change_pending' => $isCouncil && $mandate->status === Mandate::APPROVED && $mandate->hasOfficeRequest(),
            'pending_office_key' => $pendingKey = ($isCouncil && $mandate->hasOfficeRequest() ? $mandate->pendingOffice?->key : null),
            'pending_office_label' => $isCouncil && $mandate->hasOfficeRequest()
                ? self::labels(fn (string $l) => MandateLabels::office($pendingKey, $l)) : null,
            'office_history' => $isCouncil ? $mandate->officePeriods->sortBy('started_at')->values()->map(fn ($period) => [
                'office_key' => $period->office?->key,
                'office_label' => self::labels(fn (string $l) => MandateLabels::office($period->office?->key, $l)),
                'started_at' => $period->started_at?->toIso8601String(),
                'ended_at' => $period->ended_at?->toIso8601String(),
                'end_reason' => $period->end_reason,
                'end_reason_label' => self::labels(fn (string $l) => MandateLabels::reason($period->end_reason, $l)),
            ]) : [],
            'declared_started_at' => $mandate->declared_started_at?->format('Y-m-d'),
            'started_at' => $mandate->started_at?->format('Y-m-d'),
            'valid_until' => $mandate->valid_until?->toIso8601String(),
            'in_office_from' => $mandate->in_office_from?->toIso8601String(),
            'holds_until' => $mandate->holds_until?->toIso8601String(),
            'announcement_url' => $mandate->announcement_url,
            'renews_id' => $mandate->renews_id,
            'renewable' => $this->workflow->isRenewable($mandate),
            'decision_reason' => $mandate->decision_reason,
            'decision_reason_label' => $mandate->decision_reason
                ? self::labels(fn (string $l) => MandateLabels::reason($mandate->decision_reason, $l)) : null,
            'decision_message' => $mandate->decision_message,
            'decision_message_locale' => $mandate->decision_message_locale,
        ];
    }
}
