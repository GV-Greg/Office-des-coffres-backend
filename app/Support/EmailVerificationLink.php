<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Lien de confirmation d'email envoyé aux joueurs : il pointe vers le SITE DES JOUEURS
 * (/verify-email?id=…&hash=…&expires=…&signature=…), jamais vers l'API. Un domaine « admin » dans
 * un email de joueur ressemble à de l'hameçonnage (Greg, 05/10/2026).
 *
 * La signature reste celle de l'API (route verification.verify.api, URL absolue sur app.url) : la
 * page VerifyEmailView rappelle l'API avec exactement ces paramètres, en JSON, et la signature se
 * vérifie sur cette requête. Seul générateur de ce lien — inscription et rappel avant suppression.
 */
class EmailVerificationLink
{
    public static function url(User $user, Carbon $expiresAt): string
    {
        $signed = URL::temporarySignedRoute('verification.verify.api', $expiresAt, [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return rtrim((string) config('app.frontend_url'), '/').'/verify-email?'.http_build_query([
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
            'expires' => $query['expires'],
            'signature' => $query['signature'],
        ]);
    }
}
