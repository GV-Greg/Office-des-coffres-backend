<?php

use App\Models\City;
use App\Models\Kingdom;
use App\Models\Province;
use App\Support\MapTree;
use Illuminate\Support\Facades\DB;

test('la carte retourne les royaumes, provinces et villes imbriqués', function () {
    $kingdom = Kingdom::factory()->create(['kingdom_name' => 'Royaume de Test']);
    $province = Province::factory()->create(['kingdom_id' => $kingdom->id, 'province_name' => 'Province de Test']);
    City::factory()->create(['province_id' => $province->id, 'city_name' => 'Ville de Test']);

    $response = $this->getJson('/api/v1/map');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('kingdoms.0.kingdom_name', 'Royaume de Test')
        ->assertJsonPath('kingdoms.0.provinces.0.province_name', 'Province de Test')
        ->assertJsonPath('kingdoms.0.provinces.0.cities.0.city_name', 'Ville de Test');
});

test('la carte est accessible sans authentification', function () {
    $this->getJson('/api/v1/map')->assertOk();
});

// Cache de l'arbre (App\Support\MapTree). Un cache qui n'écrit pas retombe en silence sur le calcul :
// la réponse reste juste, seule la latence le trahirait. Ces tests comptent donc les requêtes SQL,
// et le premier appel doit en faire (contrôle positif : sinon le compteur ne mesure rien).
function mapQueryCount(): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->getJson('/api/v1/map')->assertOk();
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

function seedMap(): City
{
    $kingdom = Kingdom::factory()->create(['kingdom_name' => 'Royaume de Test']);
    $province = Province::factory()->create(['kingdom_id' => $kingdom->id, 'province_name' => 'Province de Test']);

    return City::factory()->create(['province_id' => $province->id, 'city_name' => 'Ville de Test']);
}

test('le second appel à la carte ne touche plus la base', function () {
    seedMap();

    expect(mapQueryCount())->toBeGreaterThan(0)
        ->and(mapQueryCount())->toBe(0);
});

test('une écriture Eloquent sur la carte vide le cache', function (Closure $write, string $path, string $expected) {
    $city = seedMap();
    mapQueryCount();

    $write($city);

    expect(mapQueryCount())->toBeGreaterThan(0);
    $this->getJson('/api/v1/map')->assertJsonPath($path, $expected);
})->with([
    'ville renommée' => [fn (City $c) => $c->update(['city_name' => 'Ville renommée']),
        'kingdoms.0.provinces.0.cities.0.city_name', 'Ville renommée'],
    'province renommée' => [fn (City $c) => $c->province->update(['province_name' => 'Province renommée']),
        'kingdoms.0.provinces.0.province_name', 'Province renommée'],
    'royaume ajouté' => [fn () => Kingdom::factory()->create(['kingdom_name' => 'Aaa premier royaume']),
        'kingdoms.0.kingdom_name', 'Aaa premier royaume'],
]);

test('une suppression sur la carte vide le cache', function () {
    $city = seedMap();
    mapQueryCount();

    $city->delete();

    expect(mapQueryCount())->toBeGreaterThan(0);
    $this->getJson('/api/v1/map')->assertJsonCount(0, 'kingdoms.0.provinces.0.cities');
});

// Le test du second appel immédiat passe avec n'importe quel TTL. Celui-ci porte sur la DURÉE : il
// échoue si le cache redescend à l'échelle d'une cadence de visite (1 h en back #39 : tout visiteur
// arrivé après une heure de calme retrouvait le calcul complet).
test('le cache de la carte survit à plusieurs jours sans visite', function () {
    seedMap();
    mapQueryCount();

    $this->travel(3)->days();
    expect(mapQueryCount())->toBe(0);
});

test('le cache de la carte se renouvelle au bout d\'une semaine', function () {
    seedMap();
    mapQueryCount();

    $this->travel(6 * 24 + 23)->hours();
    expect(mapQueryCount())->toBe(0);

    $this->travel(2)->hours();
    expect(mapQueryCount())->toBeGreaterThan(0);
});

// Forme du tableau servi par /api/v1/map. Le cache survit au déploiement et sa clé n'est empreintée
// que sur MapTree.php et les trois modèles : une colonne ajoutée par MIGRATION traverse (provinces
// et villes sont lues en select *) sans changer la clé. Si ce test casse pour cette raison, mettre
// à jour les clés attendues ET vider le cache en prod après le déploiement :
// `php artisan cache:clear` en SSH — sinon l'ancienne forme reste servie jusqu'à 7 jours.
test('la forme du tableau de la carte est celle attendue par le frontend', function () {
    seedMap();

    $tree = $this->getJson('/api/v1/map')->json('kingdoms');

    expect(array_keys($tree[0]))->toBe(['id', 'kingdom_name', 'provinces'])
        ->and(array_keys($tree[0]['provinces'][0]))
        ->toBe(['id', 'kingdom_id', 'province_name', 'created_at', 'updated_at', 'cities'])
        ->and(array_keys($tree[0]['provinces'][0]['cities'][0]))
        ->toBe(['id', 'province_id', 'city_name', 'is_capital', 'created_at', 'updated_at']);
});

test('la clé de cache couvre MapTree et les trois modèles de la carte', function () {
    expect(MapTree::shapeFiles())->toBe([
        app_path('Support/MapTree.php'),
        app_path('Models/Kingdom.php'),
        app_path('Models/Province.php'),
        app_path('Models/City.php'),
    ]);
});

test('l\'empreinte de la clé change dès qu\'un des fichiers change', function () {
    $dir = sys_get_temp_dir().'/maptree-'.uniqid();
    mkdir($dir);
    $files = array_map(function (string $source) use ($dir) {
        $copy = $dir.'/'.basename($source);
        copy($source, $copy);

        return $copy;
    }, MapTree::shapeFiles());

    $before = MapTree::fingerprint($files);
    expect(MapTree::fingerprint($files))->toBe($before);

    file_put_contents($files[2], "\n// une retouche, même cosmétique\n", FILE_APPEND);
    expect(MapTree::fingerprint($files))->not->toBe($before);

    array_map('unlink', $files);
    rmdir($dir);
});
