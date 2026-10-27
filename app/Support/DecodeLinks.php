<?php

namespace App\Support;

use App\Models\AnimDecodeActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Liens des emails et des pages de « Décode le code » : tous pointent vers le SITE DES JOUEURS, jamais vers le domaine
 * d'administration (dans un email, il ressemble à de l'hameçonnage — Greg, 05/10/2026). Seul générateur.
 *
 * La confirmation d'adresse suit le patron d'EmailVerificationLink : la signature est celle de l'API (route
 * `anim.decode.email-confirm`), la page du site rappelle l'API en JSON avec exactement ces paramètres.
 */
class DecodeLinks
{
    /** Validité du lien de confirmation d'adresse. */
    public const CONFIRMATION_DAYS = 3;

    /** Validité du lien de réinitialisation du mot de passe (D35). */
    public const RESET_HOURS = 24;

    public static function site(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }

    /** Page publique de l'activité (le lien des exports BBCode et des comptes rendus). */
    public static function page(string $slug): string
    {
        return self::site('/public/activite/decode/'.rawurlencode($slug));
    }

    public static function emailConfirmation(AnimDecodeActivity $activity, string $email): string
    {
        $hash = sha1($email);
        $signed = URL::temporarySignedRoute(
            'anim.decode.email-confirm',
            Carbon::now()->addDays(self::CONFIRMATION_DAYS),
            ['slug' => $activity->slug, 'hash' => $hash],
        );
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return self::site('/app/anim/decode/confirmer-email?'.http_build_query([
            'slug' => $activity->slug,
            'hash' => $hash,
            'expires' => $query['expires'],
            'signature' => $query['signature'],
        ]));
    }

    public static function reset(string $slug, string $token): string
    {
        return self::site('/app/anim/decode/reinitialiser?'.http_build_query(['slug' => $slug, 'token' => $token]));
    }
}
