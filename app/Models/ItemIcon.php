<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Association nom d'item normalisé ↔ ID icône Celsius (brief admin/content/brief-icones-items.md).
 * L'URL CDN est construite à la volée par iconUrl() — aucune image stockée côté ODC.
 */
class ItemIcon extends Model
{
    /** Pattern d'URL du CDN Celsius Online pour les icônes d'items. */
    public const CDN_URL_PATTERN = 'https://lesroyaumes.cdn.oxv.fr/images/items/item%d.png';

    protected $guarded = ['id'];

    /**
     * Forme sous laquelle deux noms d'items se rapprochent : espaces superflus retirés, casse ignorée.
     * Faite en PHP et non par la collation de la base : MariaDB (prod) ignore la casse ET les accents,
     * SQLite (tests) ne les ignore pas, et le même nom doit se rapprocher partout de la même façon.
     * Les accents comptent : « Mais » et « Maïs » sont deux noms (la base, elle, refusera d'enregistrer
     * les deux — collation `utf8mb4_unicode_ci`).
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * Règles de forme des deux noms d'un item (singulier `item_name`, pluriel `item_name_plural`),
     * partagées par la page Blade et l'API admin. Un nom — singulier OU pluriel — n'appartient qu'à
     * un seul item : sans cela « Kilos de minerai de fer » pourrait désigner deux icônes. Le pluriel
     * est facultatif et doit différer du singulier (un item invariable n'en déclare pas).
     *
     * @param  string|null  $singular  le singulier effectif de la ligne (saisi, ou déjà enregistré)
     * @param  string|null  $plural  le pluriel effectif quand la requête ne le porte pas : il ne doit pas devenir égal au nouveau singulier
     * @param  bool  $partial  correction d'une ligne existante : le singulier n'est validé que s'il est envoyé
     * @return array<string, array<int, mixed>>
     */
    public static function nameRules(?int $ignoreId = null, ?string $singular = null, ?string $plural = null, bool $partial = false): array
    {
        $taken = function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId): void {
            if (! is_string($value) || $value === '') {
                return;
            }
            $exists = static::query()
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->where(fn ($q) => $q->where('item_name', $value)->orWhere('item_name_plural', $value))
                ->exists();
            if ($exists) {
                $fail(__('item_icons.name_taken'));
            }
        };

        // Le pluriel diffère du singulier ; et, vu de l'autre côté, un singulier ne peut pas devenir le pluriel enregistré.
        $differsFrom = fn (?string $other) => function (string $attribute, mixed $value, \Closure $fail) use ($other): void {
            if (is_string($value) && $other !== null && self::normalizeName($value) === self::normalizeName($other)) {
                $fail(__('item_icons.plural_same'));
            }
        };

        return [
            'item_name' => [...($partial ? ['sometimes'] : []), 'required', 'string', 'max:150', $taken, $differsFrom($plural)],
            'item_name_plural' => ['nullable', 'string', 'max:150', $taken, $differsFrom($singular)],
        ];
    }

    /** Les noms sous lesquels l'item se retrouve : son singulier et, s'il existe, son pluriel. */
    public function names(): array
    {
        return array_values(array_filter([$this->item_name, $this->item_name_plural]));
    }

    /** URL publique de l'icône sur le CDN Celsius. */
    public function iconUrl(): string
    {
        return sprintf(self::CDN_URL_PATTERN, $this->game_item_id);
    }
}
