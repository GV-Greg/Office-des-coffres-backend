<?php

namespace App\Support;

use App\Models\Kingdom;
use Illuminate\Support\Facades\Cache;

/**
 * Arbre royaumes → provinces → villes servi par `GET /api/v1/map`, mis en cache.
 *
 * Données de référence identiques d'un appel à l'autre (69 938 octets les 13 fois relevées le
 * 02/10/2026), alors que leur calcul coûtait de 0,43 à 8,83 s d'attente serveur en prod.
 *
 * ⚠️ Le cache porte le TABLEAU, jamais la réponse HTTP : une réponse en cache emporterait l'en-tête
 * CORS calculé pour l'appelant du moment et le servirait à tous les suivants.
 *
 * Invalidation sur les événements Eloquent des trois modèles (`FlushesMapTree`) — aucun écran
 * d'administration n'écrit ces tables, seul `MapSeeder` le fait. Le TTL est le filet des
 * écritures qui contournent Eloquent (requête SQL directe, `update()` de masse).
 */
class MapTree
{
    public const CACHE_KEY = 'api.map.tree';

    public const TTL_SECONDS = 3600;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, fn () => Kingdom::with([
            'provinces' => function ($query) {
                $query->with(['cities' => function ($cities) {
                    $cities->orderByDesc('is_capital')->orderBy('city_name');
                }])->orderBy('province_name');
            },
        ])->orderBy('kingdom_name')->get(['id', 'kingdom_name'])->toArray());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
