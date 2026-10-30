<?php

use App\Models\Character;
use App\Models\User;
use App\Notifications\EmailChanged;
use App\Notifications\VerifyEmailChange;
use Database\Seeders\PassportClientSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(PassportClientSeeder::class));

// ─── Changement d'email ───────────────────────────────────────────────────────

test('changement email : 401 sans authentification', function () {
    $this->patchJson('/api/v1/auth/account/email', [
        'current_password' => 'password123',
        'email'            => 'new@test.com',
    ])->assertStatus(401);
});

test('changement email : 403 si mot de passe incorrect', function () {
    $user = User::factory()->create(['email' => 'joueur@test.com']);
    Passport::actingAs($user);

    $this->patchJson('/api/v1/auth/account/email', [
        'current_password' => 'mauvais',
        'email'            => 'new@test.com',
    ])->assertStatus(403);
});

test('changement email : 422 si le nouvel email est déjà pris', function () {
    User::factory()->create(['email' => 'pris@test.com']);
    $user = User::factory()->create(['email' => 'joueur@test.com']);
    Passport::actingAs($user);

    $this->patchJson('/api/v1/auth/account/email', [
        'current_password' => 'password',
        'email'            => 'pris@test.com',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

test('changement email : 422 si le nouvel email est identique à l\'actuel', function () {
    $user = User::factory()->create(['email' => 'joueur@test.com']);
    Passport::actingAs($user);

    $this->patchJson('/api/v1/auth/account/email', [
        'current_password' => 'password',
        'email'            => 'joueur@test.com',
    ])->assertStatus(422);
});

test('changement email : succès — pending_email renseigné, email inchangé, vérification envoyée', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'joueur@test.com']);
    Passport::actingAs($user);

    $this->patchJson('/api/v1/auth/account/email', [
        'current_password' => 'password',
        'email'            => 'nouveau@test.com',
    ])->assertStatus(200)->assertJsonPath('success', true);

    $user->refresh();
    expect($user->email)->toBe('joueur@test.com');
    expect($user->pending_email)->toBe('nouveau@test.com');

    Notification::assertSentTo($user, VerifyEmailChange::class);
});

test('changement email : confirmation avec lien valide — email mis à jour, tokens révoqués, notification envoyée', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@test.com', 'pending_email' => 'new@test.com']);

    // Simuler un token actif
    $token = $user->createToken('test')->token ?? $user->createToken('test');
    Passport::actingAs($user);

    $url = URL::temporarySignedRoute('account.email.verify', now()->addHour(), [
        'id'   => $user->id,
        'hash' => sha1('new@test.com'),
    ]);
    // Extraire le chemin /api/v1/account/email/verify/…
    $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

    $this->getJson($path)->assertStatus(200)->assertJsonPath('success', true);

    $user->refresh();
    expect($user->email)->toBe('new@test.com');
    expect($user->pending_email)->toBeNull();
    expect($user->email_verified_at)->not->toBeNull();

    // Tous les tokens révoqués
    $revokedCount = Token::where('user_id', $user->id)->where('revoked', false)->count();
    expect($revokedCount)->toBe(0);

    // Notification envoyée à l'ancienne adresse
    Notification::assertSentTo(
        new AnonymousNotifiable,
        EmailChanged::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'old@test.com'
    );
});

test('changement email : confirmation avec lien invalide — 403', function () {
    $user = User::factory()->create(['email' => 'old@test.com', 'pending_email' => 'new@test.com']);

    $url = URL::temporarySignedRoute('account.email.verify', now()->addHour(), [
        'id'   => $user->id,
        'hash' => sha1('wrong@test.com'),  // hash incorrect
    ]);
    $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

    $this->getJson($path)->assertStatus(403);
});

test('changement email : rate limiting à 6 requêtes par minute', function () {
    $user = User::factory()->create(['email' => 'joueur@test.com']);
    Passport::actingAs($user);

    for ($i = 0; $i < 6; $i++) {
        $this->patchJson('/api/v1/auth/account/email', [
            'current_password' => 'password',
            'email'            => "email{$i}@test.com",
        ]);
    }

    $this->patchJson('/api/v1/auth/account/email', [
        'current_password' => 'password',
        'email'            => 'septieme@test.com',
    ])->assertStatus(429);
});

