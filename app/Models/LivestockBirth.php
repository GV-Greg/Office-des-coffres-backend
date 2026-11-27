<?php

namespace App\Models;

use App\Casts\EncryptedModuleData;
use Illuminate\Database\Eloquent\Model;

/**
 * Une naissance inscrite au Livre. `payload` chiffré par MODULE_DATA_KEY (espèce, nombre, nourriture, quantité prise et
 * perdue). Jamais supprimée : un retrait la date (`withdrawn_at`) et elle n'est plus comptée.
 */
class LivestockBirth extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'born_on' => 'date',
            'payload' => EncryptedModuleData::class.':array',
            'withdrawn_at' => 'datetime',
        ];
    }
}
