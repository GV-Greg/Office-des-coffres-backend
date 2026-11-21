<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Douanier : rôle de l'Office (inexistant en jeu), demandé par le joueur et validé par le trio de sécurité. Voir la migration
 * pour le schéma et App\Services\CustomsOfficers, SEUL écrivain.
 */
class CustomsOfficer extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const REVOKED = 'revoked';

    /** Causes d'une fin de rôle, en texte (jamais en FK). */
    public const REASON_SELF = 'self';

    public const REASON_PRIMARY = 'primary';

    public const REASON_FALLBACK = 'fallback';

    public const REASON_ADMIN = 'admin';

    public const REASON_INACTIVITY = 'inactivity';

    /**
     * Inactivité (Greg, 21/11/2026) : durée par défaut et bornes du réglage de province, EN CONSTANTES — un nouveau
     * config/*.php serait absent du cache de configuration de la prod (incident du 04/10/2026).
     */
    public const DEFAULT_INACTIVITY_DAYS = 90;

    public const MIN_INACTIVITY_DAYS = 30;

    public const MAX_INACTIVITY_DAYS = 365;

    /** Sans trio connu, avis au douanier ce nombre de jours avant la révocation automatique. */
    public const INACTIVITY_NOTICE_DAYS = 7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'active' => 'integer',
            'decision_level' => 'integer',
            'decided_at' => 'datetime',
            'in_office_from' => 'datetime',
            'revoked_at' => 'datetime',
            'last_report_at' => 'datetime',
            'inactivity_notice_sent_at' => 'datetime',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'decided_by_character_id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    /** Le compteur d'inactivité part de l'approbation, puis du dernier bilan saisi (jamais d'une autre action). */
    public function inactivityReference(): ?Carbon
    {
        $reference = collect([$this->last_report_at, $this->in_office_from])->filter()->max();

        return $reference ? Carbon::instance($reference) : null;
    }
}
