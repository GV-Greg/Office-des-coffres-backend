<?php

namespace App\Models;

use App\Casts\EncryptedModuleData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réglages d'inventaire d'une VILLE (catégorie C : ils restent quand leur auteur disparaît) et son dernier inventaire
 * collé, chiffré. Écrits par le maire en fonction. Aucune règle ici : voir App\Services\InventorySettings.
 */
class CityInventorySettings extends Model
{
    protected $table = 'city_inventory_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'version' => 'integer',
            'inventory' => EncryptedModuleData::class,
            'inventory_saved_at' => 'datetime',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'updated_by_character_id');
    }
}
