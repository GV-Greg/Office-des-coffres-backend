<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MayorMandate extends Mandate
{
    public const LEVEL = 'mayor';

    protected $table = 'mayor_mandates';

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function place(): BelongsTo
    {
        return $this->city();
    }
}
