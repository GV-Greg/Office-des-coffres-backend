<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomsWatchlistEntry;
use App\Services\CustomsWatchlist;
use App\Support\MandateApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API des listes rouge et verte d'une province (Registre de douane, module Sécurité). Ne valide que la FORME : les droits vivent dans
 * App\Services\CustomsAccess, les règles dans App\Services\CustomsWatchlist. Un retrait SUPPRIME l'entrée.
 */
class CustomsListController extends Controller
{
    public function __construct(private readonly CustomsWatchlist $watchlist) {}

    public function index(Request $request, int $character): JsonResponse
    {
        $own = $request->user()->characters()->find($character);

        return $own ? response()->json(['success' => true, 'entries' => $this->watchlist->list($own)]) : $this->notFound();
    }

    public function store(Request $request, int $character): JsonResponse
    {
        $own = $request->user()->characters()->find($character);
        if (! $own) {
            return $this->notFound();
        }

        $data = $request->validate([
            'pseudo' => ['required', 'string', 'regex:/^\S{1,40}$/u'],
            'colour' => ['required', Rule::in(CustomsWatchlistEntry::COLOURS)],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);
        $entry = $this->watchlist->add($own, $data['pseudo'], $data['colour'], $data['reason'] ?? null);

        return response()->json(['success' => true, 'id' => $entry->id], 201);
    }

    public function update(Request $request, int $character, int $entry): JsonResponse
    {
        $own = $request->user()->characters()->find($character);
        if (! $own) {
            return $this->notFound();
        }

        $data = $request->validate([
            'colour' => ['sometimes', Rule::in(CustomsWatchlistEntry::COLOURS)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);
        $this->watchlist->update($own, $entry, $data);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, int $character, int $entry): JsonResponse
    {
        $own = $request->user()->characters()->find($character);
        if (! $own) {
            return $this->notFound();
        }

        $this->watchlist->remove($own, $entry);

        return response()->json(['success' => true]);
    }

    private function notFound(): JsonResponse
    {
        return MandateApiErrors::error(404, 'mandates.api.character_not_found');
    }
}
