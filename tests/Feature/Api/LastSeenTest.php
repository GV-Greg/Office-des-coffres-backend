<?php

use App\Http\Middleware\RecordLastSeen;
use App\Models\User;
use Database\Seeders\PassportClientSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

// Mesure de l'inactivité (brief-politique-promesses §2.1-2.2 ; fil admin/echanges/politique-promesses,
// E1, Q2, Q7). « Connexion » = toute requête authentifiée, API comme panneau Blade.

beforeEach(function () {
    $this->seed(PassportClientSeeder::class);
    Carbon::setTestNow('2026-10-04 10:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function seenAt(User $user): ?string
{
    return $user->fresh()->last_seen_at?->toDateTimeString();
}

// --- Migration ---

test('la migration remplit les comptes existants à sa propre date, jamais à created_at', function () {
    $migration = require database_path('migrations/2026_10_04_000001_add_last_seen_at_to_users_table.php');
    $migration->down();

    DB::table('users')->insert([
        'email' => 'ancien@test.com', 'password' => 'x',
        'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00',
    ]);

    $migration->up();

    $row = DB::table('users')->where('email', 'ancien@test.com')->first();
    expect($row->last_seen_at)->toBe('2026-10-04 10:00:00')
        ->and($row->deletion_notice_sent_at)->toBeNull();
});

// --- Requêtes authentifiées ---

test('une requête API authentifiée écrit le passage, sans toucher updated_at', function () {
    $user = User::factory()->create(['last_seen_at' => '2026-09-01 12:00:00', 'updated_at' => '2026-09-01 12:00:00']);

    Passport::actingAs($user);
    $this->getJson('/api/v1/auth/me')->assertOk();

    expect(seenAt($user))->toBe('2026-10-04 10:00:00')
        ->and($user->fresh()->updated_at->toDateTimeString())->toBe('2026-09-01 12:00:00');
});

test('une requête authentifiée du panneau Blade compte aussi', function () {
    $user = User::factory()->create(['last_seen_at' => '2026-09-01 12:00:00']);

    $this->actingAs($user)->get('/profile')->assertOk();

    expect(seenAt($user))->toBe('2026-10-04 10:00:00');
});

test('au plus une écriture par jour : un passage déjà daté d\'aujourd\'hui n\'est pas réécrit', function () {
    $user = User::factory()->create(['last_seen_at' => '2026-10-04 08:00:00']);

    Passport::actingAs($user);
    $this->getJson('/api/v1/auth/me')->assertOk();

    expect(seenAt($user))->toBe('2026-10-04 08:00:00');
});

test('le passage de la veille est réécrit', function () {
    $user = User::factory()->create(['last_seen_at' => '2026-10-03 23:59:00']);

    Passport::actingAs($user);
    $this->getJson('/api/v1/auth/me')->assertOk();

    expect(seenAt($user))->toBe('2026-10-04 10:00:00');
});

test('un retour annule le préavis en cours, même le jour où il est parti', function () {
    $user = User::factory()->create([
        'last_seen_at' => '2026-10-04 08:00:00',
        'deletion_notice_sent_at' => '2026-10-04 09:00:00',
    ]);

    Passport::actingAs($user);
    $this->getJson('/api/v1/auth/me')->assertOk();

    expect($user->fresh()->deletion_notice_sent_at)->toBeNull();
});

test('une requête publique n\'écrit rien', function () {
    $user = User::factory()->create(['last_seen_at' => '2026-09-01 12:00:00']);

    $this->getJson('/api/v1/map')->assertOk();

    expect(seenAt($user))->toBe('2026-09-01 12:00:00');
});

test('l\'écriture a lieu après la réponse (terminate), pas pendant', function () {
    $user = User::factory()->create(['last_seen_at' => '2026-09-01 12:00:00']);
    Auth::setUser($user);

    $middleware = new RecordLastSeen;
    $request = Request::create('/api/v1/auth/me');
    $response = $middleware->handle($request, fn () => new Response);

    expect(seenAt($user))->toBe('2026-09-01 12:00:00');

    $middleware->terminate($request, $response);

    expect(seenAt($user))->toBe('2026-10-04 10:00:00');
});

// --- Routes publiques qui authentifient (E1 : hors du groupe auth:api) ---

test('l\'inscription écrit le passage : aucune ligne ne reste à last_seen_at nul', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', [
        'email' => 'nouveau@test.com', 'password' => 'password123', 'confirmation' => 'password123',
    ])->assertCreated();

    expect(seenAt(User::where('email', 'nouveau@test.com')->first()))->toBe('2026-10-04 10:00:00');
});

test('le lien de vérification d\'email écrit le passage', function () {
    $user = User::factory()->unverified()->create(['last_seen_at' => '2026-09-01 12:00:00']);

    $this->get(URL::temporarySignedRoute('verification.verify.api', now()->addHour(), [
        'id' => $user->id, 'hash' => sha1($user->getEmailForVerification()),
    ]))->assertRedirect();

    expect(seenAt($user))->toBe('2026-10-04 10:00:00');
});

test('la connexion écrit le passage, une tentative refusée non', function () {
    $user = User::factory()->create(['password' => bcrypt('password123'), 'last_seen_at' => '2026-09-01 12:00:00']);

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'mauvais'])->assertStatus(401);
    expect(seenAt($user))->toBe('2026-09-01 12:00:00');

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123'])->assertOk();
    expect(seenAt($user))->toBe('2026-10-04 10:00:00');
});

test('un rafraîchissement de jeton seul écrit le passage', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);
    $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123']);

    $user->forceFill(['last_seen_at' => '2026-09-01 12:00:00'])->save();
    app('auth')->forgetGuards(); // le garde garderait sinon l'utilisateur de la requête précédente

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $login->json('refresh_token')])->assertOk();

    expect(seenAt($user))->toBe('2026-10-04 10:00:00');
});
