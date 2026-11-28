<?php

use App\Console\Commands\CheckModuleData;
use App\Models\CouncilMandate;
use App\Models\LivestockBirth;
use App\Models\ProvinceFeedPrice;
use App\Models\ProvinceLivestockSetting;
use App\Models\User;
use App\Services\AccountDeletion;
use App\Support\MandateCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

/*
| Livre des naissances (27/11/2026). Le bailli inscrit les naissances et tient le prix de la nourriture ; le dirigeant lit ;
| les deux règlent les espèces. Une naissance : un jour du mandat, retirable le jour même, jamais effacée. Les prix se
| verrouillent au terme, même pendant l'attente du successeur. Aucun auteur n'est nommé.
*/

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    $this->province = $this->map['provinceA'];
    atGameTime('2026-05-03 12:00');
    $this->bailli = mandatePlayer($this->map['cityB'], ['pseudo' => 'Intendant']);
    $this->comte = mandatePlayer($this->map['cityB'], ['pseudo' => 'Comte']);
    $this->mandate = approvedCouncil($this->bailli, $this->province, '2026-05-01', '2026-05-03', 'bailli');
    approvedCouncil($this->comte, $this->province, '2026-05-01', '2026-05-03', 'leader');
    atGameTime('2026-05-10 12:00');
    ProvinceLivestockSetting::create(['province_id' => $this->province->id, 'species' => ['cow', 'pig']]);
});

afterEach(fn () => Carbon::setTestNow());

function livestock($test, $character, string $suffix = ''): string
{
    Passport::actingAs($character->user);

    return "/api/v1/characters/{$character->id}/livestock".$suffix;
}

function birthBody(array $overrides = []): array
{
    return $overrides + ['born_on' => '2026-05-10', 'species' => 'pig', 'ingredient' => 'wheat', 'quantity' => 3, 'taken' => 5, 'wasted' => 0];
}

function feedBody(array $overrides = []): array
{
    return $overrides + ['effective_from' => '2026-05-09', 'prices' => ['maize' => 4.5, 'wheat' => 12, 'marjoram' => null, 'elderberry' => null]];
}

// ─── Droits ────────────────────────────────────────────────────────────────────────────

test('le bailli inscrit et fixe les prix ; le dirigeant lit et règle les espèces seulement', function () {
    $this->getJson(livestock($this, $this->bailli))->assertOk()
        ->assertJsonPath('province.name', 'Artois')->assertJsonPath('can_record', true)->assertJsonPath('can_price', true)
        ->assertJsonPath('can_configure', true)->assertJsonPath('first_day', '2026-05-03')->assertJsonPath('species', ['cow', 'pig']);

    $this->getJson(livestock($this, $this->comte))->assertOk()
        ->assertJsonPath('can_record', false)->assertJsonPath('can_price', false)->assertJsonPath('can_configure', true);
    $this->postJson(livestock($this, $this->comte, '/births'), birthBody())->assertForbidden()->assertJsonPath('code', 'not_recorder');
    $this->postJson(livestock($this, $this->comte, '/prices'), feedBody())->assertForbidden()->assertJsonPath('code', 'price_not_authorized');
    $this->putJson(livestock($this, $this->comte, '/species'), ['species' => ['bees']])->assertOk()->assertJsonPath('species', ['bees']);
});

test('sans poste : aucune province, aucune donnée, rien à écrire', function () {
    LivestockBirth::query()->delete();
    $stranger = mandatePlayer($this->map['cityA']);

    $this->getJson(livestock($this, $stranger))->assertOk()
        ->assertJsonPath('province', null)->assertJsonPath('births', [])->assertJsonPath('prices', [])->assertJsonPath('can_configure', false);
    $this->postJson(livestock($this, $stranger, '/births'), birthBody())->assertForbidden();
    $this->putJson(livestock($this, $stranger, '/species'), ['species' => []])->assertForbidden()->assertJsonPath('code', 'not_configurer');
});

