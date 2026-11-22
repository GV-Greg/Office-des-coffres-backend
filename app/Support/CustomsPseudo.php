<?php

namespace App\Support;

/**
 * Comparaison des pseudos du module Sécurité (Greg, 22/11/2026, C4) : tout en MINUSCULES pour comparer et colorer, mais on AFFICHE ce
 * qui a été collé (casse d'origine préservée). Un pseudo ne contient jamais d'espace (confirmé par les collages réels du 22/11/2026) :
 * seuls les points, soulignés, tirets, chiffres et accents s'y rencontrent, et on ne les « nettoie » jamais.
 */
class CustomsPseudo
{
    public static function key(string $pseudo): string
    {
        return mb_strtolower(trim($pseudo), 'UTF-8');
    }

    /** @param  array<int, string>  $pseudos */
    public static function inList(string $pseudo, array $pseudos): bool
    {
        $key = self::key($pseudo);

        return in_array($key, array_map(self::key(...), $pseudos), true);
    }
}
