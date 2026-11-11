<?php

namespace App\Models;

use App\Casts\EncryptedModuleData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un inventaire collé de la province, daté du jour de Paris, chiffré. HISTORIQUE sans suppression : un exemplaire se
 * remplace (`active` NULL + `replaced_by_id`), jamais ne s'efface. Calqué sur MineReport. Aucune règle ici : l'écriture
 * vit dans App\Services\ProvinceInventoryRegistry.
 */
class ProvinceInventorySnapshot extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'taken_on' => 'date',
            'payload' => EncryptedModuleData::class,
            'active' => 'boolean',
            'replaced_at' => 'datetime',
        ];
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }
}
