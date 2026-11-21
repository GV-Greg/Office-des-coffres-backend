<?php

use App\Models\CityInventorySnapshot;
use App\Models\MayorMandate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/*
| Registre des stocks, niveau VILLE (Cowork et Greg, 20/11/2026, fil registre-stocks : Q1-A une table par niveau, Q3 `office_key` =
| MayorMandate::LEVEL, Q4 pas de niveau personnel, Q5 rédacteur remplacé nommé, Q6 409 puis `confirm_replace`). Jumeau du registre de la
| province : un exemplaire par ville et par jour, un second texte le REMPLACE sans rien effacer, le texte est chiffré, le successeur du
| maire voit tout l'historique, l'exemplaire reste quand l'auteur disparaît. Le maire SEUL écrit et lit — le conseil n'y a aucun accès.
*/

beforeEach(function () {
    $this->map = mandateMap();
    atGameTime('2026-05-03 12:00');
    $this->city = $this->map['cityA'];
    $this->mayor = mandatePlayer($this->city, ['pseudo' => 'Maire']);
    approvedMayor($this->mayor, $this->city, '2026-05-01');
    // Un autre maire, d'une AUTRE ville de la même province : il ne voit jamais celle d'Arras.
    $this->otherMayor = mandatePlayer($this->map['cityA2'], ['pseudo' => 'Maire de Béthune']);
    approvedMayor($this->otherMayor, $this->map['cityA2'], '2026-05-01');
    // Le conseil de la province : aucun accès à l'inventaire d'une ville.
    $this->commerce = mandatePlayer($this->map['cityB'], ['pseudo' => 'Marchand']);
    $this->comte = mandatePlayer($this->map['cityB'], ['pseudo' => 'Comte']);
    approvedCouncil($this->commerce, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'commissaire_commerce');
    approvedCouncil($this->comte, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'leader');
    $this->plain = mandatePlayer($this->city, ['pseudo' => 'Passant']);
    atGameTime('2026-05-10 12:00');
});

afterEach(fn () => Carbon::setTestNow());

function cityRegUrl($character): string
{
    return "/api/v1/characters/{$character->id}/inventory/city/registry";
}

function cityRegAs($character)
{
    Passport::actingAs($character->user);

    return test();
}

const CITY_DAY1 = "Encombrement : 100\n596Miches de pain2980\n121Morceaux de viande605";

// ─── Écriture : le maire en fonction seulement ───────────────────────────────

test('le maire inscrit l\'inventaire du jour : un exemplaire daté du jour de Paris, estampillé « mayor », texte chiffré en base', function () {
    cityRegAs($this->mayor)->postJson(cityRegUrl($this->mayor), ['raw' => CITY_DAY1])->assertCreated()
        ->assertJsonPath('snapshot.taken_on', '2026-05-10')->assertJsonPath('replaced', null)->assertJsonPath('unchanged', false);

    $snapshot = CityInventorySnapshot::sole();
    expect($snapshot->city_id)->toBe($this->city->id)
        ->and($snapshot->character_id)->toBe($this->mayor->id)
        ->and($snapshot->office_key)->toBe(MayorMandate::LEVEL)->and($snapshot->office_key)->toBe('mayor')
        ->and($snapshot->active)->toBeTrue()
        ->and($snapshot->payload)->toBe(CITY_DAY1);

    // Axes en clair, payload chiffré : ni les objets ni le trésor ne sont lisibles en SQL.
    $raw = DB::table('city_inventory_snapshots')->value('payload');
    expect($raw)->not->toContain('Miches de pain')->and($raw)->not->toContain('596')->and($raw)->not->toContain('Encombrement');
});

test('seul le maire en fonction écrit : le conseil, un joueur sans poste et un maire d\'une autre ville ne touchent pas Arras', function () {
    foreach ([$this->commerce, $this->comte, $this->plain] as $who) {
        cityRegAs($who)->postJson(cityRegUrl($who), ['raw' => CITY_DAY1])->assertForbidden()->assertJsonPath('code', 'city_not_authorized');
    }
    expect(CityInventorySnapshot::count())->toBe(0);

    // Le maire de Béthune écrit dans SA ville, jamais dans celle d'Arras (la ville est celle du mandat).
    cityRegAs($this->otherMayor)->postJson(cityRegUrl($this->otherMayor), ['raw' => CITY_DAY1])->assertCreated();
    expect(CityInventorySnapshot::sole()->city_id)->toBe($this->map['cityA2']->id);
});

