<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réglages de sécurité d'une PROVINCE (catégorie C). Écrits par App\Services\CustomsOfficers::setInactivityDays().
 */
class ProvinceSecuritySettings extends Model
{
    protected $table = 'province_security_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['customs_inactivity_days' => 'integer'];
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
