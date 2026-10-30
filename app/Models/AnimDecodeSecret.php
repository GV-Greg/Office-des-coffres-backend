<?php

namespace App\Models;

use App\Casts\EncryptedModuleData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un coffre scellé : `code` (5 chiffres) est chiffré par MODULE_DATA_KEY. `status` vrai = percé.
 */
class AnimDecodeSecret extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'code' => EncryptedModuleData::class,
            'status' => 'boolean',
        ];
    }

    /** @return BelongsTo<AnimDecodeActivity, AnimDecodeSecret> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(AnimDecodeActivity::class, 'decode_activity_id');
    }

    /** @return HasMany<AnimDecodeProposal> */
    public function proposals(): HasMany
    {
        return $this->hasMany(AnimDecodeProposal::class, 'secret_id')->orderBy('id');
    }
}
