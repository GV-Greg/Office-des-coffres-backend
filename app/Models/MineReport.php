<?php

namespace App\Models;

use App\Casts\EncryptedModuleData;
use App\Services\MandateAuthority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relevé du Registre des mines (brief admin/content/brief-registre-mines.md). Axes en clair,
 * `payload` chiffré par MODULE_DATA_KEY. Ajout seul : jamais de suppression, un remplacement —
 * `active` = true en vigueur, NULL remplacé (voir la migration). L'écriture passera par un service
 * unique (PR 1b) ; ce modèle ne porte aucune règle.
 */
class MineReport extends Model
{
    /** Postes qui tiennent le registre : une seule liste, celle de l'autorité. */
    public const OFFICES = MandateAuthority::MINE_OFFICES;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reported_at' => 'date',
            'payload' => EncryptedModuleData::class.':array',
            'active' => 'boolean',
            'replaced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Province, MineReport> */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /** Auteur, NULL une fois son personnage supprimé (catégorie C). @return BelongsTo<Character, MineReport> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** @return BelongsTo<MineReport, MineReport> */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }
}
