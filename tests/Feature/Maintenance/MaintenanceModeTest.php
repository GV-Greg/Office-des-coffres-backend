<?php

// Mode maintenance (18/11/2026).
//
// Le fichier `storage/framework/down` est celui de `artisan down` : on l'écrit par le même
// mécanisme (`maintenanceMode()->activate`) et on le retire après chaque test.

use App\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

const MAINTENANCE_SECRET = 'secret-de-test-0123456789';

function closeTheOffice(array $overrides = []): void
{
    app()->maintenanceMode()->activate(array_merge([
        'except' => [],
        'redirect' => null,
        'retry' => 300,
        'refresh' => null,
        'secret' => MAINTENANCE_SECRET,
        'status' => 503,
        'template' => null,
    ], $overrides));
}

afterEach(function () {
    if (app()->maintenanceMode()->active()) {
        app()->maintenanceMode()->deactivate();
    }
});

test('ouvert, l\'API répond normalement', function () {
    $this->getJson('/api/v1/map')->assertOk();
});

test('fermé, l\'API répond 503 en JSON avec Retry-After', function () {
    closeTheOffice();

    $this->getJson('/api/v1/map')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '300')
        ->assertExactJson(['success' => false, 'message' => 'maintenance', 'retry_after' => 300]);
});

test('le 503 JSON porte les en-têtes CORS : sans eux le site ne verrait qu\'une erreur réseau', function () {
    config(['cors.allowed_origins' => ['https://officedescoffres.creacube.be']]);
    closeTheOffice();

    $this->getJson('/api/v1/map', ['Origin' => 'https://officedescoffres.creacube.be'])
        ->assertStatus(503)
        ->assertHeader('Access-Control-Allow-Origin', 'https://officedescoffres.creacube.be');
});

test('le préflight CORS passe aussi, l\'en-tête de bypass y est accepté', function () {
    config(['cors.allowed_origins' => ['https://officedescoffres.creacube.be']]);
    closeTheOffice();

    $this->call('OPTIONS', '/api/v1/auth/me', server: [
        'HTTP_ORIGIN' => 'https://officedescoffres.creacube.be',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,x-maintenance-bypass',
    ])->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://officedescoffres.creacube.be');
});

test('fermé, l\'en-tête de bypass donne accès à l\'API (le site des joueurs n\'a pas de cookie)', function () {
    closeTheOffice();

    $this->getJson('/api/v1/map', [PreventRequestsDuringMaintenance::BYPASS_HEADER => MAINTENANCE_SECRET])
        ->assertOk();
});

test('un mauvais secret, ou un secret vide, ne passe pas', function () {
    closeTheOffice();

    foreach (['mauvais-secret', MAINTENANCE_SECRET.'x', ''] as $given) {
        $this->getJson('/api/v1/map', [PreventRequestsDuringMaintenance::BYPASS_HEADER => $given])
            ->assertStatus(503);
    }
});

test('un fichier sans secret ne se laisse jamais contourner par un en-tête vide', function () {
    closeTheOffice(['secret' => null]);

    $this->getJson('/api/v1/map', [PreventRequestsDuringMaintenance::BYPASS_HEADER => ''])->assertStatus(503);
    $this->getJson('/api/v1/map')->assertStatus(503);
});

test('le bypass natif de Laravel (/{secret}, cookie) reste en place pour le panneau Blade', function () {
    closeTheOffice();

    $response = $this->get('/'.MAINTENANCE_SECRET);

    $response->assertRedirect();
    expect($response->headers->getCookies())->not->toBeEmpty();
});

test('hors API, la réponse n\'est pas le JSON de l\'API (panneau Blade : page 503 standard)', function () {
    closeTheOffice();

    $response = $this->get('/login');

    $response->assertStatus(503);
    expect($response->headers->get('Content-Type'))->not->toContain('json');
});

test('le fichier que produit scripts/maintenance.sh est accepté tel quel par Laravel', function () {
    $json = shell_exec('MAINTENANCE_SECRET='.escapeshellarg(MAINTENANCE_SECRET).' bash '.escapeshellarg(base_path('scripts/maintenance.sh')).' payload');

    $data = json_decode((string) $json, true);
    expect($data)->toBeArray()
        ->and($data['secret'])->toBe(MAINTENANCE_SECRET)
        ->and($data['status'])->toBe(503);

    file_put_contents(storage_path('framework/down'), $json);

    $this->getJson('/api/v1/map')->assertStatus(503)->assertHeader('Retry-After', '300');
    $this->getJson('/api/v1/map', [PreventRequestsDuringMaintenance::BYPASS_HEADER => MAINTENANCE_SECRET])->assertOk();
});

