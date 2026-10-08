<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Province;
use App\Services\MandateAuthority;
use App\Services\MineRegistry;
use App\Services\MineReplacementNeedsConfirmation;
use App\Support\MandateApiErrors;
use App\Support\MandateLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registre des mines — écriture (PR 1b). Ne valide que la FORME : toutes les règles (autorisation,
 * province du poste, ajout seul, R3 bis) vivent dans App\Services\MineRegistry. Erreurs au contrat
 * de MandateApiErrors (code + messages {fr, en}).
 */
class MineReportController extends Controller
{
    public function __construct(
        private readonly MineRegistry $registry,
        private readonly MandateAuthority $authority,
    ) {}

    /**
     * Ce que le personnage peut faire du Registre MAINTENANT, et dans quelle province — l'écran l'affiche
     * AVANT tout envoi (brief §2 : « l'écran dit dans quelle province il écrit »). La règle reste ici,
     * le frontend ne la recalcule pas. Lecture et écriture peuvent différer : le dirigeant consulte.
     */
    public function access(Request $request, int $character): JsonResponse
    {
        $character = $request->user()->characters()->find($character);
        if (! $character) {
            return MandateApiErrors::error(404, 'mandates.api.character_not_found');
        }

        $province = fn (?Province $p) => $p ? ['id' => $p->id, 'name' => $p->province_name] : null;

        return response()->json([
            'success' => true,
            'write' => $province($this->authority->canManageMines($character)),
            'read' => $province($this->authority->canReadMines($character)),
        ]);
    }

    public function store(Request $request, int $character): JsonResponse
    {
        $character = $request->user()->characters()->find($character);
        if (! $character) {
            return MandateApiErrors::error(404, 'mandates.api.character_not_found');
        }

        $validated = $request->validate([
            'raw' => ['required', 'string', 'max:200000'],
            'report' => ['required', 'array'],
            'report.mines' => ['required', 'array', 'min:1', 'max:50'],
            'report.mines.*.number' => ['required', 'integer', 'min:1'],
            'report.mines.*.noeud' => ['nullable', 'string', 'max:20'],
            'report.mines.*.days' => ['present', 'array'],
            'report.states' => ['nullable', 'array', 'max:50'],
            'prices' => ['nullable', 'array'],
            'prices.*' => ['nullable', 'numeric', 'min:0'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'confirm_replace' => ['sometimes', 'boolean'],
        ]);

        try {
            ['report' => $report, 'replaced' => $replaced] = $this->registry->record(
                $character,
                $validated,
                (bool) ($validated['confirm_replace'] ?? false),
            );
        } catch (MineReplacementNeedsConfirmation $pending) {
            $response = MandateApiErrors::error(409, 'mines.api.mine_report_needs_confirmation', [
                'date' => $pending->existing->reported_at->format('d/m/Y'),
                'province' => $pending->province->province_name,
            ]);

            return response()->json($response->getData(true) + ['existing' => $this->author($pending->existing)], 409);
        }

        return response()->json([
            'success' => true,
            'report' => [
                'id' => $report->id,
                'province_id' => $report->province_id,
                'reported_at' => $report->reported_at->toDateString(),
                'office_key' => $report->office_key,
                'province_name' => $report->province?->province_name,
            ],
            'replaced' => $replaced ? $this->author($replaced) : null,
        ], 201);
    }

    /** Qui a écrit le relevé remplacé — nommé à l'écran (R3 bis). NULL une fois le personnage supprimé. */
    private function author($report): array
    {
        return [
            'id' => $report->id,
            'pseudo' => $report->character?->pseudo,
            'office_key' => $report->office_key,
            'office_label' => ['fr' => MandateLabels::office($report->office_key, 'fr'), 'en' => MandateLabels::office($report->office_key, 'en')],
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }
}
