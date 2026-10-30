<?php

namespace App\Services;

/**
 * Note d'une combinaison de « Décode le code » (brief admin/modules/animation/brief-coffres-scelles.md) :
 * Bulls-and-Cows sur 5 chiffres. Service PUR — aucune dépendance Eloquent.
 *
 *  1. chaque position exacte vaut 2 et consomme le chiffre du secret ;
 *  2. chaque position restante vaut 1 si son chiffre existe encore parmi les chiffres du secret
 *     NON consommés, et le consomme à son tour (les occurrences comptent : jamais 0 ou 2) ;
 *  3. le total est plafonné à 10 ;
 *  4. une combinaison trouvée (10) est ENREGISTRÉE avec 20 points.
 */
class DecodeScoring
{
    public const LENGTH = 5;

    public const MAX = 10;

    /** Points enregistrés pour une combinaison trouvée. */
    public const FOUND = 20;

    /**
     * @param  list<int>  $secret
     * @param  list<int>  $guess
     */
    public static function score(array $secret, array $guess): int
    {
        $score = 0;
        $remaining = [];
        $unmatched = [];

        foreach ($guess as $i => $digit) {
            if (($secret[$i] ?? null) === $digit) {
                $score += 2;
            } else {
                $remaining[] = $secret[$i] ?? null;
                $unmatched[] = $digit;
            }
        }

        foreach ($unmatched as $digit) {
            $at = array_search($digit, $remaining, true);
            if ($at !== false) {
                $score += 1;
                unset($remaining[$at]);
            }
        }

        return min($score, self::MAX);
    }

    /** Points à enregistrer : 20 si la combinaison est trouvée, sinon la note. */
    public static function recorded(int $score): int
    {
        return $score === self::MAX ? self::FOUND : $score;
    }

    public static function isFound(int $score): bool
    {
        return $score === self::MAX;
    }

    /**
     * "01234" → [0, 1, 2, 3, 4] ; null si ce n'est pas exactement cinq chiffres.
     *
     * @return list<int>|null
     */
    public static function digits(string $combination): ?array
    {
        if (! preg_match('/^\d{'.self::LENGTH.'}$/', $combination)) {
            return null;
        }

        return array_map('intval', str_split($combination));
    }
}
