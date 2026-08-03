<?php

use App\Models\City;
use App\Models\Kingdom;
use App\Models\Province;

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
