<?php

use App\Models\AnimActivityType;
use App\Models\AnimDecodeActivity;
use Laravel\Passport\Passport;

/*
| API des Coffres Scellés : lecture publique (le code d'un coffre scellé n'en sort JAMAIS), routes de
| l'organisateur, refus bilingues (AnimApiErrors).
*/

beforeEach(function () {
    $this->map = mandateMap();
    $this->orga = mandatePlayer($this->map['cityA']);
    $this->other = mandatePlayer($this->map['cityB']);
    $this->base = "/api/v1/characters/{$this->orga->id}/decode-activities";
});

function createActivity($test, string $name = 'Coffre des Marches')
{
    Passport::actingAs($test->orga->user);

    return $test->postJson($test->base, ['name' => $name, 'description' => 'Pour la fête']);
}

test('les types ouverts sont publics ; un type désactivé disparaît', function () {
    $this->getJson('/api/v1/anim/activity-types')->assertOk()->assertJsonPath('types', ['decode']);

    AnimActivityType::where('key', 'decode')->update(['is_active' => false]);
    $this->getJson('/api/v1/anim/activity-types')->assertOk()->assertJsonPath('types', []);
});

test('créer une activité : 201, forme de gestion, nom unique en 422 bilingue', function () {
    createActivity($this)->assertCreated()
        ->assertJsonPath('activity.name', 'Coffre des Marches')
        ->assertJsonPath('activity.status', 'new')
        ->assertJsonPath('activity.current', null);

    // Même nom : 422 de forme, avec codes et messages FR/EN.
    $other = mandatePlayer($this->map['cityA']);
    Passport::actingAs($other->user);
    $this->postJson("/api/v1/characters/{$other->id}/decode-activities", ['name' => 'Coffre des Marches'])
        ->assertStatus(422)
        ->assertJsonPath('codes.name', 'validation.unique')
        ->assertJsonStructure(['messages' => ['name' => ['fr', 'en']]]);
});

test('une seconde activité active du même personnage est refusée en 422 bilingue', function () {
    createActivity($this);
    $this->postJson($this->base, ['name' => 'Autre'])
        ->assertStatus(422)
        ->assertJsonPath('codes.name', 'activity_already_active')
        ->assertJsonPath('messages.name.fr', fn ($m) => str_contains($m, 'déjà une activité'))
        ->assertJsonPath('messages.name.en', fn ($m) => str_contains($m, 'already have an activity'));
});

test('province ET ville : refusé', function () {
    Passport::actingAs($this->orga->user);
    $this->postJson($this->base, ['name' => 'Double lieu', 'province_id' => $this->map['provinceA']->id, 'city_id' => $this->map['cityA']->id])
        ->assertStatus(422)->assertJsonPath('codes.city_id', 'place_exclusive');
});

test('le code du coffre EN COURS : visible de l\'organisateur seul, jamais en public', function () {
    createActivity($this);
    $id = AnimDecodeActivity::sole()->id;
    $secret = $this->postJson("/api/v1/decode-activities/{$id}/secrets")->assertCreated()
        ->assertJsonPath('activity.current.number', 1);
    $code = $secret->json('activity.current.code');
    expect($code)->toMatch('/^\d{5}$/');

    // L'organisateur le retrouve dans la liste de ses activités.
    $this->getJson($this->base)->assertOk()->assertJsonPath('activities.0.current.code', $code);

    // Le public : aucune trace du code, ni dans le corps brut.
    $public = $this->getJson('/api/v1/anim/decode-activities/'.rawurlencode('Coffre des Marches'));
    $public->assertOk()->assertJsonPath('activity.current.number', 1)->assertJsonMissingPath('activity.current.code');
    expect($public->getContent())->not->toContain($code);

    // Un autre personnage connecté ne le voit pas non plus (sa propre liste est vide).
    Passport::actingAs($this->other->user);
    $this->getJson("/api/v1/characters/{$this->other->id}/decode-activities")->assertOk()->assertJsonPath('activities', []);
    $this->getJson($this->base)->assertNotFound();
});

