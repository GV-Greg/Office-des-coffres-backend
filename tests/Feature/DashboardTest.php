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

// --- Users ---

test('guest is redirected away from the users page', function () {
    $this->get('/users')->assertRedirect('/login');
});

test('authenticated non-admin cannot access the users page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/users')->assertForbidden();
});

test('admin sees all users, including those with a validated character', function () {
    $admin = actingAsAdmin();
    $userWithoutCharacter = User::factory()->create(['email' => 'sanspersonnage@test.com']);
    $userWithCharacter = User::factory()->create(['email' => 'avecpersonnage@test.com']);
    Character::factory()->create(['user_id' => $userWithCharacter->id, 'pseudo' => 'Buldo', 'is_validated' => true, 'city_id' => null]);

    $response = $this->actingAs($admin)->get('/users');

    $response->assertOk();
    $response->assertSee('sanspersonnage@test.com');
    $response->assertSee('avecpersonnage@test.com');
    $response->assertSee('Buldo');
});

test('admin can search users by email', function () {
    $admin = actingAsAdmin();
    User::factory()->create(['email' => 'trouvemoi@test.com']);
    User::factory()->create(['email' => 'autre@test.com']);

    $response = $this->actingAs($admin)->get('/users?search=trouvemoi');

    $response->assertOk();
    $response->assertSee('trouvemoi@test.com');
    $response->assertDontSee('autre@test.com');
});

test('admin can search users by character pseudo', function () {
    $admin = actingAsAdmin();
    $withPseudo = User::factory()->create(['email' => 'joueur@test.com']);
    Character::factory()->create(['user_id' => $withPseudo->id, 'pseudo' => 'Artifice', 'city_id' => null]);
    User::factory()->create(['email' => 'autre@test.com']);

    $response = $this->actingAs($admin)->get('/users?search=Artifice');

    $response->assertOk();
    $response->assertSee('joueur@test.com');
    $response->assertDontSee('autre@test.com');
});

test('admin can delete a user', function () {
    $admin = actingAsAdmin();
    $user = User::factory()->create();

    $response = $this->actingAs($admin)->delete(route('users.destroy', $user));

    $response->assertRedirect(route('users'));
    $response->assertSessionHas('status', 'user-deleted');
    $this->assertModelMissing($user);
});

test('non-admin cannot delete a user', function () {
    $actor = User::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($actor)
        ->delete(route('users.destroy', $user))
        ->assertForbidden();

    $this->assertModelExists($user);
});