test('un texte vide ou démesuré est refusé', function () {
    cityRegAs($this->mayor)->postJson(cityRegUrl($this->mayor), ['raw' => str_repeat('x', 200001)])
        ->assertStatus(422)->assertJsonPath('codes.raw', 'inventory_too_large');
    $this->postJson(cityRegUrl($this->mayor), [])->assertStatus(422);
    expect(CityInventorySnapshot::count())->toBe(0);
});

test('un mandat de maire terminé n\'ouvre plus l\'écriture', function () {
    atGameTime('2026-09-01 12:00');
    cityRegAs($this->mayor)->postJson(cityRegUrl($this->mayor), ['raw' => CITY_DAY1])->assertForbidden();
    cityRegAs($this->mayor)->getJson(cityRegUrl($this->mayor))->assertForbidden();
});

// ─── Un exemplaire par jour : remplacé après confirmation, jamais effacé ─────

test('un autre texte le même jour SANS confirmation : 409 qui nomme l\'auteur de l\'exemplaire à remplacer, rien n\'est écrit', function () {
    cityRegAs($this->mayor)->postJson(cityRegUrl($this->mayor), ['raw' => CITY_DAY1])->assertCreated();

    $this->postJson(cityRegUrl($this->mayor), ['raw' => CITY_DAY1."\n3Cailloux0"])->assertStatus(409)
        ->assertJsonPath('code', 'registry_needs_confirmation')
        ->assertJsonPath('messages.fr', 'Un inventaire existe déjà au registre pour le 10/05/2026 (Arras). Il sera remplacé, pas effacé : l\'ancien reste conservé.')
        ->assertJsonPath('existing.pseudo', 'Maire')->assertJsonPath('existing.office_label.fr', 'Maire')->assertJsonPath('existing.office_label.en', 'Mayor');

    expect(CityInventorySnapshot::count())->toBe(1)->and(CityInventorySnapshot::sole()->active)->toBeTrue();
});

test('avec confirm_replace, le second texte REMPLACE le premier : l\'ancien reste en base, marqué remplacé', function () {
    cityRegAs($this->mayor)->postJson(cityRegUrl($this->mayor), ['raw' => CITY_DAY1])->assertCreated();
    $second = '9Cailloux0';
    $this->postJson(cityRegUrl($this->mayor), ['raw' => $second, 'confirm_replace' => true])->assertCreated()
        ->assertJsonPath('replaced.pseudo', 'Maire')->assertJsonPath('replaced.office_key', 'mayor');

    expect(CityInventorySnapshot::count())->toBe(2);
    $old = CityInventorySnapshot::whereNull('active')->sole();
    $new = CityInventorySnapshot::where('active', true)->sole();
    expect($old->payload)->toBe(CITY_DAY1)
        ->and($old->replaced_by_id)->toBe($new->id)
        ->and($old->replaced_at)->not->toBeNull()
        ->and($new->payload)->toBe($second);

    // Un seul exemplaire en vigueur ce jour-là, dans l'historique servi ; son texte n'est plus consultable, son rédacteur reste nommé.
    $body = $this->getJson(cityRegUrl($this->mayor))->assertOk()->assertJsonCount(1, 'snapshots')
        ->assertJsonPath('snapshots.0.raw', $second)->assertJsonPath('snapshots.0.replaced.pseudo', 'Maire')->getContent();
    expect($body)->not->toContain('Miches de pain');
});

