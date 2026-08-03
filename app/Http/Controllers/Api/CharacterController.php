<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CharacterController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $characters = $request->user()->characters->map(fn ($character) => [
            'id'           => $character->id,
            'pseudo'       => $character->pseudo,
            'city_id'      => $character->city_id,
            'is_validated' => $character->is_validated,
        ]);

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
            'pseudo'       => $validated['pseudo'],
            'city_id'      => $validated['city_id'],
            'is_validated' => false,
        ]);

        return response()->json([
            'success'   => true,
            'character' => [
                'id'           => $character->id,
                'pseudo'       => $character->pseudo,
                'city_id'      => $character->city_id,
                'is_validated' => $character->is_validated,
            ],
        ], 201);
    }
}
