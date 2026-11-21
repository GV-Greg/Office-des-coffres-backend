<?php

use App\Models\ProvinceInventorySnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/*
| Inventaires de la PROVINCE — historique des collages du commissaire au commerce, pour ses bilans (Cowork et Greg,
| 11/11/2026, fil inventaire-bilans : « B pour la province »). Calqué sur le Registre des mines : un exemplaire par province
| et par jour, un second collage le REMPLACE sans rien effacer (après confirmation : 409 puis `confirm_replace`, fil registre-stocks
| Q6), le texte est chiffré, le successeur voit tout l'historique, l'auteur d'un exemplaire n'est nommé que lorsqu'il est REMPLACÉ
| (Q5), l'exemplaire reste quand l'auteur disparaît.
*/

beforeEach(function () {
    $this->map = mandateMap();
    atGameTime('2026-05-03 12:00');
    $this->province = $this->map['provinceA'];
    $this->commerce = mandatePlayer($this->map['cityB'], ['pseudo' => 'Marchand']);
    $this->mines = mandatePlayer($this->map['cityB'], ['pseudo' => 'Mineur']);
    $this->bailli = mandatePlayer($this->map['cityB'], ['pseudo' => 'Bailli']);
    $this->comte = mandatePlayer($this->map['cityB'], ['pseudo' => 'Comte']);
    $this->other = mandatePlayer($this->map['cityB'], ['pseudo' => 'Autre province']);
    approvedCouncil($this->commerce, $this->province, '2026-05-01', '2026-05-03', 'commissaire_commerce');
    approvedCouncil($this->mines, $this->province, '2026-05-01', '2026-05-03', 'commissaire_mines');
    approvedCouncil($this->bailli, $this->province, '2026-05-01', '2026-05-03', 'bailli');
    approvedCouncil($this->comte, $this->province, '2026-05-01', '2026-05-03', 'leader');
    // Un commissaire au commerce d'une AUTRE province : il ne voit jamais celle d'Artois.
    approvedCouncil($this->other, $this->map['provinceB'], '2026-05-01', '2026-05-03', 'commissaire_commerce');
    atGameTime('2026-05-10 12:00');
});

afterEach(fn () => Carbon::setTestNow());

function provUrl($character): string
{
    return "/api/v1/characters/{$character->id}/inventory/province";
}

function provAs($character)
{
    Passport::actingAs($character->user);

    return test();
}

const PROV_DAY1 = "Encombrement : 100\n596Miches de pain2980\n121Morceaux de viande605";

// ─── Écriture : le commissaire au commerce seulement ─────────────────────────

test('le commissaire au commerce inscrit l\'inventaire du jour : un exemplaire daté du jour de Paris, texte chiffré en base', function () {
    provAs($this->commerce)->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1])->assertCreated()
        ->assertJsonPath('snapshot.taken_on', '2026-05-10')->assertJsonPath('replaced', null)->assertJsonPath('unchanged', false);

    $snapshot = ProvinceInventorySnapshot::sole();
    expect($snapshot->province_id)->toBe($this->province->id)
        ->and($snapshot->character_id)->toBe($this->commerce->id)
        ->and($snapshot->office_key)->toBe('commissaire_commerce')
        ->and($snapshot->active)->toBeTrue()
        ->and($snapshot->payload)->toBe(PROV_DAY1);

    // Axes en clair, payload chiffré : ni les objets ni le trésor ne sont lisibles en SQL.
    $raw = DB::table('province_inventory_snapshots')->value('payload');
    expect($raw)->not->toContain('Miches de pain')->and($raw)->not->toContain('596')->and($raw)->not->toContain('Encombrement');
});

test('seul le commissaire au commerce écrit : le dirigeant, le commissaire aux mines, le bailli et un autre sont refusés', function () {
    foreach ([$this->comte, $this->mines, $this->bailli] as $who) {
        provAs($who)->postJson(provUrl($who), ['raw' => PROV_DAY1])->assertForbidden()->assertJsonPath('code', 'province_not_authorized');
    }
    // Un joueur sans poste.
    $plain = mandatePlayer($this->map['cityB']);
    provAs($plain)->postJson(provUrl($plain), ['raw' => PROV_DAY1])->assertForbidden();
    expect(ProvinceInventorySnapshot::count())->toBe(0);
});

