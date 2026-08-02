<?php

use App\Models\Character;
use App\Models\Role;
use App\Models\User;

function actingAsAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

test('guest is redirected away from the dashboard', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('authenticated non-admin cannot access the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertForbidden();
});

test('admin can view the dashboard with the character list', function () {
    $admin = actingAsAdmin();
    $character = Character::factory()->create(['pseudo' => 'Artifice']);

    $response = $this->actingAs($admin)->get('/dashboard');

    $response->assertOk();
    $response->assertSee('Artifice');
});

test('admin can validate a character', function () {
    $admin = actingAsAdmin();
    $character = Character::factory()->create(['is_validated' => false]);

    $response = $this->actingAs($admin)
        ->patch(route('characters.validate', $character));

    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('status', 'character-validated');
    expect($character->fresh()->is_validated)->toBeTrue();
});

test('admin can invalidate a character', function () {
    $admin = actingAsAdmin();
    $character = Character::factory()->create(['is_validated' => true]);

    $response = $this->actingAs($admin)
        ->patch(route('characters.validate', $character));

    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('status', 'character-invalidated');
    expect($character->fresh()->is_validated)->toBeFalse();
});

test('non-admin cannot validate a character', function () {
    $user = User::factory()->create();
    $character = Character::factory()->create(['is_validated' => false]);

    $this->actingAs($user)
        ->patch(route('characters.validate', $character))
        ->assertForbidden();

    expect($character->fresh()->is_validated)->toBeFalse();
});
