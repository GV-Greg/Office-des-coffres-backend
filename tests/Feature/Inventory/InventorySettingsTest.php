<?php

use App\Models\CharacterInventorySettings;
use App\Models\CityInventorySettings;
use App\Models\ProvinceInventorySettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/*
| Inventaires — réglages aux trois niveaux, texte collé chiffré (personnage et ville), copie entre personnages.
| Décisions de Greg et Cowork du 11/11/2026 (fils inventaire-scopes et inventaire-bilans) : personnel = le PERSONNAGE
| (catégorie A) ; ville = le maire ; province = le commissaire au commerce, le dirigeant lit seulement ; tout se résout
| par le poste tenu MAINTENANT, jamais par le corps de la requête.
*/

beforeEach(function () {
    $this->map = mandateMap();
    atGameTime('2026-05-03 12:00');
    $this->province = $this->map['provinceA'];
    $this->city = $this->map['cityA'];
    $this->mayor = mandatePlayer($this->city, ['pseudo' => 'Maire']);
    approvedMayor($this->mayor, $this->city, '2026-05-01');
    // Titulaires de la province d'Artois, résidents de Berry : la province est celle du POSTE.
    $this->commerce = mandatePlayer($this->map['cityB'], ['pseudo' => 'Marchand']);
    $this->mines = mandatePlayer($this->map['cityB'], ['pseudo' => 'Mineur']);
    $this->comte = mandatePlayer($this->map['cityB'], ['pseudo' => 'Comte']);
    approvedCouncil($this->commerce, $this->province, '2026-05-01', '2026-05-03', 'commissaire_commerce');
    approvedCouncil($this->mines, $this->province, '2026-05-01', '2026-05-03', 'commissaire_mines');
    approvedCouncil($this->comte, $this->province, '2026-05-01', '2026-05-03', 'leader');
    $this->plain = mandatePlayer($this->map['cityB'], ['pseudo' => 'Passant']);
    atGameTime('2026-05-10 12:00');
});

afterEach(fn () => Carbon::setTestNow());

function invUrl($character, string $path): string
{
    return "/api/v1/characters/{$character->id}/{$path}";
}

function invAs($character)
{
    Passport::actingAs($character->user);

    return test();
}

function invDoc(array $over = []): array
{
    return $over + [
        'custom' => [['key' => 'curiosites', 'fr' => 'Curiosités', 'en' => 'Curios']],
        'hiddenBuiltIn' => ['animaux'],
        'order' => ['curiosites', 'alimentation'],
        'exportColor' => 'darkcyan',
        'itemCategories' => ['Miches de pain' => 'alimentation', 'Clarabelle' => 'curiosites'],
    ];
}

// ─── Ce que le personnage peut gérer ─────────────────────────────────────────

test('scopes : personnel pour tous ; la ville pour le maire ; la province pour le commissaire au commerce et le dirigeant, lui en lecture seule', function () {
    invAs($this->plain)->getJson(invUrl($this->plain, 'inventory-scopes'))->assertOk()
        ->assertJsonPath('personal.can_write', true)->assertJsonPath('city', null)->assertJsonPath('province', null);

    invAs($this->mayor)->getJson(invUrl($this->mayor, 'inventory-scopes'))->assertOk()
        ->assertJsonPath('city.name', 'Arras')->assertJsonPath('city.can_write', true)->assertJsonPath('province', null);

    invAs($this->commerce)->getJson(invUrl($this->commerce, 'inventory-scopes'))->assertOk()
        ->assertJsonPath('province.name', 'Artois')->assertJsonPath('province.can_write', true)->assertJsonPath('city', null);

    invAs($this->comte)->getJson(invUrl($this->comte, 'inventory-scopes'))->assertOk()
        ->assertJsonPath('province.name', 'Artois')->assertJsonPath('province.can_write', false);

    // Le commissaire aux mines (et le bailli) n'ont AUCUN accès aux inventaires (Greg, 11/11/2026).
    invAs($this->mines)->getJson(invUrl($this->mines, 'inventory-scopes'))->assertOk()->assertJsonPath('province', null);
});

test("le personnage doit être celui du compte : le personnage d'un autre est introuvable", function () {
    invAs($this->plain)->getJson(invUrl($this->mayor, 'inventory-settings/personal'))
        ->assertNotFound()->assertJsonPath('code', 'character_not_found');
    invAs($this->plain)->putJson(invUrl($this->mayor, 'inventory-settings/personal'), ['data' => invDoc(), 'version' => 0])->assertNotFound();
});

