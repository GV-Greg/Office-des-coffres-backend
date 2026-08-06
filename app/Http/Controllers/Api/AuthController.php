<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Notifications\VerifyApiEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;

class AuthController extends BaseController
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'        => ['required', 'email', 'max:190', 'unique:users,email'],
            'password'     => ['required', 'string', 'min:8', 'max:190'],
            'confirmation' => ['required', 'string', 'same:password'],
        ]);

        $user = User::create([
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->notify(new VerifyApiEmail());

        return response()->json([
            'success' => true,
            'message' => 'Compte créé. Vérifiez votre boîte mail pour confirmer votre adresse.',
        ], 201);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->email)->first();

        // Ne pas révéler si l'email existe ou non : même réponse dans tous les cas.
        if ($user && ! $user->hasVerifiedEmail()) {
            $user->notify(new VerifyApiEmail());
        }

        return response()->json([
            'success' => true,
            'message' => "Si un compte non vérifié existe pour cet email, un nouveau lien vient d'être envoyé.",
        ]);
    }

    public function verifyEmail(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect(config('app.frontend_url') . '/verify-email?error=invalid');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return redirect(config('app.frontend_url') . '/verify-email?token=' . $token);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->sendError('Identifiants incorrects.', [], 401);
        }

        if (! $user->hasVerifiedEmail()) {
            return $this->sendError('Email non vérifié.', [], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => $this->userPayload($user),
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
        return response()->json([
            'success' => true,
            'user'    => $this->userPayload($request->user()),
        ]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id'         => $user->id,
            'email'      => $user->email,
            'is_admin'   => $user->hasRole('admin'),
            'characters' => $user->characters->load('city.province.kingdom')->map(fn ($character) => [
                'id'            => $character->id,
                'pseudo'        => $character->pseudo,
                'city_id'       => $character->city_id,
                'city_name'     => $character->city?->city_name,
                'province_name' => $character->city?->province?->province_name,
                'kingdom_name'  => $character->city?->province?->kingdom?->kingdom_name,
                'is_validated'  => $character->is_validated,
                'pending_residence_change' => $character->pending_residence_change,
            ]),
        ];
    }
}
