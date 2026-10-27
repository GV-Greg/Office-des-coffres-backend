<?php

namespace App\Models;

use App\Services\DecodeScoring;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une proposition saisie par l'organisateur. `created_at` seul : elle ne se modifie pas.
 */
class AnimDecodeProposal extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function isFound(): bool
    {
        return $this->points === DecodeScoring::FOUND;
    }

    /** @return BelongsTo<AnimDecodeSecret, AnimDecodeProposal> */
    public function secret(): BelongsTo
    {
        return $this->belongsTo(AnimDecodeSecret::class, 'secret_id');
    }
}
