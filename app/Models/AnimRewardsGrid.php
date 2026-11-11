<?php

namespace App\Models;

use App\Casts\EncryptedModuleData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une grille des lots, privée (liée à un personnage, catégorie C) ou sans compte. Automate d'état :
 * new → incomplete → filled → drawed → confirmed → closed. Aucune règle ici : tout passe par
 * App\Services\RewardsGridWorkflow.
 */
class AnimRewardsGrid extends Model
{
    public const NEW = 'new';

    public const INCOMPLETE = 'incomplete';

    public const FILLED = 'filled';

    public const DRAWED = 'drawed';

    public const CONFIRMED = 'confirmed';

    public const CLOSED = 'closed';

    /** Inactivité au-delà de laquelle `anim:close-inactive` ferme une grille. Constante, jamais config/ (incident 04/10/2026). */
    public const INACTIVITY_DAYS = 30;

    /** Bornes du côté d'une grille (décision de Greg, 11/11/2026 : 3 à 20, soit 9 à 400 cases, dans les 500 noms d'une requête). */
    public const MIN_SIDE = 3;

    public const MAX_SIDE = 20;

    /** Années pendant lesquelles la page d'une grille SANS COMPTE reste en ligne après sa clôture. */
    public const RETENTION_YEARS = 5;

    /** Statuts où la grille est visible publiquement (depuis le tirage). */
    public const PUBLIC_STATUSES = [self::DRAWED, self::CONFIRMED, self::CLOSED];

    /** Statuts où l'on peut encore ajouter/retirer des lots. */
    public const EDITABLE_STATUSES = [self::NEW, self::INCOMPLETE, self::FILLED];

    protected $guarded = ['id'];

    protected $hidden = ['password_hash', 'creator_ip_hash', 'organizer_email', 'reset_token_hash'];

    protected function casts(): array
    {
        return [
            'is_anonymous' => 'boolean',
            'width' => 'integer',
            'height' => 'integer',
            'drawn_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'closed_at' => 'datetime',
            'organizer_email' => EncryptedModuleData::class,
            'organizer_email_verified_at' => 'datetime',
            'reset_token_expires_at' => 'datetime',
            'password_reset_at' => 'datetime',
            'reset_requested_at' => 'datetime',
            'closing_snapshot' => 'array',
        ];
    }

    public function capacity(): int
    {
        return $this->width * $this->height;
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    public function isPublic(): bool
    {
        return in_array($this->status, self::PUBLIC_STATUSES, true);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->organizer_email !== null && $this->organizer_email_verified_at !== null;
    }

    /** @return BelongsTo<Character, AnimRewardsGrid> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** @return BelongsTo<AnimActivityType, AnimRewardsGrid> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(AnimActivityType::class, 'activity_type_id');
    }

    /** @return BelongsTo<Kingdom, AnimRewardsGrid> */
    public function kingdom(): BelongsTo
    {
        return $this->belongsTo(Kingdom::class);
    }

    /** @return BelongsTo<Province, AnimRewardsGrid> */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /** @return BelongsTo<City, AnimRewardsGrid> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return HasMany<AnimRewardsList> */
    public function lots(): HasMany
    {
        return $this->hasMany(AnimRewardsList::class, 'grid_id')->orderBy('id');
    }
}
