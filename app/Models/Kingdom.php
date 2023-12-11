<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kingdom extends Model
{
    /**
     * The name of table.
     *
     * @var string
     */
    protected $table = 'rk_kingdoms';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'kingdom_name',
    ];

    /**
     * @return HasMany<Province>
     */
    public function provinces(): HasMany
    {
        return $this->hasMany(Province::class, 'kingdom_id');
    }
}
