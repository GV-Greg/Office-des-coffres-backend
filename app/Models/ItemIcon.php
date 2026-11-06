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

    /** URL publique de l'icône sur le CDN Celsius. */
    public function iconUrl(): string
    {
        return sprintf(self::CDN_URL_PATTERN, $this->game_item_id);
    }
}
