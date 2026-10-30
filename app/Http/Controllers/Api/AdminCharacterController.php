<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Models\Character;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Actions admin sur les personnages, exposées via l'API (pas via Blade).
 * Middleware role:admin appliqué dans routes/api.php.
 */
class AdminCharacterController extends BaseController
{
    /**
     * Correction du pseudo d'un personnage par l'admin.
     * Réservé aux cas d'erreur à l'inscription, sur demande du joueur.
     */
    public function updatePseudo(Request $request, Character $character): JsonResponse
    {
        $validated = $request->validate([
            'pseudo' => ['required', 'string', 'min:2', 'max:50',
                         'unique:characters,pseudo,' . $character->id],
        ]);

        $character->update(['pseudo' => $validated['pseudo']]);

        return response()->json([
            'success'   => true,
            'character' => [
                'id'     => $character->id,
                'pseudo' => $character->fresh()->pseudo,
            ],
        ]);
    }
}