// ─── Réglages personnels : lecture, écriture à version ───────────────────────

test('personnel : rien au départ, première écriture à la version 0, puis chaque écriture suit la version lue', function () {
    $c = $this->plain;
    invAs($c)->getJson(invUrl($c, 'inventory-settings/personal'))->assertOk()
        ->assertJsonPath('settings.data', null)->assertJsonPath('settings.version', 0)->assertJsonPath('settings.scope.level', 'personal');

    $this->putJson(invUrl($c, 'inventory-settings/personal'), ['data' => invDoc(), 'version' => 0])->assertOk()
        ->assertJsonPath('settings.version', 1)->assertJsonPath('settings.data.custom.0.key', 'curiosites')
        ->assertJsonPath('settings.data.itemCategories.Clarabelle', 'curiosites');

    $this->putJson(invUrl($c, 'inventory-settings/personal'), ['data' => invDoc(['exportColor' => 'darkred']), 'version' => 1])->assertOk()
        ->assertJsonPath('settings.version', 2)->assertJsonPath('settings.data.exportColor', 'darkred');
    expect(CharacterInventorySettings::where('character_id', $c->id)->count())->toBe(1);
});

test('une écriture sur une version périmée est refusée (409) et renvoie l\'état courant pour se recaler', function () {
    $c = $this->plain;
    invAs($c)->putJson(invUrl($c, 'inventory-settings/personal'), ['data' => invDoc(), 'version' => 0])->assertOk();

    $this->putJson(invUrl($c, 'inventory-settings/personal'), ['data' => invDoc(['exportColor' => 'darkred']), 'version' => 0])
        ->assertStatus(409)->assertJsonPath('code', 'version_conflict')
        ->assertJsonPath('current.version', 1)->assertJsonPath('current.data.exportColor', 'darkcyan')
        ->assertJsonStructure(['messages' => ['fr', 'en']]);
    expect(CharacterInventorySettings::where('character_id', $c->id)->value('version'))->toBe(1);
});

test('personnel : les options d\'export et la visibilité n\'existent que pour le personnel', function () {
    $personal = invDoc(['exportQtyFormat' => 'suffix', 'exportShowEcus' => false, 'exportWrapper' => 'quote',
        'visibility' => ['hiddenCategories' => ['divers'], 'hiddenItems' => ['Cailloux']]]);
    invAs($this->plain)->putJson(invUrl($this->plain, 'inventory-settings/personal'), ['data' => $personal, 'version' => 0])->assertOk()
        ->assertJsonPath('settings.data.exportWrapper', 'quote')->assertJsonPath('settings.data.visibility.hiddenItems.0', 'Cailloux');

    // Partagées entre titulaires d'un poste, ces réglages ne portent pas le goût d'un joueur.
    invAs($this->mayor)->putJson(invUrl($this->mayor, 'inventory-settings/city'), ['data' => $personal, 'version' => 0])
        ->assertStatus(422)->assertJsonPath('codes.data', 'document_unknown_key');
});

// ─── Ville : le maire ────────────────────────────────────────────────────────

test('ville : le maire lit et écrit les réglages de SA ville, rattachés à la ville et non à lui', function () {
    invAs($this->mayor)->putJson(invUrl($this->mayor, 'inventory-settings/city'), ['data' => invDoc(), 'version' => 0])->assertOk()
        ->assertJsonPath('settings.scope.name', 'Arras')->assertJsonPath('settings.version', 1);

    $row = CityInventorySettings::where('city_id', $this->city->id)->sole();
    expect($row->updated_by_character_id)->toBe($this->mayor->id);

    $this->getJson(invUrl($this->mayor, 'inventory-settings/city'))->assertOk()->assertJsonPath('settings.data.custom.0.fr', 'Curiosités');
});

test('ville : sans mandat de maire en cours, refus 403 en lecture comme en écriture', function () {
    foreach ([$this->plain, $this->commerce, $this->comte] as $who) {
        invAs($who)->getJson(invUrl($who, 'inventory-settings/city'))->assertForbidden()->assertJsonPath('code', 'city_not_authorized');
        $this->putJson(invUrl($who, 'inventory-settings/city'), ['data' => invDoc(), 'version' => 0])->assertForbidden();
    }
    expect(CityInventorySettings::count())->toBe(0);
});

