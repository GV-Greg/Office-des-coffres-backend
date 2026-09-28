<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

// Le limiteur 'api' était déclaré dans AppServiceProvider sans jamais être branché : 65 essais
// de mot de passe sur login donnaient 65 × 401 et aucun 429 (constat du 28/09/2026). Ces tests
// prouvent que le CODE est protégé, pas la prod — la vérification en prod se rejoue à la main
// (admin/content/brief-rate-limit.md).

// Compteurs du RateLimiter dans le cache : vidé explicitement, sinon l'ordre d'exécution
// déciderait du résultat si le store de test cessait un jour d'être recréé à chaque test.
beforeEach(fn () => Cache::flush());

function attemptLogin(string $email, string $ip = '10.0.0.1')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'mauvais-mot-de-passe']);
}

// --- login : couche (email, IP) ---

test('login répond 429 au-delà de 5 essais par minute pour un même email et une même IP', function () {
    User::factory()->create(['email' => 'joueur@test.com']);

    foreach (range(1, 5) as $i) {
        attemptLogin('joueur@test.com')->assertStatus(401);
    }

    attemptLogin('joueur@test.com')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', fn (string $message) => str_starts_with($message, 'Trop de tentatives.'));
});

test('la fenêtre se vide : une minute plus tard, un nouvel essai repasse', function () {
    User::factory()->create(['email' => 'joueur@test.com']);

    foreach (range(1, 5) as $i) {
        attemptLogin('joueur@test.com');
    }
    attemptLogin('joueur@test.com')->assertStatus(429);

    $this->travel(61)->seconds();

    attemptLogin('joueur@test.com')->assertStatus(401);
});

test('un email inexistant est limité exactement comme un email existant', function () {
    User::factory()->create(['email' => 'inscrit@test.com']);

    foreach (['inscrit@test.com' => '10.0.0.1', 'inconnu@test.com' => '10.0.0.2'] as $email => $ip) {
        foreach (range(1, 5) as $i) {
            attemptLogin($email, $ip)
                ->assertStatus(401)
                ->assertExactJson(['success' => false, 'message' => 'Identifiants incorrects.']);
        }
        attemptLogin($email, $ip)->assertStatus(429);
    }
});

test('la casse de l\'email ne permet pas de contourner la limite', function () {
    foreach (['joueur@test.com', 'Joueur@test.com', 'JOUEUR@test.com', 'joueur@TEST.com', 'JoUeUr@test.com'] as $email) {
        attemptLogin($email)->assertStatus(401);
    }

    attemptLogin('joueur@test.com')->assertStatus(429);
});

test('la limite (email, IP) ne bloque pas le même compte depuis une autre IP', function () {
    foreach (range(1, 6) as $i) {
        attemptLogin('joueur@test.com', '10.0.0.1');
    }

    attemptLogin('joueur@test.com', '10.0.0.2')->assertStatus(401);
});

// --- login : couche IP seule ---

test('login répond 429 au-delà de 30 essais par minute depuis une IP, même en changeant d\'email', function () {
    foreach (range(1, 30) as $i) {
        attemptLogin("compte{$i}@test.com")->assertStatus(401);
    }

    attemptLogin('compte31@test.com')->assertStatus(429);
});

// --- login : oracle temporel ---

test('un email inexistant paie le même Hash::check qu\'un email existant', function () {
    Hash::spy();

    attemptLogin('inconnu@test.com')->assertStatus(401);

    Hash::shouldHaveReceived('check')->once();
});

// --- register, refresh ---

test('register répond 429 au-delà de 5 essais par minute depuis une IP', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/register', ['email' => 'invalide'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/register', ['email' => 'invalide'])->assertStatus(429);
});

test('refresh répond 429 au-delà de 20 essais par minute depuis une IP', function () {
    foreach (range(1, 20) as $i) {
        $this->postJson('/api/v1/auth/refresh', [])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/refresh', [])->assertStatus(429);
});

// --- plancher de toute l'API ---

test('toute route d\'API est limitée à 60 requêtes par minute et par IP', function () {
    foreach (range(1, 60) as $i) {
        $this->getJson('/api/v1/map')->assertOk();
    }

    $this->getJson('/api/v1/map')->assertStatus(429);
});
