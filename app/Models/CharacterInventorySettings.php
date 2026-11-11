<?php

namespace App\Models;

use App\Casts\EncryptedModuleData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réglages d'inventaire d'un PERSONNAGE (catégorie A : supprimés avec lui) et son dernier inventaire collé, chiffré.
 * Aucune règle ici : l'écriture vit dans App\Services\InventorySettings, la forme du document dans
 * App\Support\InventorySettingsDocument.
 */
class CharacterInventorySettings extends Model
{
    protected $table = 'character_inventory_settings';

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

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