// ─── Changement de mot de passe ──────────────────────────────────────────────

test('changement mot de passe : 401 sans authentification', function () {
    $this->patchJson('/api/v1/auth/account/password', [
        'current_password'      => 'password',
        'password'              => 'nouveau123',
        'password_confirmation' => 'nouveau123',
    ])->assertStatus(401);
});

test('changement mot de passe : 403 si mot de passe actuel incorrect', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->patchJson('/api/v1/auth/account/password', [
        'current_password'      => 'mauvais',
        'password'              => 'nouveau123',
        'password_confirmation' => 'nouveau123',
    ])->assertStatus(403);
});

test('changement mot de passe : 422 si la confirmation ne correspond pas', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->patchJson('/api/v1/auth/account/password', [
        'current_password'      => 'password',
        'password'              => 'nouveau123',
        'password_confirmation' => 'different',
    ])->assertStatus(422)->assertJsonValidationErrors(['password_confirmation']);
});

test('changement mot de passe : 422 si le nouveau mot de passe est trop court', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->patchJson('/api/v1/auth/account/password', [
        'current_password'      => 'password',
        'password'              => 'court',
        'password_confirmation' => 'court',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);
});

test('changement mot de passe : succès — hash mis à jour, autres tokens révoqués', function () {
    $user = User::factory()->create();
    // Créer un autre token simulant une autre session (avant de s'authentifier)
    $otherToken = $user->createToken('autre-session');
    Passport::actingAs($user);

    $this->patchJson('/api/v1/auth/account/password', [
        'current_password'      => 'password',
        'password'              => 'nouveau_password123',
        'password_confirmation' => 'nouveau_password123',
    ])->assertStatus(200)->assertJsonPath('success', true);

    $user->refresh();

    // Vérifier que le hash a changé
    expect(\Illuminate\Support\Facades\Hash::check('nouveau_password123', $user->password))->toBeTrue();

    // L'autre token est révoqué (Passport::actingAs() n'a pas de DB token,
    // donc tous les tokens existants sont révoqués — comportement correct)
    $other = Token::where('id', $otherToken->token->id)->first();
    expect($other?->revoked)->toBeTrue();
});

test('changement mot de passe : rate limiting à 6 requêtes par minute', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    for ($i = 0; $i < 6; $i++) {
        $this->patchJson('/api/v1/auth/account/password', [
            'current_password'      => 'mauvais',
            'password'              => 'nouveau123',
            'password_confirmation' => 'nouveau123',
        ]);
    }

    $this->patchJson('/api/v1/auth/account/password', [
        'current_password'      => 'mauvais',
        'password'              => 'nouveau123',
        'password_confirmation' => 'nouveau123',
    ])->assertStatus(429);
});

// ─── Correction du pseudo par l'admin ────────────────────────────────────────

test('correction pseudo : 403 si non-admin', function () {
    $user = User::factory()->create();
    $character = Character::factory()->create(['user_id' => $user->id, 'city_id' => null]);
    Passport::actingAs($user);

    $this->patchJson("/api/v1/admin/characters/{$character->id}/pseudo", [
        'pseudo' => 'NouveauPseudo',
    ])->assertStatus(403);
});

test('correction pseudo : 422 si le pseudo est déjà pris', function () {
    Character::factory()->create(['pseudo' => 'DejaUtilise', 'city_id' => null]);
    $admin = User::factory()->create();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    $character = Character::factory()->create(['user_id' => $admin->id, 'city_id' => null]);
    Passport::actingAs($admin);

    $this->patchJson("/api/v1/admin/characters/{$character->id}/pseudo", [
        'pseudo' => 'DejaUtilise',
    ])->assertStatus(422)->assertJsonValidationErrors(['pseudo']);
});

test('correction pseudo : succès par un admin', function () {
    $admin = User::factory()->create();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    $character = Character::factory()->create(['user_id' => $admin->id, 'city_id' => null]);
    Passport::actingAs($admin);

    $this->patchJson("/api/v1/admin/characters/{$character->id}/pseudo", [
        'pseudo' => 'PseudoCorrige',
    ])->assertStatus(200)->assertJsonPath('character.pseudo', 'PseudoCorrige');

    expect($character->fresh()->pseudo)->toBe('PseudoCorrige');
});