test('401 sans jeton ; 404 pour le personnage d\'un autre compte', function () {
    $this->getJson("/api/v1/characters/{$this->bailli->id}/livestock")->assertUnauthorized();

    Passport::actingAs($this->comte->user);
    $this->getJson("/api/v1/characters/{$this->bailli->id}/livestock")->assertNotFound();
});

// ─── Naissances ────────────────────────────────────────────────────────────────────────

test('une naissance s\'inscrit, chiffrée, et aucun auteur n\'est nommé', function () {
    $this->postJson(livestock($this, $this->bailli, '/births'), birthBody(['species' => 'cow', 'ingredient' => 'maize', 'quantity' => 1, 'taken' => 12, 'wasted' => 0.75]))
        ->assertCreated()->assertJsonPath('birth.taken', 12)->assertJsonPath('birth.can_withdraw', true);

    expect(DB::table('livestock_births')->value('payload'))->not->toContain('maize')
        ->and(LivestockBirth::sole()->payload)->toMatchArray(['species' => 'cow', 'quantity' => 1, 'wasted' => 0.75])
        ->and(CheckModuleData::ENCRYPTED_COLUMNS['livestock_births'])->toBe('payload');

    $read = $this->getJson(livestock($this, $this->comte))->assertOk();
    expect($read->json('births.0.can_withdraw'))->toBeFalse()->and($read->getContent())->not->toContain('Intendant');
});

test('refusée hors du mandat, dans le futur, sans réglage, pour une espèce non élevée ou une nourriture qui ne convient pas', function () {
    $url = livestock($this, $this->bailli, '/births');
    $this->postJson($url, birthBody(['born_on' => '2026-05-02']))->assertStatus(422)->assertJsonPath('codes.born_on', 'birth_outside_term');
    $this->postJson($url, birthBody(['born_on' => '2026-05-11']))->assertStatus(422)->assertJsonPath('codes.born_on', 'birth_outside_term');
    $this->postJson($url, birthBody(['species' => 'goat']))->assertStatus(422)->assertJsonPath('codes.species', 'species_not_raised');
    $this->postJson($url, birthBody(['ingredient' => 'marjoram']))->assertStatus(422)->assertJsonPath('codes.ingredient', 'bad_feed');
    $this->postJson($url, birthBody(['quantity' => 5]))->assertStatus(422);
    $this->postJson($url, birthBody(['wasted' => 1]))->assertStatus(422);

    ProvinceLivestockSetting::query()->delete();
    $this->postJson($url, birthBody())->assertStatus(422)->assertJsonPath('codes.species', 'not_configured');
    expect(LivestockBirth::count())->toBe(0);
});

test('elle se retire le jour du jeu même, sans être effacée, et n\'est plus comptée', function () {
    $id = $this->postJson(livestock($this, $this->bailli, '/births'), birthBody())->json('birth.id');

    $this->postJson(livestock($this, $this->bailli, "/births/{$id}/withdraw"))->assertOk();
    expect(LivestockBirth::sole()->withdrawn_at)->not->toBeNull();
    $this->getJson(livestock($this, $this->bailli))->assertJsonPath('births', []);

    $id = $this->postJson(livestock($this, $this->bailli, '/births'), birthBody())->json('birth.id');
    atGameTime('2026-05-11 04:00');
    $this->postJson(livestock($this, $this->bailli, "/births/{$id}/withdraw"))->assertStatus(422)->assertJsonPath('codes.birth', 'withdraw_closed');
});

test('pendant l\'attente du successeur : les naissances s\'inscrivent encore, les prix non', function () {
    Carbon::setTestNow($this->mandate->valid_until->copy()->addDays(10)->addHours(12));
    $day = MandateCalendar::today();

    $this->getJson(livestock($this, $this->bailli))->assertOk()->assertJsonPath('can_record', true)->assertJsonPath('can_price', false);
    $this->postJson(livestock($this, $this->bailli, '/births'), birthBody(['born_on' => $day]))->assertCreated();
    $this->postJson(livestock($this, $this->bailli, '/prices'), feedBody(['effective_from' => '2026-05-09']))
        ->assertForbidden()->assertJsonPath('code', 'price_locked');
});

// ─── Prix ──────────────────────────────────────────────────────────────────────────────