test('un texte vide ou démesuré est refusé', function () {
    provAs($this->commerce)->postJson(provUrl($this->commerce), ['raw' => str_repeat('x', 200001)])
        ->assertStatus(422)->assertJsonPath('codes.raw', 'inventory_too_large');
    $this->postJson(provUrl($this->commerce), [])->assertStatus(422);
    expect(ProvinceInventorySnapshot::count())->toBe(0);
});

// ─── Un exemplaire par jour : remplacé, jamais effacé ────────────────────────

test('un second collage le même jour REMPLACE le premier, après confirmation : l\'ancien reste en base, marqué remplacé', function () {
    provAs($this->commerce)->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1])->assertCreated()->assertJsonPath('replaced', null);
    $second = PROV_DAY1."\n3Cailloux0";
    $this->postJson(provUrl($this->commerce), ['raw' => $second, 'confirm_replace' => true])->assertCreated()
        ->assertJsonPath('replaced.pseudo', 'Marchand')->assertJsonPath('replaced.office_key', 'commissaire_commerce')
        ->assertJsonPath('replaced.office_label.fr', 'Commissaire au commerce')->assertJsonPath('replaced.taken_on', '2026-05-10');

    expect(ProvinceInventorySnapshot::count())->toBe(2);
    $old = ProvinceInventorySnapshot::whereNull('active')->sole();
    $new = ProvinceInventorySnapshot::where('active', true)->sole();
    expect($old->payload)->toBe(PROV_DAY1)
        ->and($old->replaced_by_id)->toBe($new->id)
        ->and($old->replaced_at)->not->toBeNull()
        ->and($new->payload)->toBe($second);

    // Un seul exemplaire en vigueur ce jour-là, dans l'historique servi.
    $this->getJson(provUrl($this->commerce))->assertOk()->assertJsonCount(1, 'snapshots')->assertJsonPath('snapshots.0.raw', $second);
});

test('un autre texte le même jour SANS confirmation : 409 qui nomme l\'auteur de l\'exemplaire à remplacer, rien n\'est écrit', function () {
    provAs($this->commerce)->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1])->assertCreated();

    $this->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1."\n3Cailloux0"])->assertStatus(409)
        ->assertJsonPath('code', 'registry_needs_confirmation')
        ->assertJsonPath('messages.fr', 'Un inventaire existe déjà au registre pour le 10/05/2026 (Artois). Il sera remplacé, pas effacé : l\'ancien reste conservé.')
        ->assertJsonPath('messages.en', 'An inventory is already in the registry for 10/05/2026 (Artois). It will be replaced, not deleted: the old one is kept.')
        ->assertJsonPath('existing.pseudo', 'Marchand')->assertJsonPath('existing.office_label.en', 'Trade Minister');

    expect(ProvinceInventorySnapshot::count())->toBe(1)->and(ProvinceInventorySnapshot::sole()->active)->toBeTrue();
});

test('un collage identique à celui du jour ne crée rien, et ne demande aucune confirmation', function () {
    provAs($this->commerce)->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1])->assertCreated();
    $this->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1])->assertOk()->assertJsonPath('unchanged', true)->assertJsonPath('replaced', null);
    expect(ProvinceInventorySnapshot::count())->toBe(1);
});

test('un autre jour, un nouvel exemplaire : l\'historique s\'allonge, du plus récent au plus ancien', function () {
    provAs($this->commerce)->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1])->assertCreated();
    atGameTime('2026-05-17 09:00');
    $this->postJson(provUrl($this->commerce), ['raw' => "700Miches de pain3500"])->assertCreated()->assertJsonPath('snapshot.taken_on', '2026-05-17');
    atGameTime('2026-05-24 09:00');
    $this->postJson(provUrl($this->commerce), ['raw' => "800Miches de pain4000"])->assertCreated();

    $this->getJson(provUrl($this->commerce))->assertOk()->assertJsonCount(3, 'snapshots')
        ->assertJsonPath('snapshots.0.taken_on', '2026-05-24')
        ->assertJsonPath('snapshots.1.taken_on', '2026-05-17')
        ->assertJsonPath('snapshots.2.taken_on', '2026-05-10')
        ->assertJsonPath('snapshots.2.raw', PROV_DAY1);
});

test('la base tient « un exemplaire en vigueur par province et par jour » : deux actifs se heurtent', function () {
    provAs($this->commerce)->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1])->assertCreated();

    expect(fn () => ProvinceInventorySnapshot::create([
        'province_id' => $this->province->id, 'taken_on' => '2026-05-10', 'character_id' => null,
        'office_key' => 'commissaire_commerce', 'payload' => 'autre',
    ]))->toThrow(QueryException::class);
});

