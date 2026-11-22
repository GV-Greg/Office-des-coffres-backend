<?php

namespace App\Models;

use App\Casts\EncryptedModuleData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrée des listes rouge/verte d'une province (module Sécurité) : pseudo et raison CHIFFRÉS. Un retrait supprime la ligne. Aucune règle ici :
 * l'écriture vit dans App\Services\CustomsWatchlist.
 */
class CustomsWatchlistEntry extends Model
{
    public const RED = 'red';

    public const GREEN = 'green';

    public const COLOURS = [self::RED, self::GREEN];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'added_on' => 'date',
            'payload' => EncryptedModuleData::class.':array',
        ];
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'added_by_character_id');
    }

    public function pseudo(): string
    {
        return (string) ($this->payload['pseudo'] ?? '');
    }

    public function reason(): ?string
    {
        $reason = $this->payload['reason'] ?? null;

        return $reason === null || $reason === '' ? null : (string) $reason;
    }
}
