<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class CouncilMandate extends Mandate
{
    public const LEVEL = 'council';

    protected $table = 'council_mandates';

    protected function casts(): array
    {
        return parent::casts() + ['office_requested_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Province, CouncilMandate>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function place(): BelongsTo
    {
        return $this->province();
    }

    /**
     * Historique des postes de ce mandat (Q17).
     *
     * @return HasMany<CouncilOfficePeriod>
     */
    public function officePeriods(): HasMany
    {
        return $this->hasMany(CouncilOfficePeriod::class);
    }

    /** Poste demandé (titre d'une inscription, ou déclaration) ; NULL = sans poste. */
    public function pendingOffice(): BelongsTo
    {
        return $this->belongsTo(CouncilOffice::class, 'pending_office_id');
    }

    /** « En attente » se lit sur la date, pas sur pending_office_id (NULL = devenir sans poste). */
    public function hasOfficeRequest(): bool
    {
        return $this->office_requested_at !== null;
    }

    /** Période EN VIGUEUR maintenant (started_at ≤ maintenant < ended_at), ou null. */
    public function currentPeriod(): ?CouncilOfficePeriod
    {
        $now = Carbon::now();

        return $this->officePeriods()
            ->where('started_at', '<=', $now)
            ->where('ended_at', '>', $now)
            ->with('office')
            ->first();
    }

    /** Clé du poste détenu maintenant, ou null (sans poste). */
    public function currentOfficeKey(): ?string
    {
        return $this->currentPeriod()?->office?->key;
    }
}
