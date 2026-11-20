<?php

namespace App\Models;

use App\Casts\EncryptedModuleData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un inventaire collé de la ville, daté du jour de Paris, chiffré. HISTORIQUE sans suppression : un exemplaire se remplace
 * (`active` NULL + `replaced_by_id`), jamais ne s'efface. Jumeau de ProvinceInventorySnapshot. Aucune règle ici : l'écriture vit
 * dans App\Services\CityInventoryRegistry.
 */
class CityInventorySnapshot extends Model
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

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    /** L'exemplaire que celui-ci a remplacé (même jour), s'il y en a un. */
    public function replaces(): HasOne
    {
        return $this->hasOne(self::class, 'replaced_by_id');
    }
}
