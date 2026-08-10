<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Notifications\VerifyApiEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\RefreshToken;

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

        // Lien signé, pas de mot de passe disponible ici : impossible de passer par le
        // password grant (#13). Jeton d'accès personnel classique à la place — pas de
        // refresh token dans ce cas précis, consommé immédiatement par le frontend
        // (setToken + checkAuth), l'utilisateur repassera par login() normalement une
        // fois ce jeton expiré.
        $token = $user->createToken('email-verification')->accessToken;

        return redirect(config('app.frontend_url') . '/verify-email?token=' . $token);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'       => ['required', 'email'],
            'password'    => ['required', 'string'],
            'remember_me' => ['sometimes', 'boolean'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return $this->sendError('Identifiants incorrects.', [], 401);
        }

        if (! $user->hasVerifiedEmail()) {
            return $this->sendError('Email non vérifié.', [], 403);
        }

        $user->tokens()->where('revoked', false)->get()->each->revoke();

        $tokens = $this->issuePasswordGrantTokens(
            $validated['email'],
            $validated['password'],
            $validated['remember_me'] ?? false,
        );

        if (! $tokens) {
            return $this->sendError("Émission du jeton d'accès impossible.", [], 500);
        }

        return response()->json([
            'success' => true,
            ...$tokens,
            'user'    => $this->userPayload($user),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refresh_token' => ['required', 'string'],
            'remember_me'   => ['sometimes', 'boolean'],
        ]);

        $data = $this->requestOauthToken([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $validated['refresh_token'],
            'client_id'     => config('services.passport.password_client_id'),
            'client_secret' => config('services.passport.password_client_secret'),
            'scope'         => '',
        ]);

        if (! $data) {
            return $this->sendError('Session expirée, reconnecte-toi.', [], 401);
        }

        if (! ($validated['remember_me'] ?? false)) {
            $this->shortenRefreshTokenExpiration($data['access_token']);
        }

        return response()->json([
            'success'       => true,
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_in'    => $data['expires_in'],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->token();

        RefreshToken::where('access_token_id', $token->id)->update(['revoked' => true]);
        $token->revoke();

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

    /**
     * Passe par le password grant Passport (#13 de admin/strategies/cookies.md) : login()
     * garde sa propre validation (email vérifié, message d'erreur français) au lieu de
     * s'appuyer sur les erreurs génériques d'/oauth/token, puis délègue l'émission réelle
     * du couple access+refresh token à Passport plutôt que de réinventer la rotation.
     */
    private function issuePasswordGrantTokens(string $email, string $password, bool $rememberMe): ?array
    {
        $data = $this->requestOauthToken([
            'grant_type'    => 'password',
            'client_id'     => config('services.passport.password_client_id'),
            'client_secret' => config('services.passport.password_client_secret'),
            'username'      => $email,
            'password'      => $password,
            'scope'         => '',
        ]);

        if (! $data) {
            return null;
        }

        if (! $rememberMe) {
            $this->shortenRefreshTokenExpiration($data['access_token']);
        }

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_in'    => $data['expires_in'],
        ];
    }

    /**
     * Dispatch interne vers /oauth/token plutôt qu'un vrai appel HTTP sortant
     * (Http::post(config('app.url') . '/oauth/token', ...)) : ce dernier échoue sous
     * `php artisan test` (aucun serveur n'écoute pendant la suite) et reste fragile même en
     * prod (aller-retour réseau vers soi-même, dépendant de la résolution DNS/du routage
     * public). `app()->handle()` traverse le kernel HTTP normalement (routing, middleware)
     * sans passer par un vrai socket.
     */
    private function requestOauthToken(array $params): ?array
    {
        $response = app()->handle(Request::create('/oauth/token', 'POST', $params));

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        return json_decode($response->getContent(), true);
    }

    /**
     * Passport n'a pas de notion de durée de refresh token conditionnelle à l'émission
     * (Passport::refreshTokensExpireIn() est un réglage global) — on raccourcit donc après
     * coup l'expiration du refresh token tout juste émis quand "remember_me" n'est pas
     * coché. L'access token est un JWT dont le claim `jti` correspond à l'id de la ligne
     * oauth_access_tokens ; le refresh token associé s'y retrouve par access_token_id.
     */
    private function shortenRefreshTokenExpiration(string $accessToken): void
    {
        $payload = json_decode(base64_decode(strtr(explode('.', $accessToken)[1] ?? '', '-_', '+/')), true);
        $accessTokenId = $payload['jti'] ?? null;

        if ($accessTokenId) {
            RefreshToken::where('access_token_id', $accessTokenId)
                ->update(['expires_at' => now()->addHours(12)]);
        }
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