test('un coffre percé se révèle en public avec son découvreur, et le classement se cumule', function () {
    createActivity($this);
    $id = AnimDecodeActivity::sole()->id;
    $first = $this->postJson("/api/v1/decode-activities/{$id}/secrets")->json('activity.current');

    $this->postJson("/api/v1/decode-activities/{$id}/secrets/{$first['id']}/proposals", ['combination' => '99999', 'player' => 'Aldric'])
        ->assertCreated()->assertJsonPath('found', false);
    $hit = $this->postJson("/api/v1/decode-activities/{$id}/secrets/{$first['id']}/proposals", ['combination' => $first['code'], 'player' => 'Brunehaut'])
        ->assertCreated()->assertJsonPath('found', true)->assertJsonPath('score', 10)
        ->assertJsonPath('proposal.points', 20)
        ->assertJsonPath('activity.status', 'relaunch')
        ->assertJsonPath('activity.current.number', 2);

    $public = $this->getJson('/api/v1/anim/decode-activities/'.rawurlencode('Coffre des Marches'))->assertOk();
    $public->assertJsonPath('activity.opened.0.number', 1)
        ->assertJsonPath('activity.opened.0.code', $first['code'])
        ->assertJsonPath('activity.opened.0.discoverer', 'Brunehaut')
        ->assertJsonPath('activity.ranking.0.player', 'Brunehaut')
        ->assertJsonPath('activity.ranking.0.points', 20);
    // Le NOUVEAU coffre, lui, reste scellé.
    expect($public->getContent())->not->toContain($hit->json('activity.current.code'));
});

test('REFUS 403 bilingue : un autre compte ne touche à rien', function () {
    createActivity($this);
    $id = AnimDecodeActivity::sole()->id;
    $secretId = $this->postJson("/api/v1/decode-activities/{$id}/secrets")->json('activity.current.id');

    Passport::actingAs($this->other->user);
    foreach ([
        ['post', "/api/v1/decode-activities/{$id}/secrets", []],
        ['post', "/api/v1/decode-activities/{$id}/secrets/{$secretId}/proposals", ['combination' => '12345', 'player' => 'X']],
        ['post', "/api/v1/decode-activities/{$id}/reopen", []],
        ['post', "/api/v1/decode-activities/{$id}/close", []],
        ['delete', "/api/v1/decode-activities/{$id}", []],
        ['get', "/api/v1/decode-activities/{$id}/export/rules", []],
        ['get', "/api/v1/decode-activities/{$id}/export/results", []],
    ] as [$verb, $url, $body]) {
        $this->{$verb.'Json'}($url, $body)
            ->assertForbidden()
            ->assertJsonPath('codes.activity', 'not_owner')
            ->assertJsonStructure(['messages' => ['activity' => ['fr', 'en']]]);
    }

    // Rien n'a bougé.
    expect(AnimDecodeActivity::sole()->status)->toBe('launched');
});

test('un visiteur sans jeton ne gère rien (401)', function () {
    $this->postJson($this->base, ['name' => 'X'])->assertUnauthorized();
    $this->postJson('/api/v1/decode-activities/1/secrets')->assertUnauthorized();
});

test('404 : nom inconnu en public, activité inconnue en gestion, personnage d\'un autre compte', function () {
    $this->getJson('/api/v1/anim/decode-activities/Inconnue')->assertNotFound()->assertJsonPath('code', 'activity_not_found');

    Passport::actingAs($this->orga->user);
    $this->postJson('/api/v1/decode-activities/9999/close')->assertNotFound()->assertJsonPath('code', 'activity_not_found');
    $this->getJson("/api/v1/characters/{$this->other->id}/decode-activities")->assertNotFound()->assertJsonPath('code', 'character_not_found');
});

test('clore puis supprimer : la suppression exige une activité close', function () {
    createActivity($this);
    $id = AnimDecodeActivity::sole()->id;
    $this->postJson("/api/v1/decode-activities/{$id}/secrets");

    $this->deleteJson("/api/v1/decode-activities/{$id}")->assertStatus(422)->assertJsonPath('codes.activity', 'delete_requires_closed');

    $this->postJson("/api/v1/decode-activities/{$id}/close")->assertOk()->assertJsonPath('activity.status', 'closed');
    // Une activité close reste lisible en public.
    $this->getJson('/api/v1/anim/decode-activities/'.rawurlencode('Coffre des Marches'))->assertOk()->assertJsonPath('activity.status', 'closed');

    $this->deleteJson("/api/v1/decode-activities/{$id}")->assertOk();
    expect(AnimDecodeActivity::count())->toBe(0);
});

test('une proposition mal formée est une 422 de forme', function () {
    createActivity($this);
    $id = AnimDecodeActivity::sole()->id;
    $secretId = $this->postJson("/api/v1/decode-activities/{$id}/secrets")->json('activity.current.id');

    $this->postJson("/api/v1/decode-activities/{$id}/secrets/{$secretId}/proposals", ['combination' => '12a45', 'player' => 'X'])
        ->assertStatus(422)->assertJsonPath('codes.combination', 'validation.regex');
});

test('le secret est chiffré en base et relu par module-data:check', function () {
    createActivity($this);
    $id = AnimDecodeActivity::sole()->id;
    $code = $this->postJson("/api/v1/decode-activities/{$id}/secrets")->json('activity.current.code');

    expect(DB::table('anim_decode_secrets')->value('code'))->not->toContain($code);
    $this->artisan('module-data:check')->assertSuccessful();
});
