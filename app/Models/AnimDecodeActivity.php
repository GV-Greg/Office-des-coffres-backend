<?php

namespace App\Models;

use App\Casts\EncryptedModuleData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une activité de « Décode le code », privée (liée à un personnage) ou sans compte. Aucune règle ici : tout passe
 * par App\Services\DecodeWorkflow et ses voisins.
 */
class AnimDecodeActivity extends Model
{
    public const NEW = 'new';

    public const LAUNCHED = 'launched';

    public const RELAUNCH = 'relaunch';

    public const CLOSED = 'closed';

    /**
     * Inactivité au-delà de laquelle `anim:close-inactive` ferme une activité. Constante et non `config/` : un
     * NOUVEAU fichier config est absent du cache de configuration en prod (incident du 04/10/2026, CLAUDE.md).
     */
    public const INACTIVITY_DAYS = 30;

    /** Années pendant lesquelles la page de résumé d'une activité SANS COMPTE reste en ligne après sa clôture (D7). */
    public const RETENTION_YEARS = 5;

    protected $guarded = ['id'];

    /** Jamais dans une réponse : mot de passe haché, empreinte d'IP, adresse chiffrée, jeton haché. */
    protected $hidden = ['password_hash', 'creator_ip_hash', 'organizer_email', 'reset_token_hash'];

    protected function casts(): array
    {
        return [
            'is_anonymous' => 'boolean',
            'launched_at' => 'datetime',
            'closed_at' => 'datetime',
            'organizer_email' => EncryptedModuleData::class,
            'organizer_email_verified_at' => 'datetime',
            'reset_token_expires_at' => 'datetime',
            'password_reset_at' => 'datetime',
            'reset_requested_at' => 'datetime',
            'closing_snapshot' => 'array',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    /** Une adresse n'est utilisable qu'une fois confirmée (D29). */
    public function hasVerifiedEmail(): bool
    {
        return $this->organizer_email !== null && $this->organizer_email_verified_at !== null;
    }

    /** Organisateur d'une activité privée (le pseudo se lit en direct, jamais recopié). @return BelongsTo<Character, AnimDecodeActivity> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** @return BelongsTo<AnimActivityType, AnimDecodeActivity> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(AnimActivityType::class, 'activity_type_id');
    }

    /** @return BelongsTo<Kingdom, AnimDecodeActivity> */
    public function kingdom(): BelongsTo
    {
        return $this->belongsTo(Kingdom::class);
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