test('un prix s\'ajoute et se corrige pendant le mandat ; au moins un prix, ni futur, ni hors mandat, ni doublon', function () {
    $url = livestock($this, $this->bailli, '/prices');
    $id = $this->postJson($url, feedBody())->assertCreated()->assertJsonPath('price.prices.wheat', 12)->json('price.id');

    $this->postJson($url, feedBody())->assertStatus(422)->assertJsonPath('codes.effective_from', 'price_exists');
    $this->postJson($url, feedBody(['effective_from' => '2026-05-11']))->assertStatus(422)->assertJsonPath('codes.effective_from', 'price_future');
    $this->postJson($url, feedBody(['effective_from' => '2026-05-02']))->assertStatus(422)->assertJsonPath('codes.effective_from', 'price_outside_office');
    $this->postJson($url, feedBody(['effective_from' => '2026-05-08', 'prices' => ['maize' => null, 'wheat' => null, 'marjoram' => null, 'elderberry' => null]]))->assertStatus(422);

    $this->putJson("{$url}/{$id}", feedBody(['prices' => ['maize' => 5, 'wheat' => null, 'marjoram' => null, 'elderberry' => null]]))
        ->assertOk()->assertJsonPath('price.prices.maize', 5)->assertJsonPath('price.prices.wheat', null);
    $this->deleteJson("{$url}/{$id}")->assertStatus(405);

    Carbon::setTestNow($this->mandate->valid_until->copy()->addHour());
    $this->putJson(livestock($this, $this->bailli, "/prices/{$id}"), feedBody())->assertForbidden()->assertJsonPath('code', 'price_locked');
});

// ─── Anciens titulaires et cycle de vie ───────────────────────────────────────────────

test('un ancien titulaire relit sa période, en lecture seule, jamais ce qui vient après', function () {
    $this->postJson(livestock($this, $this->bailli, '/births'), birthBody())->assertCreated();
    ProvinceFeedPrice::create(['province_id' => $this->province->id, 'effective_from' => '2026-05-04', 'price_wheat' => 12]);
    $later = LivestockBirth::create(['province_id' => $this->province->id, 'born_on' => '2026-08-20', 'payload' => ['species' => 'cow']]);
    ProvinceFeedPrice::create(['province_id' => $this->province->id, 'effective_from' => '2026-08-20', 'price_wheat' => 99]);
    Carbon::setTestNow($this->mandate->valid_until->copy()->addDays(60));

    $this->getJson(livestock($this, $this->bailli))->assertOk()->assertJsonPath('province', null)->assertJsonPath('past_mandates.0.id', $this->mandate->id);
    $past = $this->getJson(livestock($this, $this->bailli, "/mandates/{$this->mandate->id}"))->assertOk();

    expect($past->json('births'))->toHaveCount(1)
        ->and($past->json('births.0.id'))->not->toBe($later->id)
        ->and($past->json('births.0.can_withdraw'))->toBeFalse()
        ->and($past->json('prices'))->toHaveCount(1)
        ->and($past->json('prices.0.editable'))->toBeFalse();

    $this->getJson(livestock($this, $this->comte, "/mandates/{$this->mandate->id}"))->assertForbidden();
});

test('un mandat révoqué ne donne plus rien', function () {
    CouncilMandate::whereKey($this->mandate->id)->update(['status' => CouncilMandate::REVOKED]);
    Carbon::setTestNow($this->mandate->valid_until->copy()->addDays(60));

    $this->getJson(livestock($this, $this->bailli))->assertOk()->assertJsonPath('past_mandates', []);
});

test('la suppression d\'un compte coupe les liens vers l\'auteur ; les naissances et les prix restent', function () {
    $this->postJson(livestock($this, $this->bailli, '/births'), birthBody())->assertCreated();
    $this->postJson(livestock($this, $this->bailli, '/prices'), feedBody())->assertCreated();

    app(AccountDeletion::class)->deleteUser(User::find($this->bailli->user_id));

    expect(LivestockBirth::sole()->character_id)->toBeNull()
        ->and(ProvinceFeedPrice::sole()->created_by_character_id)->toBeNull();
});