test("ville : la fin du mandat retire l'accès, les réglages restent à la ville", function () {
    invAs($this->mayor)->putJson(invUrl($this->mayor, 'inventory-settings/city'), ['data' => invDoc(), 'version' => 0])->assertOk();

    atGameTime('2026-07-15 12:00'); // le mandat (30 jours) est fini depuis longtemps
    invAs($this->mayor)->getJson(invUrl($this->mayor, 'inventory-settings/city'))->assertForbidden();
    expect(CityInventorySettings::where('city_id', $this->city->id)->exists())->toBeTrue();
});

// ─── Province : le commissaire au commerce, le dirigeant lit ─────────────────

test('province : le commissaire au commerce écrit, le dirigeant lit seulement, les autres postes n\'ont aucun accès', function () {
    invAs($this->commerce)->putJson(invUrl($this->commerce, 'inventory-settings/province'), ['data' => invDoc(), 'version' => 0])->assertOk()
        ->assertJsonPath('settings.scope.name', 'Artois');
    expect(ProvinceInventorySettings::where('province_id', $this->province->id)->value('updated_by_character_id'))->toBe($this->commerce->id);

    // Le dirigeant lit …
    invAs($this->comte)->getJson(invUrl($this->comte, 'inventory-settings/province'))->assertOk()->assertJsonPath('settings.version', 1);
    // … mais n'écrit pas.
    $this->putJson(invUrl($this->comte, 'inventory-settings/province'), ['data' => invDoc(), 'version' => 1])
        ->assertForbidden()->assertJsonPath('code', 'province_not_authorized');

    // Le commissaire aux mines, le maire, un passant : ni lecture ni écriture.
    foreach ([$this->mines, $this->mayor, $this->plain] as $who) {
        invAs($who)->getJson(invUrl($who, 'inventory-settings/province'))->assertForbidden()->assertJsonPath('code', 'province_not_reader');
        $this->putJson(invUrl($who, 'inventory-settings/province'), ['data' => invDoc(), 'version' => 1])->assertForbidden();
    }
    expect(ProvinceInventorySettings::value('version'))->toBe(1);
});

// ─── Validation du document ──────────────────────────────────────────────────

test('un document refuse les clés inconnues, les formes fausses et les excès — rien n\'est écrit', function () {
    $c = $this->plain;
    $put = fn (array $data) => invAs($c)->putJson(invUrl($c, 'inventory-settings/personal'), ['data' => $data, 'version' => 0]);

    $put(['commentaire' => 'libre'])->assertStatus(422)->assertJsonPath('codes.data', 'document_unknown_key');
    $put(['custom' => [['key' => 'MAJUSCULES', 'fr' => 'X']]])->assertStatus(422)->assertJsonPath('codes.data', 'document_malformed');
    $put(['custom' => [['key' => 'a', 'fr' => 'A'], ['key' => 'a', 'fr' => 'B']]])->assertStatus(422)->assertJsonPath('codes.data', 'document_malformed');
    $put(['custom' => [['key' => 'a', 'fr' => '']]])->assertStatus(422)->assertJsonPath('codes.data', 'document_malformed');
    $put(['exportColor' => 'Rouge Vif'])->assertStatus(422)->assertJsonPath('codes.data', 'document_malformed');
    $put(['itemCategories' => ['Pain' => 'pas un slug!']])->assertStatus(422)->assertJsonPath('codes.data', 'document_malformed');
    $put(['order' => 'alimentation'])->assertStatus(422)->assertJsonPath('codes.data', 'document_malformed');
    $put(['exportWrapper' => 'html'])->assertStatus(422)->assertJsonPath('codes.data', 'document_malformed');

    $tooMany = array_map(fn ($i) => ['key' => "c$i", 'fr' => "Cat $i"], range(1, 31));
    $put(['custom' => $tooMany])->assertStatus(422)->assertJsonPath('codes.data', 'document_too_many');

    $huge = [];
    foreach (range(1, 2900) as $i) {
        $huge[str_repeat('x', 150)."-$i"] = 'alimentation';
    }
    $put(['itemCategories' => $huge])->assertStatus(422)->assertJsonPath('codes.data', 'document_too_large');

    // Chaque refus porte ses messages FR et EN.
    $put(['commentaire' => 'libre'])->assertJsonStructure(['messages' => ['data' => ['fr', 'en']]]);
    expect(CharacterInventorySettings::count())->toBe(0);
});

