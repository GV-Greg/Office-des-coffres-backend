<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Période de détention d'un poste par un mandat de conseiller (Q17). `ended_at` est toujours
 * renseigné : il naît égal au holds_until du mandat, avec end_reason = fin_du_mandat, et n'est
 * écrit que par MandateWorkflow (Q19). Une période fermée n'est JAMAIS rouverte (Q23).
 *
 * ⚠️ Exclusivité : deux périodes d'un même poste dans une même province n'ont jamais de fenêtres
 * [started_at, ended_at) qui se chevauchent. MySQL ne sait pas l'exprimer (index partiel) : le
 * test MandateOfficeTest est le seul garde-fou.
 */
class CouncilOfficePeriod extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<CouncilMandate, CouncilOfficePeriod>
     */
    public function mandate(): BelongsTo
    {
        return $this->belongsTo(CouncilMandate::class, 'council_mandate_id');
    }

    /**
     * @return BelongsTo<CouncilOffice, CouncilOfficePeriod>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(CouncilOffice::class, 'council_office_id');
    }
}
