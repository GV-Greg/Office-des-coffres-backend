<?php

use App\Console\Commands\CheckModuleData;

/*
| Non-consultation par l'éditeur (donnees-utilisateur.md §5bis) — inventaires.
|
| Les réglages d'inventaire (personnage, ville, province), le dernier inventaire collé et l'historique des inventaires d'une
| province ne sont lisibles que par leur propriétaire ou les titulaires du poste, via l'API. AUCUNE surface d'administration
| (contrôleur, vue Blade, route admin) ne référence ces tables ni ces modèles : ce test échoue si l'une le fait. La promesse
| faite aux joueurs cesse d'être une intention, elle devient une contrainte que la CI fait respecter.
*/

const INVENTORY_TABLES = [
    'character_inventory_settings', 'city_inventory_settings', 'province_inventory_settings', 'province_inventory_snapshots',
];

const INVENTORY_MODELS = [
    'CharacterInventorySettings', 'CityInventorySettings', 'ProvinceInventorySettings', 'ProvinceInventorySnapshot',
    'InventorySettings', 'InventoryText', 'ProvinceInventoryRegistry',
];

function adminSurfaceFiles(): array
{
    $files = [base_path('routes/web.php')];
    foreach ([app_path('Http/Controllers/Web'), resource_path('views')] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && preg_match('/\.(php|blade\.php)$/', $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

test("aucune surface d'administration ne référence les tables ni les modèles d'inventaire", function () {
    $offenders = [];
    foreach (adminSurfaceFiles() as $path) {
        $code = file_get_contents($path);
        foreach ([...INVENTORY_TABLES, ...INVENTORY_MODELS] as $needle) {
            if (str_contains($code, $needle)) {
                $offenders[] = str_replace(base_path().'/', '', $path)." → $needle";
            }
        }
    }

    // Seule exception : la vue d'export du compte (rendue pour le joueur lui-même, jamais pour l'administration).
    $offenders = array_values(array_filter($offenders, fn ($o) => ! str_starts_with($o, 'resources/views/exports/')));

    expect($offenders)->toBe([]);
});

test("les routes d'inventaire sont toutes derrière auth:api, aucune dans les routes admin (web)", function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), 'inventory'));

    expect($routes->count())->toBeGreaterThan(0);
    foreach ($routes as $route) {
        expect($route->uri())->toStartWith('api/v1/')
            ->and($route->gatherMiddleware())->toContain('auth:api')
            ->and($route->gatherMiddleware())->not->toContain('role:admin');
    }
});

test('les colonnes de texte collé sont déclarées chiffrées au contrôle du chiffrement', function () {
    $columns = CheckModuleData::ENCRYPTED_COLUMNS;

    expect($columns)->toMatchArray([
        'character_inventory_settings' => 'inventory',
        'city_inventory_settings' => 'inventory',
        'province_inventory_snapshots' => 'payload',
    ]);
});
