<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InventoryAccessDenied;
use App\Exceptions\InventoryCopyNeedsConfirmation;
use App\Exceptions\InventoryVersionConflict;
use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Services\InventorySettings;
use App\Services\InventoryText;
use App\Services\ProvinceInventoryRegistry;
use App\Support\MandateApiErrors;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inventaires — réglages et texte collé, aux trois niveaux (personnage, ville, province). Ne valide que la FORME de la
 * requête : l'autorisation (par le poste tenu MAINTENANT), la version, le document et le chiffrement vivent dans
 * App\Services\InventorySettings, InventoryText et ProvinceInventoryRegistry. Erreurs au contrat de MandateApiErrors
 * (code + messages {fr, en}). ⚠️ Routes ajoutées : route:cache en prod.
 */
class InventorySettingsController extends Controller
{
    public function __construct(
        private readonly InventorySettings $settings,
        private readonly InventoryText $text,
        private readonly ProvinceInventoryRegistry $registry,
    ) {}

    /** Ce que le personnage peut gérer MAINTENANT : son inventaire, la ville dont il est maire, la province dont il est commissaire au commerce ou dirigeant. */
    public function scopes(Request $request, int $character): JsonResponse
    {
        return $this->withCharacter($request, $character, fn (Character $c) => response()->json(['success' => true] + $this->settings->scopes($c)));
    }

    public function show(Request $request, int $character, string $level): JsonResponse
    {
        return $this->withCharacter($request, $character, fn (Character $c) => response()->json(['success' => true, 'settings' => $this->settings->show($c, $level)]));
    }

    public function update(Request $request, int $character, string $level): JsonResponse
    {
        $input = $request->validate(['data' => ['required', 'array'], 'version' => ['required', 'integer', 'min:0']]);

        return $this->withCharacter($request, $character, fn (Character $c) => response()->json([
            'success' => true,
            'settings' => $this->settings->save($c, $level, $input['data'], (int) $input['version']),
        ]));
    }

    /** Copie les réglages personnels d'un autre personnage du compte vers celui-ci. */
    public function copy(Request $request, int $character): JsonResponse
    {
        $input = $request->validate(['from_character_id' => ['required', 'integer'], 'confirm_replace' => ['sometimes', 'boolean']]);

        return $this->withCharacter($request, $character, function (Character $target) use ($request, $input) {
            $source = $request->user()->characters()->find($input['from_character_id']);
            if (! $source) {
                return MandateApiErrors::error(404, 'inventory.api.copy_source_not_found');
            }

            return response()->json([
                'success' => true,
                'settings' => $this->settings->copyPersonal($target, $source, (bool) ($input['confirm_replace'] ?? false)),
            ]);
        });
    }

    /** Le dernier inventaire collé (personnel ou ville), déchiffré. */
    public function textShow(Request $request, int $character, string $level): JsonResponse
    {
        return $this->withCharacter($request, $character, fn (Character $c) => response()->json(['success' => true, 'inventory' => $this->text->get($c, $level)]));
    }

    /** Remplace le dernier inventaire collé (personnel ou ville). */
    public function textPut(Request $request, int $character, string $level): JsonResponse
    {
        $input = $request->validate(['raw' => ['required', 'string']]);

        return $this->withCharacter($request, $character, fn (Character $c) => response()->json(['success' => true, 'inventory' => $this->text->put($c, $level, $input['raw'])]));
    }

    /** L'historique des inventaires de la province (commissaire au commerce, dirigeant). */
    public function provinceHistory(Request $request, int $character): JsonResponse
    {
        return $this->withCharacter($request, $character, fn (Character $c) => response()->json(['success' => true] + $this->registry->history($c)));
    }

    /** Inscrit l'inventaire du jour dans l'historique de la province (commissaire au commerce). */
    public function provinceRecord(Request $request, int $character): JsonResponse
    {
        $input = $request->validate(['raw' => ['required', 'string']]);

        return $this->withCharacter($request, $character, function (Character $c) use ($input) {
            $result = $this->registry->record($c, $input['raw']);

            return response()->json([
                'success' => true,
                'snapshot' => ['id' => $result['snapshot']->id, 'taken_on' => $result['snapshot']->taken_on->toDateString()],
                'replaced' => $result['replaced'],
                'unchanged' => $result['unchanged'],
            ], $result['unchanged'] || $result['replaced'] ? 200 : 201);
        });
    }

    /** Le personnage est celui du COMPTE (404 sinon), puis les trois refus propres aux inventaires deviennent des réponses. */
    private function withCharacter(Request $request, int $character, Closure $then): JsonResponse
    {
        $model = $request->user()->characters()->find($character);
        if (! $model) {
            return MandateApiErrors::error(404, 'mandates.api.character_not_found');
        }

        try {
            return $then($model);
        } catch (InventoryAccessDenied $denied) {
            return MandateApiErrors::error(403, $denied->key);
        } catch (InventoryVersionConflict $conflict) {
            $response = MandateApiErrors::error(409, 'inventory.api.version_conflict');

            return response()->json($response->getData(true) + ['current' => $conflict->current], 409);
        } catch (InventoryCopyNeedsConfirmation $pending) {
            return MandateApiErrors::error(409, 'inventory.api.copy_needs_confirmation', [
                'target' => $pending->targetPseudo,
                'source' => $pending->sourcePseudo,
            ]);
        }
    }
}
