<?php

use App\Models\Character;
use App\Models\User;

// --- Register ---

test('un utilisateur peut s\'inscrire', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'username'     => 'Artifice',
        'email'        => 'artifice@test.com',
        'password'     => 'password123',
        'confirmation' => 'password123',
    ]);

    $response->assertStatus(201)
             ->assertJsonStructure(['success', 'token', 'user' => ['id', 'email', 'pseudo', 'is_validated']])
             ->assertJsonPath('success', true)
             ->assertJsonPath('user.pseudo', 'Artifice')
             ->assertJsonPath('user.is_validated', false);

    $this->assertDatabaseHas('users', ['email' => 'artifice@test.com']);
    $this->assertDatabaseHas('characters', ['pseudo' => 'Artifice', 'is_validated' => false]);
});

test('l\'inscription échoue si le pseudo est déjà pris', function () {
    Character::factory()->create(['pseudo' => 'Artifice']);

    $this->postJson('/api/v1/auth/register', [
        'username'     => 'Artifice',
        'email'        => 'autre@test.com',
        'password'     => 'password123',
        'confirmation' => 'password123',
    ])->assertStatus(422)->assertJsonPath('errors.username.0', fn ($msg) => str_contains($msg, 'déjà'));
});

test('l\'inscription échoue si l\'email est déjà utilisé', function () {
    User::factory()->create(['email' => 'pris@test.com']);

    $this->postJson('/api/v1/auth/register', [
        'username'     => 'Nouveau',
        'email'        => 'pris@test.com',
        'password'     => 'password123',
        'confirmation' => 'password123',
    ])->assertStatus(422)->assertJsonPath('errors.email.0', fn ($msg) => str_contains($msg, 'déjà'));
});

test('l\'inscription échoue si la confirmation ne correspond pas', function () {
    $this->postJson('/api/v1/auth/register', [
        'username'     => 'TestUser',
        'email'        => 'test@test.com',
        'password'     => 'password123',
        'confirmation' => 'different',
    ])->assertStatus(422)->assertJsonValidationErrors(['confirmation']);
});

test('l\'inscription échoue si le mot de passe est trop court', function () {
    $this->postJson('/api/v1/auth/register', [
        'username'     => 'TestUser',
        'email'        => 'test@test.com',
        'password'     => 'court',
        'confirmation' => 'court',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);
});

// --- Login ---

test('un utilisateur peut se connecter avec son pseudo', function () {
    $user      = User::factory()->create(['password' => bcrypt('password123')]);
    $character = Character::factory()->create(['user_id' => $user->id, 'pseudo' => 'Buldo', 'is_validated' => true, 'city_id' => null]);

    $response = $this->postJson('/api/v1/auth/login', [
        'username' => 'Buldo',
        'password' => 'password123',
    ]);

    $response->assertOk()
             ->assertJsonStructure(['success', 'token', 'user' => ['id', 'email', 'pseudo', 'is_validated']])
             ->assertJsonPath('success', true)
             ->assertJsonPath('user.pseudo', 'Buldo')
             ->assertJsonPath('user.is_validated', true);
});

test('le login échoue avec un mauvais mot de passe', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);
    Character::factory()->create(['user_id' => $user->id, 'pseudo' => 'Buldo', 'city_id' => null]);

    $this->postJson('/api/v1/auth/login', [
        'username' => 'Buldo',
        'password' => 'mauvais',
    ])->assertStatus(401)->assertJsonPath('success', false);
});

test('le login échoue avec un pseudo inconnu', function () {
    $this->postJson('/api/v1/auth/login', [
        'username' => 'InexistantXYZ',
        'password' => 'password123',
    ])->assertStatus(401)->assertJsonPath('success', false);
});

// --- Me ---

test('un utilisateur authentifié peut récupérer son profil', function () {
    $user      = User::factory()->create();
    $character = Character::factory()->create(['user_id' => $user->id, 'pseudo' => 'Artifice', 'is_validated' => false, 'city_id' => null]);

    $this->actingAs($user, 'sanctum')
         ->getJson('/api/v1/auth/me')
         ->assertOk()
         ->assertJsonPath('success', true)
         ->assertJsonPath('user.pseudo', 'Artifice')
         ->assertJsonPath('user.is_validated', false);
});

test('/me retourne 401 sans token', function () {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

// --- Logout ---

test('un utilisateur peut se déconnecter', function () {
    $user  = User::factory()->create();
    $token = $user->createToken('api-token')->plainTextToken;

    $this->withToken($token)
         ->postJson('/api/v1/auth/logout')
         ->assertOk()
         ->assertJsonPath('success', true);
});