test("la version est exigée, et le document normalisé : l'anglais vide reprend le français", function () {
    $c = $this->plain;
    invAs($c)->putJson(invUrl($c, 'inventory-settings/personal'), ['data' => invDoc()])->assertStatus(422);
    $this->putJson(invUrl($c, 'inventory-settings/personal'), ['data' => ['custom' => [['key' => 'a', 'fr' => '  Armes  ', 'en' => '']]], 'version' => 0])->assertOk()
        ->assertJsonPath('settings.data.custom.0.fr', 'Armes')->assertJsonPath('settings.data.custom.0.en', 'Armes');
});

// ─── Copie vers un autre de ses personnages ──────────────────────────────────

test('copie : les réglages d\'un personnage passent à un autre personnage du même compte, sans le texte collé', function () {
    $a = $this->plain;
    $b = mandatePlayer($this->map['cityB'], ['user_id' => $a->user_id, 'pseudo' => 'Second']);
    invAs($a)->putJson(invUrl($a, 'inventory-settings/personal'), ['data' => invDoc(), 'version' => 0])->assertOk();
    $this->putJson(invUrl($a, 'inventory/personal'), ['raw' => "596Miches de pain2980\n"])->assertOk();

    $this->postJson(invUrl($b, 'inventory-settings/personal/copy'), ['from_character_id' => $a->id])->assertOk()
        ->assertJsonPath('settings.data.custom.0.key', 'curiosites')->assertJsonPath('settings.version', 1)
        ->assertJsonPath('settings.scope.name', 'Second');

    // Chaque personnage a SON inventaire : le texte collé ne se copie pas.
    $this->getJson(invUrl($b, 'inventory/personal'))->assertOk()->assertJsonPath('inventory.raw', null);
    // La source n'a pas bougé.
    expect(CharacterInventorySettings::where('character_id', $a->id)->value('version'))->toBe(1);
});

test('copie : remplacer des réglages existants demande confirmation ; jamais depuis le personnage d\'un autre compte', function () {
    $a = $this->plain;
    $b = mandatePlayer($this->map['cityB'], ['user_id' => $a->user_id, 'pseudo' => 'Second']);
    invAs($a)->putJson(invUrl($a, 'inventory-settings/personal'), ['data' => invDoc(), 'version' => 0])->assertOk();
    $this->putJson(invUrl($b, 'inventory-settings/personal'), ['data' => ['exportColor' => 'darkred'], 'version' => 0])->assertOk();

    $this->postJson(invUrl($b, 'inventory-settings/personal/copy'), ['from_character_id' => $a->id])
        ->assertStatus(409)->assertJsonPath('code', 'copy_needs_confirmation');
    expect(CharacterInventorySettings::where('character_id', $b->id)->value('data'))->toBe(['exportColor' => 'darkred']);

    $this->postJson(invUrl($b, 'inventory-settings/personal/copy'), ['from_character_id' => $a->id, 'confirm_replace' => true])->assertOk()
        ->assertJsonPath('settings.version', 2)->assertJsonPath('settings.data.exportColor', 'darkcyan');

    // Un personnage qui n'est pas sur le compte : introuvable, rien ne fuit.
    $this->postJson(invUrl($b, 'inventory-settings/personal/copy'), ['from_character_id' => $this->mayor->id, 'confirm_replace' => true])
        ->assertNotFound()->assertJsonPath('code', 'copy_source_not_found');
});

test('copie : pas sur soi-même, pas depuis un personnage sans réglages', function () {
    $a = $this->plain;
    $b = mandatePlayer($this->map['cityB'], ['user_id' => $a->user_id, 'pseudo' => 'Second']);
    invAs($a)->postJson(invUrl($a, 'inventory-settings/personal/copy'), ['from_character_id' => $a->id])
        ->assertStatus(422)->assertJsonPath('codes.source', 'copy_same_character');
    $this->postJson(invUrl($b, 'inventory-settings/personal/copy'), ['from_character_id' => $a->id])
        ->assertStatus(422)->assertJsonPath('codes.source', 'copy_source_empty');
});

// ─── Texte collé : personnage et ville, chiffré, un seul exemplaire ──────────

