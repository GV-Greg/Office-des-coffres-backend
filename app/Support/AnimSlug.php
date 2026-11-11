<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Le slug public d'une activité du module Animation : dérivé du nom, que l'utilisateur accepte ou corrige.
 * SEULE source des règles : le site demande l'aperçu au serveur, il ne les recopie pas.
 *
 *  - lettres minuscules et tirets seulement, **aucun chiffre** ;
 *  - accents retirés (é→e, ç→c, œ→oe, ß→ss), tout autre signe → un seul tiret, jamais au début, à la fin ni doublé ;
 *  - **3 à 20 caractères** ;
 *  - **aucun mot réservé** : aucune route fixe ne voisine `{slug}`, voir routes/api.php.
 *
 * `taken()` prend la class-string du modèle concerné : chaque type d'activité a sa propre table, les slugs ne sont
 * uniques qu'au sein d'un type (les pages publiques vivent sous des préfixes distincts).
 *
 * Jamais de suffixe automatique en cas de collision : `taken()` le dit, l'utilisateur choisit.
 */
class AnimSlug
{
    public const MIN = 3;

    public const MAX = 20;

    public const PATTERN = '/^[a-z]+(?:-[a-z]+)*$/';

    /** Proposition tirée d'un nom : peut être vide ou trop courte (un nom fait de chiffres) — l'utilisateur corrige. */
    public static function propose(string $name): string
    {
        $slug = (string) preg_replace('/[^a-z]+/', '-', Str::lower(Str::ascii($name)));
        $slug = trim($slug, '-');

        if (strlen($slug) > self::MAX) {
            $cut = substr($slug, 0, self::MAX + 1);
            // Coupe à un tiret si possible (un mot entier plutôt qu'un mot tronqué), sinon net à 20.
            $at = strrpos($cut, '-');
            $slug = $at !== false && $at >= self::MIN ? substr($cut, 0, $at) : substr($slug, 0, self::MAX);
        }

        return trim($slug, '-');
    }

    /** Vrai si la forme est acceptée (longueur et caractères). */
    public static function isValid(string $slug): bool
    {
        $length = strlen($slug);

        return $length >= self::MIN && $length <= self::MAX && preg_match(self::PATTERN, $slug) === 1;
    }

    /**
     * Déjà pris dans la table du modèle donné (close ou non : le slug reste réservé tant que la page existe).
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    public static function taken(string $slug, string $model): bool
    {
        return $model::where('slug', $slug)->exists();
    }
}
