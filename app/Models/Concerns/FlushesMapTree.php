<?php

namespace App\Models\Concerns;

use App\Support\MapTree;

/**
 * Vide le cache de la carte (`MapTree`) à toute écriture Eloquent sur un royaume, une province
 * ou une ville. Sans lui, une modification resterait invisible jusqu'à l'expiration du TTL.
 */
trait FlushesMapTree
{
    public static function bootFlushesMapTree(): void
    {
        static::saved(fn () => MapTree::forget());
        static::deleted(fn () => MapTree::forget());
    }
}
