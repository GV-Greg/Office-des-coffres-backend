<?php

namespace App\Support;

/**
 * Lien de réinitialisation du mot de passe envoyé aux joueurs : il pointe vers le SITE DES JOUEURS
 * (/reset-password?token=…&email=…), jamais vers le domaine d'administration (hameçonnage, Greg, 05/10/2026). Seul générateur de ce lien. Le jeton est celui
 * du broker de Laravel (haché en base, `password_reset_tokens`) ; la page rappelle l'API avec ce jeton.
 */
class PasswordResetLink
{
    public static function url(string $token, string $email): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $email,
        ]);
    }
}
