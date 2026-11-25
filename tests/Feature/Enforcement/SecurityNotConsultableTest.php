<?php

/*
| Non-consultation par l'éditeur (donnees-utilisateur.md §5bis) — La Salle des gardes (module Sécurité).
|
| La politique de confidentialité écrit, pour les listes rouge/verte et les bilans de défense : « Aucune interface d'administration du
| site ne permet de consulter les listes ni les bilans : cette restriction est inscrite dans le code, vérifiée automatiquement à chaque
| livraison ». Ce test est cette vérification — il DOIT exister avant que le texte soit en ligne (validé le 22/11/2026, |, v2).
|
| ⚠️ NOMS RÉSERVÉS : les tables et les classes ci-dessous n'existent pas encore (PR 3-1). La PR 3-1 doit les créer sous ces noms, ou mettre
| ce test à jour AVANT de merger — sinon la promesse de la politique ne garderait plus rien. Le « contrôle positif » plus bas prouve que
| le détecteur voit bien un nom réservé : un test qui passe parce que rien n'existe ne protège pas.
|
| Hors périmètre, volontairement : `customs_officers` (statut de douanier) a sa propre page d'administration `/customs`, niveau 3 de la
| validation — elle ne montre que les douaniers, jamais une liste ni un bilan.
*/

const SECURITY_TABLES = ['customs_watchlist_entries', 'customs_reports'];

const SECURITY_CLASSES = ['CustomsWatchlist', 'CustomsReport'];

function securityNeedles(): array
{
    return [...SECURITY_TABLES, ...SECURITY_CLASSES];
}

/** @return array<int, string> les noms réservés présents dans ce texte */
function securityNamesIn(string $code): array
{
    return array_values(array_filter(securityNeedles(), fn (string $needle) => str_contains($code, $needle)));
}

function securityAdminSurfaceFiles(): array
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

test("aucune surface d'administration ne référence les tables ni les classes des listes et des bilans", function () {
    $offenders = [];
    foreach (securityAdminSurfaceFiles() as $path) {
        foreach (securityNamesIn(file_get_contents($path)) as $name) {
            $offenders[] = str_replace(base_path().'/', '', $path)." → $name";
        }
    }

    expect($offenders)->toBe([]);
});

test('contrôle positif : le détecteur voit un nom réservé dans un contrôleur ou une vue qui lirait une liste ou un bilan', function () {
    expect(securityNamesIn('CustomsReport::query()->get()'))->toBe(['CustomsReport'])
        ->and(securityNamesIn("DB::table('customs_watchlist_entries')"))->toBe(['customs_watchlist_entries'])
        ->and(securityNamesIn('$reports = new CustomsWatchlistEntry()'))->toBe(['CustomsWatchlist'])
        ->and(securityNamesIn('CustomsOfficer::query()->approved()->get()'))->toBe([]);
});

test('aucune route web (administration) ne touche aux listes ni aux bilans : /customs ne sert que les douaniers', function () {
    // + le retrait d'un pseudo (outil interne) : il ne sert que des NOMBRES, voir CustomsAdminTest.
    $allowed = ['customs', 'customs/{id}/approve', 'customs/{id}/reject', 'customs/{id}/revoke', 'customs/remove-pseudo', 'customs/remove-pseudo/apply'];

    $webCustoms = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => ! str_starts_with($route->uri(), 'api/') && str_starts_with($route->uri(), 'customs'))
        ->map(fn ($route) => $route->uri())
        ->unique()->values()->all();

    expect($webCustoms)->not->toBe([])
        ->and(array_diff($webCustoms, $allowed))->toBe([]);
});

test('les routes des listes et des bilans (quand elles existent) sont toutes derrière auth:api, aucune dans les routes admin', function () {
    $pattern = '#customs[-/](reports|watchlist|lists)#';

    // Contrôle positif du filtre : il voit les noms prévus pour la PR 3-1, jamais les douaniers.
    expect(preg_match($pattern, 'api/v1/characters/{character}/customs-reports'))->toBe(1)
        ->and(preg_match($pattern, 'api/v1/provinces/{province}/customs/watchlist'))->toBe(1)
        ->and(preg_match($pattern, 'api/v1/customs-officers/{id}/approve'))->toBe(0)
        ->and(preg_match($pattern, 'api/v1/characters/{character}/customs'))->toBe(0);

    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => preg_match($pattern, $route->uri()) === 1);

    foreach ($routes as $route) {
        expect($route->uri())->toStartWith('api/v1/')
            ->and($route->gatherMiddleware())->toContain('auth:api')
            ->and($route->gatherMiddleware())->not->toContain('role:admin');
    }
});
