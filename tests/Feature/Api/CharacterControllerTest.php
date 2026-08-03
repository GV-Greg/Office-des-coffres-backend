<?php

use App\Models\City;
use App\Models\User;

test('un utilisateur connecté peut créer un personnage', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/characters', [
        'pseudo'  => 'Artifice',
        'city_id' => $city->id,
    ]);

    $response->assertStatus(201)
             ->assertJsonPath('success', true)
             ->assertJsonPath('character.pseudo', 'Artifice')
             ->assertJsonPath('character.is_validated', false);

    $this->assertDatabaseHas('characters', [
        'user_id' => $user->id,
        'pseudo'  => 'Artifice',
        'city_id' => $city->id,
    ]);
});

test('un utilisateur peut créer plusieurs personnages', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/characters', ['pseudo' => 'Artifice', 'city_id' => $city->id]);
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/characters', ['pseudo' => 'Buldo', 'city_id' => $city->id]);

    expect($user->characters()->count())->toBe(2);
});

test('la création de personnage échoue sans authentification', function () {
    $city = City::factory()->create();

    $this->postJson('/api/v1/characters', ['pseudo' => 'Artifice', 'city_id' => $city->id])
        ->assertStatus(401);
});

test('la création de personnage échoue si le pseudo est déjà pris', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();
    $user->characters()->create(['pseudo' => 'Artifice', 'city_id' => $city->id, 'is_validated' => false]);

    $other = User::factory()->create();

    $this->actingAs($other, 'sanctum')->postJson('/api/v1/characters', [
        'pseudo'  => 'Artifice',
        'city_id' => $city->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['pseudo']);
});

test('la création de personnage échoue si la ville n\'existe pas', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/characters', [
        'pseudo'  => 'Artifice',
        'city_id' => 999999,
    ])->assertStatus(422)->assertJsonValidationErrors(['city_id']);
});

test('un utilisateur peut lister ses personnages', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();
    $user->characters()->create(['pseudo' => 'Artifice', 'city_id' => $city->id, 'is_validated' => true]);
    $user->characters()->create(['pseudo' => 'Buldo', 'city_id' => $city->id, 'is_validated' => false]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/characters');

    $response->assertOk()->assertJsonPath('success', true);
    expect($response->json('characters'))->toHaveCount(2);
});

test('la liste des personnages échoue sans authentification', function () {
    $this->getJson('/api/v1/characters')->assertStatus(401);
});
