<?php

namespace App\Support;

use App\Exceptions\MandateRefusal;

/**
 * Règles du mot de passe d'un organisateur sans compte (D10, D28) : IMPOSÉES PAR LE SERVEUR. La barre de force du
 * site n'est qu'une aide ; aucune règle de sécurité n'y repose.
 *
 *  - 10 caractères au moins ;
 *  - différent du slug et du pseudo de l'organisateur (ce qu'un tiers essaie en premier).
 */
class DecodePassword
{
    public const MIN = 10;

    /** @throws MandateRefusal */
    public static function assertValid(string $password, string $slug, ?string $pseudo): void
    {
        if (mb_strlen($password) < self::MIN) {
            throw MandateRefusal::make('password', 'anim.api.password_too_short', ['min' => self::MIN]);
        }

        $lower = mb_strtolower($password);
        if ($lower === mb_strtolower($slug) || ($pseudo !== null && $pseudo !== '' && $lower === mb_strtolower($pseudo))) {
            throw MandateRefusal::make('password', 'anim.api.password_too_guessable');
        }
    }
}
