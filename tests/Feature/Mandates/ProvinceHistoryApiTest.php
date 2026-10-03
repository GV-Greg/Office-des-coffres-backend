<?php

use App\Models\Character;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

// « Ma province » côté API (fil admin/echanges/mandats-historique) : résidence seule (07, Q11),
// même fusion que l'admin, rien de ce qui est réservé à l'admin.

beforeEach(function () {
    Notification::fake();
    atGameTime('2026-05-10 12:00');
    $this->map = mandateMap();
});

afterEach(fn () => Carbon::setTestNow());

test('l\'historique suit la province de RÉSIDENCE du personnage, et seulement elle', function () {
    $reader = mandatePlayer($this->map['cityA']);  // réside en Artois
    $judge = approvedCouncil(mandatePlayer($this->map['cityA2']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    $elsewhere = approvedMayor(mandatePlayer($this->map['cityB']), $this->map['cityB'], '2026-05-01'); // Berry

    Passport::actingAs($reader->user);
    $json = $this->getJson("/api/v1/characters/{$reader->id}/province")->assertOk()->json();

    expect($json['province']['name'])->toBe('Artois')
        ->and(collect($json['offices'])->firstWhere('key', 'juge')['holders'][0]['pseudo'])->toBe($judge->character->pseudo)
        ->and(collect($json['offices'])->firstWhere('key', 'juge')['label'])->toBe(['fr' => 'Juge', 'en' => 'Judge'])
        ->and(json_encode($json))->not->toContain($elsewhere->character->pseudo)
        // Q3 : les villes sans mandat sont listées, jamais masquées.
        ->and($json['cities_without_mandate'])->toBe(['Arras', 'Béthune']);
});

test('ni refus, ni demande en attente, ni note interne, ni marque de compte supprimé', function () {
    $reader = mandatePlayer($this->map['cityA']);
    $pending = mandateWorkflow()->request(mandatePlayer($this->map['cityA']), 'mayor', [
        'city_id' => $this->map['cityA']->id, 'started_at' => '2026-05-01', 'announcement_url' => 'https://forum.example/a',
    ]);
    $rejected = mandateWorkflow()->request(mandatePlayer($this->map['cityA2']), 'mayor', [
        'city_id' => $this->map['cityA2']->id, 'started_at' => '2026-05-01', 'announcement_url' => 'https://forum.example/b',
    ]);
    mandateWorkflow()->reject($rejected, 'annonce_non_concluante', null, null);
    $mayor = approvedMayor(mandatePlayer($this->map['cityA']), $this->map['cityA'], '2026-05-01', ['note' => 'NOTE-SECRETE']);
    $mayor->forceFill(['note' => 'NOTE-SECRETE'])->save();

    Passport::actingAs($reader->user);
    $raw = $this->getJson("/api/v1/characters/{$reader->id}/province")->assertOk()->getContent();

    expect($raw)->not->toContain($pending->character->pseudo)
        ->not->toContain($rejected->character->pseudo)
        ->not->toContain('NOTE-SECRETE')
        ->not->toContain('archived')
        ->toContain($mayor->character->pseudo);
});

test('le personnage d\'un autre compte : 404 au format des mandats', function () {
    $other = mandatePlayer($this->map['cityA']);
    Passport::actingAs(mandatePlayer($this->map['cityA'])->user);

    $this->getJson("/api/v1/characters/{$other->id}/province")
        ->assertNotFound()->assertJsonPath('code', 'character_not_found')->assertJsonStructure(['messages' => ['fr', 'en']]);
});

test('résidence inconnue : province nulle, pas une erreur', function () {
    $homeless = Character::factory()->create(['city_id' => null, 'is_validated' => true]);
    Passport::actingAs($homeless->user);

    $this->getJson("/api/v1/characters/{$homeless->id}/province")->assertOk()->assertJsonPath('province', null);
});

test('sans jeton : 401', function () {
    $this->getJson('/api/v1/characters/1/province')->assertUnauthorized();
});
