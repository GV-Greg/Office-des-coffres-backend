<?php

use App\Models\AnimActivityType;
use App\Models\AnimDecodeActivity;
use App\Services\DecodeWorkflow;
use Laravel\Passport\Passport;

/*
| API de « Décode le code », mode PRIVÉ : lecture publique par SLUG (le code d'un coffre scellé n'en sort JAMAIS), routes de
| l'organisateur, refus bilingues (AnimApiErrors). Le mode sans compte a son propre fichier (DecodeAnonymousApiTest).
*/

beforeEach(function () {
    $this->map = mandateMap();
    $this->orga = mandatePlayer($this->map['cityA']);
    $this->other = mandatePlayer($this->map['cityB']);
    $this->base = "/api/v1/characters/{$this->orga->id}/decode-activities";
});

function createActivity($test, string $name = 'Coffre des Marches', string $slug = 'coffre-des-marches', array $extra = [])
{
    Passport::actingAs($test->orga->user);

    $place = array_intersect_key($extra, array_flip(['kingdom_id', 'province_id', 'city_id'])) ? [] : ['province_id' => $test->map['provinceA']->id];

    return $test->postJson($test->base, ['name' => $name, 'slug' => $slug] + $extra + $place);
}

test('les types ouverts sont publics ; un type désactivé disparaît', function () {
    $this->getJson('/api/v1/anim/activity-types')->assertOk()->assertJsonPath('types', ['decode']);

    AnimActivityType::where('key', 'decode')->update(['is_active' => false]);
    $this->getJson('/api/v1/anim/activity-types')->assertOk()->assertJsonPath('types', []);
});

test('créer une activité privée : 201, slug, mode private, pseudo du personnage lu en direct', function () {
    createActivity($this)->assertCreated()
        ->assertJsonPath('activity.name', 'Coffre des Marches')
        ->assertJsonPath('activity.slug', 'coffre-des-marches')
        ->assertJsonPath('activity.mode', 'private')
        ->assertJsonPath('activity.organizer', $this->orga->pseudo)
        ->assertJsonPath('activity.status', 'new')
        ->assertJsonPath('activity.current', null);

    // Le pseudo n'est JAMAIS recopié dans l'activité : il se lit sur le personnage.
    expect(AnimDecodeActivity::sole()->organizer_name)->toBeNull();
});

test('le slug est obligatoire, valable et libre : refus bilingue, jamais de suffixe', function () {
    Passport::actingAs($this->orga->user);
    $this->postJson($this->base, ['name' => 'Sans slug'])->assertStatus(422)->assertJsonPath('codes.slug', 'validation.required');
    $this->postJson($this->base, ['name' => 'Chiffré', 'slug' => 'abc1', 'province_id' => $this->map['provinceA']->id])->assertStatus(422)->assertJsonPath('codes.slug', 'slug_invalid');

    createActivity($this)->assertCreated();

    // Un autre personnage demande le même slug : « déjà pris », en deux langues.
    Passport::actingAs($this->other->user);
    $this->postJson("/api/v1/characters/{$this->other->id}/decode-activities", ['name' => 'Coffre des Marches', 'slug' => 'coffre-des-marches', 'province_id' => $this->map['provinceA']->id])
        ->assertStatus(422)
        ->assertJsonPath('codes.slug', 'slug_taken')
        ->assertJsonStructure(['messages' => ['slug' => ['fr', 'en']]]);
});

test('le nom n\'est plus unique à lui seul : deux noms peuvent différer par leur slug', function () {
    createActivity($this)->assertCreated();

    Passport::actingAs($this->other->user);
    $this->postJson("/api/v1/characters/{$this->other->id}/decode-activities", ['name' => 'Coffre des Marches', 'slug' => 'les-marches', 'province_id' => $this->map['provinceA']->id])->assertCreated();
});

test('une seconde activité active du même personnage est refusée en 422 bilingue', function () {
    createActivity($this)->assertCreated();
    $this->postJson($this->base, ['name' => 'Autre', 'slug' => 'autre-activite', 'province_id' => $this->map['provinceA']->id])
        ->assertStatus(422)
        ->assertJsonPath('codes.name', 'activity_already_active')
        ->assertJsonPath('messages.name.fr', fn ($m) => str_contains($m, 'déjà une activité'))
        ->assertJsonPath('messages.name.en', fn ($m) => str_contains($m, 'already have an activity'));
});

