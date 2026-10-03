<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Character extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $fillable = ['user_id', 'pseudo', 'city_id', 'is_validated', 'pending_residence_change'];

    protected $casts = [
        'is_validated' => 'boolean',
        'pending_residence_change' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, Character>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<City, Character>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return HasMany<MayorMandate>
     */
    public function mayorMandates(): HasMany
    {
        return $this->hasMany(MayorMandate::class);
    }

    /**
     * @return HasMany<CouncilMandate>
     */
    public function councilMandates(): HasMany
    {
        return $this->hasMany(CouncilMandate::class);
    }
}
