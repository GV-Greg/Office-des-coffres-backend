<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jeu de prix des mines d'une province, en vigueur à partir de `effective_from` (brief
 * admin/content/brief-prix-registre.md). Aucune règle ici : l'autorisation vit dans
 * MandateAuthority, l'écriture dans App\Services\MinePrices.
 */
class ProvinceMinePrice extends Model
{
    use HasFactory;

    /** Ressource du jeu → colonne. Une seule table de correspondance. */
    public const COLUMNS = [
        'OR' => 'price_or',
        'FER' => 'price_fer',
        'PIERRE' => 'price_pierre',
        'ARGILE' => 'price_argile',
        'SEL' => 'price_sel',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'price_or' => 'float',
            'price_fer' => 'float',
            'price_pierre' => 'float',
            'price_argile' => 'float',
            'price_sel' => 'float',
            'hourly_rate' => 'float',
        ];
    }

    /** @return BelongsTo<Province, ProvinceMinePrice> */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /** Auteur, NULL une fois son personnage supprimé (catégorie C). @return BelongsTo<Character, ProvinceMinePrice> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'created_by_character_id');
    }

    /** @return BelongsTo<Character, ProvinceMinePrice> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'updated_by_character_id');
    }

    /** @return array<string, float> ressource → prix */
    public function prices(): array
    {
        return collect(self::COLUMNS)->map(fn (string $column) => $this->{$column})->all();
    }
}