// ─── Lecture : commissaire au commerce et dirigeant ──────────────────────────

test('lecture : le commissaire au commerce et le dirigeant lisent l\'historique, les autres postes et les autres provinces non', function () {
    provAs($this->commerce)->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1])->assertCreated();

    provAs($this->commerce)->getJson(provUrl($this->commerce))->assertOk()
        ->assertJsonPath('province.name', 'Artois')->assertJsonPath('can_write', true)
        ->assertJsonPath('snapshots.0.raw', PROV_DAY1)->assertJsonPath('snapshots.0.by_me', true);

    provAs($this->comte)->getJson(provUrl($this->comte))->assertOk()
        ->assertJsonPath('can_write', false)->assertJsonPath('snapshots.0.raw', PROV_DAY1)->assertJsonPath('snapshots.0.by_me', false);

    foreach ([$this->mines, $this->bailli] as $who) {
        provAs($who)->getJson(provUrl($who))->assertForbidden()->assertJsonPath('code', 'province_not_reader');
    }
    // Le commissaire au commerce d'une autre province lit SA province (vide), jamais celle d'Artois.
    provAs($this->other)->getJson(provUrl($this->other))->assertOk()->assertJsonPath('province.name', 'Berry')->assertJsonCount(0, 'snapshots');
});

test('le registre dit le jour du jeu en cours (`today`) — la bascule est à 04:00 Paris', function () {
    provAs($this->comte)->getJson(provUrl($this->comte))->assertOk()->assertJsonPath('today', '2026-05-10');
    atGameTime('2026-05-11 02:00');
    $this->getJson(provUrl($this->comte))->assertOk()->assertJsonPath('today', '2026-05-10');
});

test('l\'auteur d\'un exemplaire en vigueur n\'est jamais nommé : l\'historique ne porte ni son pseudo ni son identifiant', function () {
    provAs($this->commerce)->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1])->assertCreated();
    $body = provAs($this->comte)->getJson(provUrl($this->comte))->assertOk()->getContent();

    expect($body)->not->toContain('Marchand')->and($body)->not->toContain('character_id')
        ->and(array_keys(json_decode($body, true)['snapshots'][0]))->toBe(['id', 'taken_on', 'saved_at', 'office_key', 'by_me', 'replaced', 'raw'])
        ->and(json_decode($body, true)['snapshots'][0]['replaced'])->toBeNull();
});

test('le rédacteur REMPLACÉ reste nommé dans l\'historique, pour tout lecteur (politique §3) — sans son texte', function () {
    provAs($this->commerce)->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1])->assertCreated();
    $second = '9Cailloux0'; // sans rien de commun avec le premier texte
    $this->postJson(provUrl($this->commerce), ['raw' => $second, 'confirm_replace' => true])->assertCreated();

    $body = provAs($this->comte)->getJson(provUrl($this->comte))->assertOk()
        ->assertJsonCount(1, 'snapshots')->assertJsonPath('snapshots.0.replaced.pseudo', 'Marchand')
        ->assertJsonPath('snapshots.0.replaced.office_key', 'commissaire_commerce')->getContent();

    // Le texte de l'ancien n'est plus consultable : seul son rédacteur est nommé, pas le texte.
    expect(array_keys(json_decode($body, true)['snapshots'][0]['replaced']))->toBe(['id', 'pseudo', 'office_key', 'office_label', 'taken_on', 'saved_at', 'replaced_at'])
        ->and($body)->not->toContain('Miches de pain')->and(substr_count($body, 'Cailloux'))->toBe(1);
});

test('si le rédacteur remplacé a disparu, son pseudo est NULL : le lien est coupé, le fait reste', function () {
    provAs($this->commerce)->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1])->assertCreated();
    $successor = mandatePlayer($this->map['cityB'], ['pseudo' => 'Successeur']);
    approvedCouncil($successor, $this->province, '2026-05-02', '2026-05-09', 'commissaire_commerce');
    $this->commerce->delete();

    provAs($successor)->postJson(provUrl($successor), ['raw' => '5Cailloux0', 'confirm_replace' => true])->assertCreated()->assertJsonPath('replaced.pseudo', null);
});

// ─── Mémoire de la province : transmission et survie ─────────────────────────

