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
             ->assertJsonPath('character.is_validated', false)
             ->assertJsonPath('character.city_name', $city->city_name)
             ->assertJsonPath('character.province_name', $city->province->province_name)
             ->assertJsonPath('character.kingdom_name', $city->province->kingdom->kingdom_name);

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
    expect($response->json('characters.0.city_name'))->toBe($city->city_name);
});

test('la liste des personnages échoue sans authentification', function () {
    $this->getJson('/api/v1/characters')->assertStatus(401);
});

test('un utilisateur peut changer la résidence de son personnage, ce qui repasse la validation à false', function () {
    $user = User::factory()->create();
    $oldCity = City::factory()->create();
    $newCity = City::factory()->create();
    $character = $user->characters()->create(['pseudo' => 'Artifice', 'city_id' => $oldCity->id, 'is_validated' => true]);

    $response = $this->actingAs($user, 'sanctum')->patchJson("/api/v1/characters/{$character->id}", [
        'city_id' => $newCity->id,
    ]);

    $response->assertOk()
             ->assertJsonPath('success', true)
             ->assertJsonPath('character.city_id', $newCity->id)
             ->assertJsonPath('character.city_name', $newCity->city_name)
             ->assertJsonPath('character.is_validated', false)
             ->assertJsonPath('character.pending_residence_change', true);

    $this->assertDatabaseHas('characters', [
        'id'                        => $character->id,
        'city_id'                   => $newCity->id,
        'is_validated'              => false,
        'pending_residence_change'  => true,
    ]);
});

test('un nouveau personnage n\'est pas marqué comme changement de résidence en attente', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/characters', [
        'pseudo'  => 'Artifice',
        'city_id' => $city->id,
    ]);

    $response->assertJsonPath('character.pending_residence_change', false);
});

test('un utilisateur ne peut pas changer la résidence du personnage d\'un autre', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $city = City::factory()->create();
    $newCity = City::factory()->create();
    $character = $owner->characters()->create(['pseudo' => 'Artifice', 'city_id' => $city->id, 'is_validated' => true]);

    $this->actingAs($other, 'sanctum')
        ->patchJson("/api/v1/characters/{$character->id}", ['city_id' => $newCity->id])
        ->assertStatus(404);

    $this->assertDatabaseHas('characters', ['id' => $character->id, 'city_id' => $city->id, 'is_validated' => true]);
});

test('le changement de résidence échoue si la ville n\'existe pas', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();
    $character = $user->characters()->create(['pseudo' => 'Artifice', 'city_id' => $city->id, 'is_validated' => true]);

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/v1/characters/{$character->id}", ['city_id' => 999999])
        ->assertStatus(422)->assertJsonValidationErrors(['city_id']);
});

test('le changement de résidence échoue sans authentification', function () {
    $city = City::factory()->create();
    $character = User::factory()->create()->characters()->create(['pseudo' => 'Artifice', 'city_id' => $city->id]);

    $this->patchJson("/api/v1/characters/{$character->id}", ['city_id' => $city->id])
        ->assertStatus(401);
});