test('le texte collé est chiffré en base, relu en clair, et REMPLACÉ à chaque collage (un seul exemplaire)', function () {
    $c = $this->plain;
    invAs($c)->getJson(invUrl($c, 'inventory/personal'))->assertOk()->assertJsonPath('inventory.raw', null);

    // Laravel retire les espaces et retours à la ligne en bout de chaîne avant le contrôleur : le contenu est conservé tel quel.
    $first = "Encombrement : 100\n596Miches de pain2980";
    $this->putJson(invUrl($c, 'inventory/personal'), ['raw' => $first])->assertOk()->assertJsonPath('inventory.raw', $first);
    $stored = DB::table('character_inventory_settings')->where('character_id', $c->id)->value('inventory');
    expect($stored)->not->toContain('Miches de pain')->and($stored)->not->toContain('596');

    $second = "121Morceaux de viande605";
    $this->putJson(invUrl($c, 'inventory/personal'), ['raw' => $second])->assertOk();
    $this->getJson(invUrl($c, 'inventory/personal'))->assertOk()->assertJsonPath('inventory.raw', $second);
    expect(CharacterInventorySettings::where('character_id', $c->id)->count())->toBe(1);

    // Écrire le texte ne détruit pas les réglages, et inversement.
    $this->putJson(invUrl($c, 'inventory-settings/personal'), ['data' => invDoc(), 'version' => 1])->assertOk();
    $this->getJson(invUrl($c, 'inventory/personal'))->assertJsonPath('inventory.raw', $second);
});

test('texte collé : vide ou démesuré refusé ; ville réservée au maire ; la province passe par son historique', function () {
    $c = $this->plain;
    invAs($c)->putJson(invUrl($c, 'inventory/personal'), ['raw' => "   \n"])->assertStatus(422)->assertJsonPath('codes.raw', 'validation.required');
    $this->putJson(invUrl($c, 'inventory/personal'), ['raw' => str_repeat('x', 200001)])->assertStatus(422)->assertJsonPath('codes.raw', 'inventory_too_large');

    $this->putJson(invUrl($c, 'inventory/city'), ['raw' => 'x'])->assertForbidden()->assertJsonPath('code', 'city_not_authorized');
    invAs($this->mayor)->putJson(invUrl($this->mayor, 'inventory/city'), ['raw' => "3Cailloux0\n"])->assertOk()->assertJsonPath('inventory.scope.name', 'Arras');
    expect(DB::table('city_inventory_settings')->value('inventory'))->not->toContain('Cailloux');

    // Pas d'exemplaire unique remplacé pour la province : le PUT n'existe pas sur cette route (historique : GET et POST seulement).
    $this->putJson(invUrl($this->commerce, 'inventory/province'), ['raw' => 'x'])->assertStatus(405);
});

// ─── Cycle de vie : cascade (A) et survie (C) ────────────────────────────────

test('supprimer le personnage emporte ses réglages personnels (A) ; ceux de la ville et de la province restent, sans auteur (C)', function () {
    invAs($this->plain)->putJson(invUrl($this->plain, 'inventory-settings/personal'), ['data' => invDoc(), 'version' => 0])->assertOk();
    invAs($this->mayor)->putJson(invUrl($this->mayor, 'inventory-settings/city'), ['data' => invDoc(), 'version' => 0])->assertOk();
    invAs($this->commerce)->putJson(invUrl($this->commerce, 'inventory-settings/province'), ['data' => invDoc(), 'version' => 0])->assertOk();

    $this->plain->delete();
    $this->mayor->delete();
    $this->commerce->delete();

    expect(CharacterInventorySettings::count())->toBe(0)
        ->and(CityInventorySettings::sole()->updated_by_character_id)->toBeNull()
        ->and(ProvinceInventorySettings::sole()->updated_by_character_id)->toBeNull()
        ->and(CityInventorySettings::sole()->data['custom'][0]['key'])->toBe('curiosites');
});

// ─── Export du compte ────────────────────────────────────────────────────────

test('l\'export du compte porte les réglages et le dernier inventaire du joueur, jamais ceux de la ville ni de la province', function () {
    invAs($this->mayor)->putJson(invUrl($this->mayor, 'inventory-settings/personal'), ['data' => invDoc(), 'version' => 0])->assertOk();
    $this->putJson(invUrl($this->mayor, 'inventory/personal'), ['raw' => "596Miches de pain2980\n"])->assertOk();
    $this->putJson(invUrl($this->mayor, 'inventory-settings/city'), ['data' => invDoc(['exportColor' => 'darkred']), 'version' => 0])->assertOk();

    $export = $this->getJson('/api/v1/auth/account/export')->assertOk()->json();

    expect($export['inventory'])->toHaveCount(1)
        ->and($export['inventory'][0]['character'])->toBe('Maire')
        ->and($export['inventory'][0]['settings']['exportColor'])->toBe('darkcyan')
        ->and($export['inventory'][0]['last_inventory'])->toContain('Miches de pain')
        ->and(json_encode($export))->not->toContain('darkred')
        ->and($export['not_included'])->toHaveKey('shared_data');
});
