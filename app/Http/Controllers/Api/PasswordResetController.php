<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Notifications\PasswordChanged;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Throwable;

/**
 * « Mot de passe oublié » pour les joueurs (fil admin/echanges/mot-de-passe-oublie, décisions de Greg du 24/11/2026 : toutes les recommandations).
 *
 * - `forgot` : réponse TOUJOURS identique, que le compte existe ou non (Q7 : on ne révèle pas quelles adresses ont un compte) ; l'email part
 *   au gabarit de l'Office, avec un lien vers le SITE DES JOUEURS (jamais le domaine d'administration). Limiteur `password-reset`.
 * - `reset` : le jeton (haché en base, 60 minutes, à usage unique) et l'email donnent le droit de choisir un nouveau mot de passe. Un compte dont
 *   l'email n'était pas confirmé le devient (Q4 : lire le lien le prouve). Tous les jetons Passport sont révoqués (Q6) ; un email d'avis part
 *   (Q5). Jeton ou adresse invalide, expiré, déjà utilisé : UNE SEULE réponse, sans oracle. Limiteur `password-reset-confirm`.
 * Aucun mot de passe ne circule par email, rien n'est journalisé de ce que le joueur écrit.
 */
class PasswordResetController extends BaseController
{
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);

        try {
            Password::sendResetLink(['email' => $data['email']]);
        } catch (Throwable $exception) {
            // Un envoi qui échoue ne doit pas se lire dans la réponse (oracle) ; on garde la classe de l'erreur, jamais l'adresse.
            Log::warning('Lien de réinitialisation du mot de passe : envoi impossible.', ['exception' => $exception::class]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Si un compte existe pour cette adresse, un email vient de partir.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:8', 'max:190'],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ]);

        $status = Password::reset(
            [
                'email' => $data['email'],
                'password' => $data['password'],
                'password_confirmation' => $data['password_confirmation'],
                'token' => $data['token'],
            ],
            function ($user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                if (! $user->hasVerifiedEmail()) {
                    $user->markEmailAsVerified();
                }

                // Déconnecte TOUS les appareils : si quelqu'un d'autre avait une session, elle tombe.
                $user->tokens()->where('revoked', false)->get()->each(function (Token $token) {
                    RefreshToken::where('access_token_id', $token->id)->update(['revoked' => true]);
                    $token->revoke();
                });

                try {
                    $user->notify(new PasswordChanged);
                } catch (Throwable $exception) {
                    // L'avis ne bloque jamais le geste du joueur.
                    Log::warning('Avis de changement de mot de passe : envoi impossible.', ['exception' => $exception::class]);
                }

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'success' => false,
                'message' => 'Ce lien est invalide ou expiré.',
                'code' => 'invalid_token',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Mot de passe modifié.',
        ]);
    }
}
