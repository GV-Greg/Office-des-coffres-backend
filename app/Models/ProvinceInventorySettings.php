<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réglages d'inventaire d'une PROVINCE (catégorie C), écrits par son commissaire au commerce en fonction. L'inventaire
 * de la province n'est PAS ici : il a un historique (App\Models\ProvinceInventorySnapshot). Voir App\Services\InventorySettings.
 */
class ProvinceInventorySettings extends Model
{
    protected $table = 'province_inventory_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'version' => 'integer',
        ];
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'updated_by_character_id');
    }
}
