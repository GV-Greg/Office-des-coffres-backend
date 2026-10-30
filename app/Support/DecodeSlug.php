<?php

namespace App\Support;

use App\Models\AnimDecodeActivity;
use Illuminate\Support\Str;

/**
 * Le « code » d'une activité de « Décode le code » : un slug PUBLIC dérivé du nom, que l'utilisateur accepte (D3, D14,
 * D26, D27). SEULE source des règles : le site demande l'aperçu au serveur, il ne les recopie pas.
 *
 *  - lettres minuscules et tirets seulement, **aucun chiffre** ;
 *  - accents retirés (é→e, ç→c, œ→oe, ß→ss), tout autre signe → un seul tiret, jamais au début, à la fin ni doublé ;
 *  - **3 à 20 caractères** ;
 *  - **aucun mot réservé** (D14) : aucune route fixe ne voisine `{slug}`, voir routes/api.php.
 *
 * Jamais de suffixe automatique en cas de collision (D27) : `taken()` le dit, l'utilisateur choisit.
 */
class DecodeSlug
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

    /** Déjà pris par une activité (privée ou sans compte, close ou non : le slug reste pris tant que la page existe). */
    public static function taken(string $slug): bool
    {
        return AnimDecodeActivity::where('slug', $slug)->exists();
    }
}
