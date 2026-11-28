<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Les espèces élevées dans une province. Écriture : App\Services\LivestockRegister. */
class ProvinceLivestockSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['species' => 'array'];
    }
}
