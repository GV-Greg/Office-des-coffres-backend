<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une activité des Coffres Scellés. Aucune règle ici : tout passe par App\Services\DecodeWorkflow.
 */
class AnimDecodeActivity extends Model
{
    public const NEW = 'new';

    public const LAUNCHED = 'launched';

    public const RELAUNCH = 'relaunch';

    public const CLOSED = 'closed';

    /**
     * Inactivité au-delà de laquelle `anim:close-inactive` ferme une activité. Constante et non
     * `config/` : un NOUVEAU fichier config est absent du cache de configuration en prod (incident du
     * 04/10/2026, voir CLAUDE.md).
     */
    public const INACTIVITY_DAYS = 30;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['launched_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    /** Organisateur, NULL une fois son personnage supprimé (catégorie C). @return BelongsTo<Character, AnimDecodeActivity> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** @return BelongsTo<AnimActivityType, AnimDecodeActivity> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(AnimActivityType::class, 'activity_type_id');
    }

    /** @return BelongsTo<Province, AnimDecodeActivity> */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /** @return BelongsTo<City, AnimDecodeActivity> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** Dans l'ordre de création : le rang d'un coffre est son numéro. @return HasMany<AnimDecodeSecret> */
    public function secrets(): HasMany
    {
        return $this->hasMany(AnimDecodeSecret::class, 'decode_activity_id')->orderBy('id');
    }
}