test('royaume, province OU ville : jamais deux niveaux à la fois', function () {
    Passport::actingAs($this->orga->user);
    $this->postJson($this->base, [
        'name' => 'Double lieu', 'slug' => 'double-lieu',
        'province_id' => $this->map['provinceA']->id, 'city_id' => $this->map['cityA']->id,
    ])->assertStatus(422)->assertJsonPath('codes.place', 'place_exclusive');

    $this->postJson($this->base, ['name' => 'Un royaume', 'slug' => 'un-royaume', 'kingdom_id' => $this->map['provinceA']->kingdom_id])
        ->assertCreated()->assertJsonPath('activity.place.kind', 'kingdom');
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

    // Le public, par le slug : aucune trace du code, ni dans le corps brut.
    $public = $this->getJson('/api/v1/anim/decode-activities/coffre-des-marches');
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

    $this->postJson("/api/v1/decode-activities/{$id}/secrets/{$first['id']}/proposals", ['combination' => '99999', 'character_name' => 'Aldric'])
        ->assertCreated()->assertJsonPath('found', false);
    $hit = $this->postJson("/api/v1/decode-activities/{$id}/secrets/{$first['id']}/proposals", ['combination' => $first['code'], 'character_name' => 'Brunehaut'])
        ->assertCreated()->assertJsonPath('found', true)->assertJsonPath('score', 10)
        ->assertJsonPath('proposal.points', 20)
        ->assertJsonPath('proposal.character_name', 'Brunehaut')
        ->assertJsonPath('activity.status', 'relaunch')
        ->assertJsonPath('activity.current.number', 2);

    $public = $this->getJson('/api/v1/anim/decode-activities/coffre-des-marches')->assertOk();
    $public->assertJsonPath('activity.opened.0.number', 1)
        ->assertJsonPath('activity.opened.0.code', $first['code'])
        ->assertJsonPath('activity.opened.0.discoverer', 'Brunehaut')
        ->assertJsonPath('activity.ranking.0.character_name', 'Brunehaut')
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
        ['post', "/api/v1/decode-activities/{$id}/secrets/{$secretId}/proposals", ['combination' => '12345', 'character_name' => 'X']],
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

test('une activité SANS COMPTE n\'est pas gérable par les routes privées, même par un compte connecté', function () {
    $type = AnimActivityType::where('key', 'decode')->first();
    $anonymous = app(DecodeWorkflow::class)->createAnonymous($type, [
        'slug' => 'sans-compte', 'name' => 'Sans compte', 'organizer_name' => 'Artifice', 'password' => 'un-bon-mot-de-passe',
    ], null);

    Passport::actingAs($this->orga->user);
    $this->postJson("/api/v1/decode-activities/{$anonymous->id}/close")->assertForbidden()->assertJsonPath('codes.activity', 'not_owner');
    $this->deleteJson("/api/v1/decode-activities/{$anonymous->id}")->assertForbidden();
    expect($anonymous->refresh()->isClosed())->toBeFalse();
});

test('un visiteur sans jeton ne gère rien (401)', function () {
    $this->postJson($this->base, ['name' => 'X', 'slug' => 'xxx'])->assertUnauthorized();
    $this->postJson('/api/v1/decode-activities/1/secrets')->assertUnauthorized();
});

test('404 : slug inconnu en public, activité inconnue en gestion, personnage d\'un autre compte', function () {
    $this->getJson('/api/v1/anim/decode-activities/inconnue')->assertNotFound()->assertJsonPath('code', 'activity_not_found');

    Passport::actingAs($this->orga->user);
    $this->postJson('/api/v1/decode-activities/9999/close')->assertNotFound()->assertJsonPath('code', 'activity_not_found');
    $this->getJson("/api/v1/characters/{$this->other->id}/decode-activities")->assertNotFound()->assertJsonPath('code', 'character_not_found');
});

test('clore puis supprimer : la suppression exige une activité close', function () {
    createActivity($this);
    $id = AnimDecodeActivity::sole()->id;
    $this->postJson("/api/v1/decode-activities/{$id}/secrets");

    $this->deleteJson("/api/v1/decode-activities/{$id}")->assertStatus(422)->assertJsonPath('codes.activity', 'delete_requires_closed');

    $this->postJson("/api/v1/decode-activities/{$id}/close")->assertOk()->assertJsonPath('activity.status', 'closed')->assertJsonPath('activity.can_reopen', true);
    // Une activité privée close reste lisible en public, avec tout son contenu.
    $this->getJson('/api/v1/anim/decode-activities/coffre-des-marches')->assertOk()->assertJsonPath('activity.status', 'closed')->assertJsonPath('activity.current.number', 1);

    $this->deleteJson("/api/v1/decode-activities/{$id}")->assertOk();
    expect(AnimDecodeActivity::count())->toBe(0);
});

test('une activité privée close se rouvre', function () {
    createActivity($this);
    $id = AnimDecodeActivity::sole()->id;
    $this->postJson("/api/v1/decode-activities/{$id}/secrets");
    $this->postJson("/api/v1/decode-activities/{$id}/close")->assertOk();

    $this->postJson("/api/v1/decode-activities/{$id}/reopen")->assertOk()->assertJsonPath('activity.status', 'launched');
});

test('une proposition mal formée est une 422 de forme', function () {
    createActivity($this);
    $id = AnimDecodeActivity::sole()->id;
    $secretId = $this->postJson("/api/v1/decode-activities/{$id}/secrets")->json('activity.current.id');

    $this->postJson("/api/v1/decode-activities/{$id}/secrets/{$secretId}/proposals", ['combination' => '12a45', 'character_name' => 'X'])
        ->assertStatus(422)->assertJsonPath('codes.combination', 'validation.regex');
});

test('le secret est chiffré en base et relu par module-data:check', function () {
    createActivity($this);
    $id = AnimDecodeActivity::sole()->id;
    $code = $this->postJson("/api/v1/decode-activities/{$id}/secrets")->json('activity.current.code');

    expect(DB::table('anim_decode_secrets')->value('code'))->not->toContain($code);
    $this->artisan('module-data:check')->assertSuccessful();
});

test('le lieu est obligatoire aussi pour une activité privée', function () {
    Passport::actingAs($this->orga->user);

    $this->postJson($this->base, ['name' => 'Sans lieu', 'slug' => 'sans-lieu'])
        ->assertStatus(422)->assertJsonPath('codes.place', 'place_required');
});
