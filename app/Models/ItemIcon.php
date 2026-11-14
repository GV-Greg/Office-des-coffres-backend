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

    /** URL publique de l'icône sur le CDN Celsius. */
    public function iconUrl(): string
    {
        return sprintf(self::CDN_URL_PATTERN, $this->game_item_id);
    }
}
