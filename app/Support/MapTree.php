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
 * Le cache porte le tableau, pas la réponse HTTP. (Le CORS n'y serait pas en danger : `HandleCors`
 * réécrit ses en-têtes après le contrôleur, vérifié le 02/10/2026 en cachant la réponse entière.)
 *
 * Invalidation sur les événements Eloquent des trois modèles (`FlushesMapTree`) — aucun écran
 * d'administration n'écrit ces tables, seul `MapSeeder` le fait, par `::create()`.
 *
 * TTL d'une SEMAINE, jamais de l'ordre d'une cadence de visite. À 1 h (back #39), le visiteur qui
 * arrivait après une heure de calme — le cas lent qui a ouvert l'enquête — trouvait toujours le
 * cache expiré : le gain n'existait que pour des appels rapprochés, qui n'étaient pas lents. La
 * semaine ne sert plus qu'à réparer seule une écriture hors Eloquent (SQL direct, `update()` de
 * masse), pour une requête lente par semaine.
 *
 * ⚠️ Le cache SURVIT AU DÉPLOIEMENT (`storage/framework/` est exclu du FTP). Toute modification de
 * la FORME du tableau (champ ajouté, retiré, renommé, autre tri) doit incrémenter la version de
 * `CACHE_KEY` : sinon l'ancienne forme reste servie jusqu'à une semaine au frontend déployé.
 */
class MapTree
{
    public const CACHE_KEY = 'api.map.tree.v1';

    public const TTL_SECONDS = 7 * 24 * 3600;

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