test('un collage identique à celui du jour ne crée rien, et ne demande aucune confirmation', function () {
    cityRegAs($this->mayor)->postJson(cityRegUrl($this->mayor), ['raw' => CITY_DAY1])->assertCreated();
    $this->postJson(cityRegUrl($this->mayor), ['raw' => CITY_DAY1])->assertOk()->assertJsonPath('unchanged', true)->assertJsonPath('replaced', null);
    expect(CityInventorySnapshot::count())->toBe(1);
});

test('un autre jour, un nouvel exemplaire : l\'historique s\'allonge, du plus récent au plus ancien', function () {
    cityRegAs($this->mayor)->postJson(cityRegUrl($this->mayor), ['raw' => CITY_DAY1])->assertCreated();
    atGameTime('2026-05-17 09:00');
    $this->postJson(cityRegUrl($this->mayor), ['raw' => '700Miches de pain3500'])->assertCreated()->assertJsonPath('snapshot.taken_on', '2026-05-17');
    atGameTime('2026-05-24 09:00');
    $this->postJson(cityRegUrl($this->mayor), ['raw' => '800Miches de pain4000'])->assertCreated();

    $this->getJson(cityRegUrl($this->mayor))->assertOk()->assertJsonCount(3, 'snapshots')
        ->assertJsonPath('snapshots.0.taken_on', '2026-05-24')
        ->assertJsonPath('snapshots.1.taken_on', '2026-05-17')
        ->assertJsonPath('snapshots.2.taken_on', '2026-05-10')
        ->assertJsonPath('snapshots.2.raw', CITY_DAY1);
});

test('la base tient « un exemplaire en vigueur par ville et par jour » : deux actifs se heurtent', function () {
    cityRegAs($this->mayor)->postJson(cityRegUrl($this->mayor), ['raw' => CITY_DAY1])->assertCreated();

    expect(fn () => CityInventorySnapshot::create([
        'city_id' => $this->city->id, 'taken_on' => '2026-05-10', 'character_id' => null, 'office_key' => 'mayor', 'payload' => 'autre',
    ]))->toThrow(QueryException::class);
});

// ─── Lecture : le maire seul ─────────────────────────────────────────────────

test('lecture : le maire lit l\'historique de SA ville ; le conseil, un passant et le maire d\'une autre ville n\'y accèdent pas', function () {
    cityRegAs($this->mayor)->postJson(cityRegUrl($this->mayor), ['raw' => CITY_DAY1])->assertCreated();

    cityRegAs($this->mayor)->getJson(cityRegUrl($this->mayor))->assertOk()
        ->assertJsonPath('city.name', 'Arras')->assertJsonPath('can_write', true)
        ->assertJsonPath('snapshots.0.raw', CITY_DAY1)->assertJsonPath('snapshots.0.by_me', true);

    foreach ([$this->commerce, $this->comte, $this->plain] as $who) {
        cityRegAs($who)->getJson(cityRegUrl($who))->assertForbidden()->assertJsonPath('code', 'city_not_authorized');
    }
    // Le maire de Béthune lit SA ville (vide), jamais celle d'Arras.
    cityRegAs($this->otherMayor)->getJson(cityRegUrl($this->otherMayor))->assertOk()->assertJsonPath('city.name', 'Béthune')->assertJsonCount(0, 'snapshots');
});

test('le registre dit le jour du jeu en cours (`today`), pour l\'âge de la dernière inscription — la bascule est à 04:00 Paris', function () {
    cityRegAs($this->mayor)->getJson(cityRegUrl($this->mayor))->assertOk()->assertJsonPath('today', '2026-05-10');
    // 02:00 à Paris : le jour du jeu n'a pas encore basculé.
    atGameTime('2026-05-11 02:00');
    $this->getJson(cityRegUrl($this->mayor))->assertOk()->assertJsonPath('today', '2026-05-10');
    atGameTime('2026-05-11 04:30');
    $this->getJson(cityRegUrl($this->mayor))->assertOk()->assertJsonPath('today', '2026-05-11');
});