test('un nouveau commissaire au commerce voit l\'historique COMPLET de son prédécesseur (des faits provinciaux)', function () {
    provAs($this->commerce)->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1])->assertCreated();
    atGameTime('2026-05-17 09:00');
    $this->postJson(provUrl($this->commerce), ['raw' => '700Miches de pain3500'])->assertCreated();

    // Le prédécesseur quitte le poste, un successeur est élu plus tard.
    atGameTime('2026-08-01 12:00');
    $successor = mandatePlayer($this->map['cityB'], ['pseudo' => 'Successeur']);
    approvedCouncil($successor, $this->province, '2026-07-29', '2026-08-01', 'commissaire_commerce');

    provAs($successor)->getJson(provUrl($successor))->assertOk()->assertJsonCount(2, 'snapshots')
        ->assertJsonPath('snapshots.0.by_me', false)->assertJsonPath('snapshots.1.raw', PROV_DAY1);
});

test('si l\'auteur disparaît, l\'exemplaire reste et seul le lien est coupé (catégorie C)', function () {
    provAs($this->commerce)->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1])->assertCreated();
    $this->commerce->delete();

    $snapshot = ProvinceInventorySnapshot::sole();
    expect($snapshot->character_id)->toBeNull()->and($snapshot->payload)->toBe(PROV_DAY1);
    provAs($this->comte)->getJson(provUrl($this->comte))->assertOk()->assertJsonPath('snapshots.0.raw', PROV_DAY1);
});

test('l\'historique servi est borné aux exemplaires les plus récents', function () {
    foreach (range(1, 105) as $i) {
        ProvinceInventorySnapshot::create([
            'province_id' => $this->province->id, 'taken_on' => Carbon::parse('2025-01-01')->addDays($i)->toDateString(),
            'character_id' => null, 'office_key' => 'commissaire_commerce', 'payload' => "x$i",
        ]);
    }
    provAs($this->comte)->getJson(provUrl($this->comte))->assertOk()->assertJsonCount(100, 'snapshots')
        ->assertJsonPath('snapshots.0.raw', 'x105');
});

// ─── Rétro-datation (brief 10) : mêmes règles que la ville, depuis l'entrée en fonction du commissaire au commerce (03/05 04:00) ─────────────

test('province : la date choisie, de 14 jours du jeu avant l\'entrée en fonction (19/04) à aujourd\'hui (10/05) ; hors plage, 422', function () {
    provAs($this->commerce)->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1, 'date' => '2026-04-19'])->assertCreated()->assertJsonPath('snapshot.taken_on', '2026-04-19');
    $this->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1, 'date' => '2026-04-18'])->assertStatus(422)->assertJsonPath('codes.date', 'registry_date_out_of_range');
    $this->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1, 'date' => '2026-05-11'])->assertStatus(422)->assertJsonPath('codes.date', 'registry_date_out_of_range');
});

test('province : avant l\'entrée en fonction, l\'inscription du prédécesseur est verrouillée ; pendant le mandat, elle se remplace après confirmation', function () {
    ProvinceInventorySnapshot::create(['province_id' => $this->province->id, 'taken_on' => '2026-04-25', 'character_id' => null, 'office_key' => 'commissaire_commerce', 'payload' => 'héritage']);

    provAs($this->commerce)->postJson(provUrl($this->commerce), ['raw' => 'autre', 'date' => '2026-04-25', 'confirm_replace' => true])->assertStatus(409)
        ->assertJsonPath('code', 'registry_before_mandate_locked');
    expect(ProvinceInventorySnapshot::sole()->payload)->toBe('héritage');

    $this->postJson(provUrl($this->commerce), ['raw' => PROV_DAY1, 'date' => '2026-05-06'])->assertCreated();
    $this->postJson(provUrl($this->commerce), ['raw' => 'autre', 'date' => '2026-05-06'])->assertStatus(409)->assertJsonPath('code', 'registry_needs_confirmation');
});

test('province : `office_from` pour tout lecteur (le dirigeant aussi), `backdate_min` seulement pour celui qui écrit', function () {
    provAs($this->commerce)->getJson(provUrl($this->commerce))->assertOk()
        ->assertJsonPath('office_from', '2026-05-03')->assertJsonPath('backdate_min', '2026-04-19');
    provAs($this->comte)->getJson(provUrl($this->comte))->assertOk()
        ->assertJsonPath('office_from', '2026-05-03')->assertJsonPath('backdate_min', null);
});
