<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mandat de maire ou de conseiller comtal : une ligne porte la demande ET le mandat, distingués
 * par `status`. Une table par niveau (§5ter), cette classe ne porte que le commun.
 *
 * RÈGLE DE LECTURE UNIQUE (Q1) : l'autorité court de `in_office_from` à `holds_until`, quel que
 * soit le statut. Toute fin effective — échéance, prolongation, passation, révocation — s'écrit
 * dans `holds_until`, et UNIQUEMENT par MandateWorkflow::setHoldsUntil(), qui écrit dans le même
 * appel `holds_until_set_by` et l'`ended_at` de la période de poste en cours (Q19, Q25).
 * `valid_until` (fin nominale) ne bouge qu'avec une correction de `started_at` : un `holds_until`
 * écrasé par erreur se recalcule depuis elle (MandateCalendar::nominalHoldsUntil).
 */
abstract class Mandate extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const REVOKED = 'revoked';

    /** Niveau exposé par l'API et dans les routes : « mayor » ou « council ». */
    public const LEVEL = '';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'declared_started_at' => 'date',
            'started_at' => 'date',
            'valid_until' => 'datetime',
            'in_office_from' => 'datetime',
            'holds_until' => 'datetime',
            'processed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Character, Mandate>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function renews(): BelongsTo
    {
        return $this->belongsTo(static::class, 'renews_id');
    }

    /** Le lieu du mandat : une ville ou une province. */
    abstract public function place(): BelongsTo;

    /**
     * Autorité en vigueur MAINTENANT. Inclut `revoked` : un révoqué l'était avant sa date d'effet
     * (sémantique A de Q10), et cette date n'est jamais dans le futur, donc il ne passe jamais ce
     * filtre aujourd'hui. Pas de paramètre de date (Q10).
     */
    public function scopeInOfficeNow(Builder $query): Builder
    {
        $now = Carbon::now();

        return $query->whereIn('status', [self::APPROVED, self::REVOKED])
            ->whereNotNull('in_office_from')
            ->where('in_office_from', '<=', $now)
            ->where('holds_until', '>', $now);
    }

    /** Approuvé et pas encore terminé : actif, en prolongation, ou élu pas encore en fonction. */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED)->where('holds_until', '>', Carbon::now());
    }

    /**
     * Statut EFFECTIF, calculé, jamais stocké : pending, elected, active, extended, expired,
     * rejected, revoked. L'expiration est sèche à `holds_until`.
     */
    public function effectiveStatus(): string
    {
        if (in_array($this->status, [self::PENDING, self::REJECTED, self::REVOKED], true)) {
            return $this->status;
        }

        $now = Carbon::now();

        if ($this->holds_until !== null && $now->greaterThanOrEqualTo($this->holds_until)) {
            return 'expired';
        }

        if ($this->in_office_from === null || $now->lessThan($this->in_office_from)) {
            return 'elected';
        }

        if ($this->valid_until !== null && $now->greaterThanOrEqualTo($this->valid_until)) {
            return 'extended';
        }

        return 'active';
    }

    public function isRunning(): bool
    {
        return in_array($this->effectiveStatus(), ['elected', 'active', 'extended'], true);
    }

    public function isInOffice(): bool
    {
        return in_array($this->effectiveStatus(), ['active', 'extended'], true);
    }

    /** Ajoute une ligne datée à la note interne (trace cumulative, jamais envoyée ni exportée). */
    public function appendNote(string $line): void
    {
        $stamp = Carbon::now(config('mandates.timezone'))->format('d/m/Y H:i');
        $this->note = trim(($this->note ? $this->note."\n" : '')."{$stamp} — {$line}");
    }
}
