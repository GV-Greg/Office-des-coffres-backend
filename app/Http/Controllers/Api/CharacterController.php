<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Models\Character;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CharacterController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $characters = $request->user()->characters->load('city.province.kingdom')
            ->map(fn ($character) => $this->characterPayload($character));

        return response()->json([
            'success'    => true,
            'characters' => $characters,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pseudo'  => ['required', 'string', 'max:190', 'unique:characters,pseudo'],
            'city_id' => ['required', 'integer', 'exists:rk_cities,id'],
        ]);

        $character = $request->user()->characters()->create([
            'pseudo'                    => $validated['pseudo'],
            'city_id'                   => $validated['city_id'],
            'is_validated'              => false,
            'pending_residence_change'  => false,
        ])->load('city.province.kingdom');

        return response()->json([
            'success'   => true,
            'character' => $this->characterPayload($character),
        ], 201);
    }

    public function update(Request $request, int $character): JsonResponse
    {
        $character = $request->user()->characters()->find($character);

        if (! $character) {
            return $this->sendError('Personnage introuvable.', [], 404);
        }

        $validated = $request->validate([
            'city_id' => ['required', 'integer', 'exists:rk_cities,id'],
        ]);

        $character->update([
            'city_id'                  => $validated['city_id'],
            'is_validated'             => false,
            'pending_residence_change' => true,
        ]);

        $character->load('city.province.kingdom');

        return response()->json([
            'success'   => true,
            'character' => $this->characterPayload($character),
        ]);
    }

    private function characterPayload(Character $character): array
    {
        return [
            'id'            => $character->id,
            'pseudo'        => $character->pseudo,
            'city_id'       => $character->city_id,
            'city_name'     => $character->city?->city_name,
            'province_name' => $character->city?->province?->province_name,
            'kingdom_name'  => $character->city?->province?->kingdom?->kingdom_name,
            'is_validated'  => $character->is_validated,
            'pending_residence_change' => $character->pending_residence_change,
        ];
    }
}