test('l\'auteur d\'un exemplaire en vigueur n\'est jamais nommé : ni son pseudo ni son identifiant', function () {
    cityRegAs($this->mayor)->postJson(cityRegUrl($this->mayor), ['raw' => CITY_DAY1])->assertCreated();
    $body = cityRegAs($this->mayor)->getJson(cityRegUrl($this->mayor))->assertOk()->getContent();

    expect($body)->not->toContain('"pseudo":"Maire"')->and($body)->not->toContain('character_id')
        ->and(array_keys(json_decode($body, true)['snapshots'][0]))->toBe(['id', 'taken_on', 'saved_at', 'office_key', 'by_me', 'replaced', 'raw']);
});

// ─── Mémoire de la ville : transmission et survie ────────────────────────────

test('un nouveau maire voit l\'historique COMPLET de son prédécesseur (des faits de la ville)', function () {
    cityRegAs($this->mayor)->postJson(cityRegUrl($this->mayor), ['raw' => CITY_DAY1])->assertCreated();
    atGameTime('2026-05-17 09:00');
    $this->postJson(cityRegUrl($this->mayor), ['raw' => '700Miches de pain3500'])->assertCreated();

    // Le prédécesseur quitte le poste (30 jours), un successeur est élu plus tard.
    atGameTime('2026-08-01 12:00');
    $successor = mandatePlayer($this->city, ['pseudo' => 'Successeur']);
    approvedMayor($successor, $this->city, '2026-07-29');

    cityRegAs($successor)->getJson(cityRegUrl($successor))->assertOk()->assertJsonCount(2, 'snapshots')
        ->assertJsonPath('snapshots.0.by_me', false)->assertJsonPath('snapshots.1.raw', CITY_DAY1);
});

test('si l\'auteur disparaît, l\'exemplaire reste et seul le lien est coupé (catégorie C)', function () {
    cityRegAs($this->mayor)->postJson(cityRegUrl($this->mayor), ['raw' => CITY_DAY1])->assertCreated();
    atGameTime('2026-08-01 12:00');
    $successor = mandatePlayer($this->city, ['pseudo' => 'Successeur']);
    approvedMayor($successor, $this->city, '2026-07-29');
    $this->mayor->delete();

    $snapshot = CityInventorySnapshot::sole();
    expect($snapshot->character_id)->toBeNull()->and($snapshot->payload)->toBe(CITY_DAY1);
    cityRegAs($successor)->getJson(cityRegUrl($successor))->assertOk()->assertJsonPath('snapshots.0.raw', CITY_DAY1);
});

test('si le rédacteur remplacé a disparu, son pseudo est NULL : le lien est coupé, le fait reste', function () {
    cityRegAs($this->mayor)->postJson(cityRegUrl($this->mayor), ['raw' => CITY_DAY1])->assertCreated();
    $this->mayor->delete();
    // Le maire disparu : un autre maire reprend la ville le même jour du jeu et remplace l'exemplaire.
    $successor = mandatePlayer($this->city, ['pseudo' => 'Successeur']);
    approvedMayor($successor, $this->city, '2026-05-09');

    cityRegAs($successor)->postJson(cityRegUrl($successor), ['raw' => '5Cailloux0', 'confirm_replace' => true])->assertCreated()->assertJsonPath('replaced.pseudo', null);
});

test('l\'historique servi est borné aux exemplaires les plus récents', function () {
    foreach (range(1, 105) as $i) {
        CityInventorySnapshot::create([
            'city_id' => $this->city->id, 'taken_on' => Carbon::parse('2025-01-01')->addDays($i)->toDateString(),
            'character_id' => null, 'office_key' => 'mayor', 'payload' => "x$i",
        ]);
    }
    cityRegAs($this->mayor)->getJson(cityRegUrl($this->mayor))->assertOk()->assertJsonCount(100, 'snapshots')->assertJsonPath('snapshots.0.raw', 'x105');
});

test('le registre de la ville et celui de la province sont deux tables : l\'un n\'écrit jamais dans l\'autre', function () {
    cityRegAs($this->mayor)->postJson(cityRegUrl($this->mayor), ['raw' => CITY_DAY1])->assertCreated();
    expect(DB::table('city_inventory_snapshots')->count())->toBe(1)->and(DB::table('province_inventory_snapshots')->count())->toBe(0);
});
