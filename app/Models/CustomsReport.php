<?php

namespace App\Models;

use App\Casts\EncryptedModuleData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bilan de défense d'une ville pour un jour du jeu (module Sécurité), payload chiffré. HISTORIQUE sans suppression : un bilan se
 * remplace (`active` NULL + `replaced_by_id`), jamais ne s'efface. Aucune règle ici : l'écriture vit dans App\Services\CustomsReports.
 */
class CustomsReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reported_on' => 'date',
            'payload' => EncryptedModuleData::class.':array',
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
}