test('le script refuse un secret qui casserait le JSON ou l\'URL', function () {
    foreach (['', 'court', 'a"b-0123456789-0123', 'avec espace 0123456789'] as $secret) {
        $out = shell_exec('MAINTENANCE_SECRET='.escapeshellarg($secret).' bash '.escapeshellarg(base_path('scripts/maintenance.sh')).' payload 2>&1');

        expect($out)->toContain('MAINTENANCE_SECRET');
    }
});

test('le déploiement ferme l\'Office avant l\'envoi et le rouvre après', function () {
    $deploy = file_get_contents(base_path('.github/workflows/deploy.yml'));

    $close = strpos($deploy, 'scripts/maintenance.sh down');
    $upload = strpos($deploy, 'uses: SamKirkland/FTP-Deploy-Action');
    $reopen = strpos($deploy, 'scripts/maintenance.sh up');
    $probes = strpos($deploy, 'Vérifier la redirection vers l\'origine canonique');

    expect($close)->toBeInt()->toBeLessThan($upload)
        ->and($reopen)->toBeInt()->toBeGreaterThan($upload)->toBeLessThan($probes);

    // Un Office déjà fermé reste fermé : les sondes (200 attendu) sont alors ignorées.
    expect($deploy)->toContain("steps.maintenance.outputs.mode != 'held'");
});

// Incident du 18/11/2026 (back #89) : un `: ` non protégé dans un `run: echo "…"` rendait deploy.yml
// invalide — GitHub rejette alors le workflow sans lancer aucun job, et plus aucun push sur main ne
// se déploie. Ce que la CI ne voit pas, ce test le voit.
test('les workflows se lisent comme du YAML valide, avec les étapes de maintenance', function () {
    foreach (['deploy', 'maintenance', 'tests'] as $name) {
        $workflow = Yaml::parseFile(base_path(".github/workflows/{$name}.yml"));

        expect($workflow)->toBeArray()->toHaveKey('jobs');
    }

    $steps = collect(Yaml::parseFile(base_path('.github/workflows/deploy.yml'))['jobs']['build-and-deploy']['steps'])->pluck('name');

    expect($steps->all())->toContain('Fermer l\'Office (mode maintenance)', 'Rouvrir l\'Office', 'Deploy files via FTP');
});

// Accès administrateur sur la page 503 du panneau Blade (Greg, 18/11/2026).
test('pendant une maintenance, la page 503 du panneau propose l\'accès administrateur', function () {
    closeTheOffice();

    $response = $this->get('/login');

    $response->assertStatus(503)
        ->assertSee('Maintenance en cours')
        ->assertSee('Accès administrateur')
        ->assertSee('Secret de maintenance')
        // Le secret n'est écrit nulle part dans la page : le navigateur l'ajoute à l'adresse.
        ->assertDontSee(MAINTENANCE_SECRET);
    expect($response->headers->get('Content-Type'))->toContain('text/html');
});

test('un 503 qui n\'est pas une maintenance n\'a ni formulaire ni secret à saisir', function () {
    Route::get('/_503-hors-maintenance', fn () => abort(503));

    $this->get('/_503-hors-maintenance')
        ->assertStatus(503)
        ->assertSee('Service indisponible')
        ->assertDontSee('Accès administrateur')
        ->assertDontSee('maintenance-bypass');
});

test('l\'adresse /{secret} saisie dans le formulaire pose le cookie et ouvre le panneau', function () {
    closeTheOffice();

    $this->get('/'.MAINTENANCE_SECRET)->assertRedirect();

    $cookie = collect($this->get('/'.MAINTENANCE_SECRET)->headers->getCookies())->first(fn ($c) => $c->getName() === 'laravel_maintenance');
    expect($cookie)->not->toBeNull();

    $this->withUnencryptedCookie('laravel_maintenance', $cookie->getValue())->get('/login')->assertOk();
});

test('la vue 503 n\'affecte pas l\'API : elle répond toujours en JSON', function () {
    closeTheOffice();

    $this->getJson('/api/v1/map')->assertStatus(503)->assertJson(['message' => 'maintenance']);
});
