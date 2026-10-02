<?php

namespace App\Support;

use App\Models\City;
use App\Models\Kingdom;
use App\Models\Province;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;

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
 * ⚠️ Le cache SURVIT AU DÉPLOIEMENT (`storage/framework/` est exclu du FTP). La clé porte donc une
 * EMPREINTE des fichiers qui décident de la forme du tableau — celui-ci et les trois modèles
 * (`$casts`, accesseurs, `$hidden`…) : toute modification de l'un d'eux change la clé, et
 * l'ancienne forme cesse d'être servie sans que personne n'ait à y penser. Coût : une requête
 * lente après une modification, même cosmétique. Ce que l'empreinte ne voit pas — une colonne
 * ajoutée par migration, qui traverse parce que provinces et villes sont lues en `select *` — est
 * gardé par le test de forme de `tests/Feature/Api/MapTest.php`.
 *
 * L'ancienne clé n'est jamais effacée : le cache `file` ne supprime une entrée expirée qu'en la
 * relisant, et personne ne relit une clé abandonnée (~70 Ko par changement d'empreinte).
 * `php artisan cache:clear` en SSH fait le ménage.
 */
class MapTree
{
    public const CACHE_PREFIX = 'api.map.tree.';

    public const TTL_SECONDS = 7 * 24 * 3600;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get(): array
    {
        return Cache::remember(self::key(), self::TTL_SECONDS, fn () => Kingdom::with([
            'provinces' => function ($query) {
                $query->with(['cities' => function ($cities) {
                    $cities->orderByDesc('is_capital')->orderBy('city_name');
                }])->orderBy('province_name');
            },
        ])->orderBy('kingdom_name')->get(['id', 'kingdom_name'])->toArray());
    }

    public static function forget(): void
    {
        Cache::forget(self::key());
    }

    /**
     * `api.map.tree.` + empreinte des fichiers qui décident de la forme du tableau, calculée une
     * fois par requête (~2 ms par fichier mesurés sur le montage WSL du dev, le plus lent).
     */
    public static function key(): string
    {
        static $key = null;

        return $key ??= self::CACHE_PREFIX.self::fingerprint(self::shapeFiles());
    }

    /**
     * @param  array<int, string>  $files
     */
    public static function fingerprint(array $files): string
    {
        return substr(md5(implode('', array_map(fn (string $file) => md5_file($file), $files))), 0, 8);
    }

    /**
     * @return array<int, string>
     */
    public static function shapeFiles(): array
    {
        return array_map(
            fn (string $class) => (new ReflectionClass($class))->getFileName(),
            [self::class, Kingdom::class, Province::class, City::class],
        );
    }
}
