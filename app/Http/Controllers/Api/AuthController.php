<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Models\Character;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends BaseController
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username'     => ['required', 'string', 'max:190', 'unique:characters,pseudo'],
            'email'        => ['required', 'email', 'max:190', 'unique:users,email'],
            'password'     => ['required', 'string', 'min:8', 'max:190'],
            'confirmation' => ['required', 'string', 'same:password'],
        ]);

        $user = User::create([
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        Character::create([
            'user_id'      => $user->id,
            'pseudo'       => $validated['username'],
            'is_validated' => false,
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => [
                'id'           => $user->id,
                'email'        => $user->email,
                'pseudo'       => $validated['username'],
                'is_validated' => false,
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $character = Character::where('pseudo', $request->username)->first();

        if (! $character) {
            return $this->sendError('Identifiants incorrects.', [], 401);
        }

        $user = $character->user;

        if (! Hash::check($request->password, $user->password)) {
            return $this->sendError('Identifiants incorrects.', [], 401);
        }

        $user->tokens()->delete();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => [
                'id'           => $user->id,
                'email'        => $user->email,
                'pseudo'       => $character->pseudo,
                'is_validated' => $character->is_validated,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Déconnecté.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user      = $request->user();
        $character = $user->characters()->first();

        return response()->json([
            'success' => true,
            'user'    => [
                'id'           => $user->id,
                'email'        => $user->email,
                'pseudo'       => $character?->pseudo,
                'is_validated' => $character?->is_validated ?? false,
            ],
        ]);
    }
}
