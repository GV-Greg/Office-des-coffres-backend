<?php

use App\Models\AnimActivityType;
use App\Models\AnimDecodeActivity;
use App\Models\User;
use App\Services\DecodeWorkflow;

/*
| Animation — administration Blade (role:admin) : types d'activité et surveillance des activités
| récentes. Aucune gestion des activités des joueurs : elles appartiennent à leur organisateur.
*/

beforeEach(function () {
    $this->map = mandateMap();
    $this->type = AnimActivityType::where('key', 'decode')->firstOrFail();
});

test('la page est réservée à l\'administrateur', function () {
    $this->get('/anim')->assertRedirect('/login');

    $this->actingAs(User::factory()->create())->get('/anim')->assertForbidden();
    $this->actingAs(mandateAdmin())->get('/anim')->assertOk()->assertSee('Activités récentes');
});

test('le toggle active et désactive un type, sans le supprimer', function () {
    $this->actingAs(mandateAdmin());

    $this->patch("/anim/types/{$this->type->id}/toggle")->assertRedirect(route('anim.index'));
    expect($this->type->refresh()->is_active)->toBeFalse();

    $this->patch("/anim/types/{$this->type->id}/toggle");
    expect($this->type->refresh()->is_active)->toBeTrue();
});

test('un joueur non administrateur ne peut pas désactiver un type', function () {
    $this->actingAs(User::factory()->create())->patch("/anim/types/{$this->type->id}/toggle")->assertForbidden();

    expect($this->type->refresh()->is_active)->toBeTrue();
});

test('les activités récentes montrent organisateur, lieu et état, même après suppression du compte', function () {
    $orga = mandatePlayer($this->map['cityA']);
    app(DecodeWorkflow::class)->create($orga, $this->type, ['name' => 'Coffre des Marches', 'province_id' => $this->map['provinceA']->id]);

    $this->actingAs(mandateAdmin())->get('/anim')
        ->assertOk()
        ->assertSee('Coffre des Marches')
        ->assertSee($orga->pseudo)
        ->assertSee('Artois')
        ->assertSee('Nouveau');

    $orga->delete();
    $this->get('/anim')->assertOk()->assertSee('Coffre des Marches')->assertSee('compte supprimé');
    expect(AnimDecodeActivity::count())->toBe(1);
});
