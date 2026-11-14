<?php

use App\Models\ItemIcon;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;

/*
|--------------------------------------------------------------------------
| Référentiel des icônes d'items
|--------------------------------------------------------------------------
| Brief : admin/content/brief-icones-items.md. Fil : admin/echanges/icones-items/.
| Trois surfaces : la page Blade (session, rôle admin), l'API JSON admin (Passport, rôle admin)
| et la lecture publique. Aucune image n'est stockée : seul l'ID Celsius l'est.
*/

function iconsAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

// ─── Modèle ──────────────────────────────────────────────────────────────────

test('l\'URL de l\'icône est construite à la volée sur le CDN de Celsius', function () {
    $icon = ItemIcon::create(['item_name' => 'Miches de pain', 'game_item_id' => 50]);

    expect($icon->iconUrl())->toBe('https://lesroyaumes.cdn.oxv.fr/images/items/item50.png');
});

test('la table ne stocke aucune URL ni image', function () {
    expect(Schema::getColumnListing('item_icons'))
        ->toEqualCanonicalizing(['id', 'item_name', 'game_item_id', 'created_at', 'updated_at']);
});

test('normalizeName ignore la casse et les espaces superflus, mais pas les accents', function () {
    expect(ItemIcon::normalizeName('  Miches   DE pain '))->toBe('miches de pain')
        ->and(ItemIcon::normalizeName('SACS DE MAÏS'))->toBe('sacs de maïs')
        ->and(ItemIcon::normalizeName('Mais'))->not->toBe(ItemIcon::normalizeName('Maïs'));
});

// ─── Lecture publique ────────────────────────────────────────────────────────

test('lecture publique : accessible sans authentification', function () {
    ItemIcon::create(['item_name' => 'Miches de pain', 'game_item_id' => 50]);

    $this->getJson('/api/v1/item-icons?names[]=Miches de pain')
        ->assertOk()
        ->assertExactJson(['success' => true, 'icons' => ['Miches de pain' => 50]]);
});

test('lecture publique : la clé est le nom DEMANDÉ, pas le nom enregistré', function () {
    ItemIcon::create(['item_name' => 'Miches de pain', 'game_item_id' => 50]);

    $this->getJson('/api/v1/item-icons?names[]=miches DE pain')
        ->assertOk()
        ->assertExactJson(['success' => true, 'icons' => ['miches DE pain' => 50]]);
});

test('lecture publique : les noms sans icône sont omis, et rien ne correspond → un objet vide', function () {
    ItemIcon::create(['item_name' => 'Miches de pain', 'game_item_id' => 50]);

    $mixed = $this->getJson('/api/v1/item-icons?names[]=Miches de pain&names[]=Inconnu')->assertOk();
    expect($mixed->json('icons'))->toBe(['Miches de pain' => 50]);

    // `{}` et non `[]` : le contenu brut doit être un objet JSON.
    $none = $this->getJson('/api/v1/item-icons?names[]=Inconnu')->assertOk();
    expect($none->getContent())->toContain('"icons":{}');
});

test('lecture publique : les accents comptent', function () {
    ItemIcon::create(['item_name' => 'Sacs de maïs', 'game_item_id' => 52]);

    $this->getJson('/api/v1/item-icons?names[]=Sacs de mais&names[]=Sacs de maïs')
        ->assertOk()
        ->assertExactJson(['success' => true, 'icons' => ['Sacs de maïs' => 52]]);
});

test('lecture publique : un nom répété ne sort qu\'une fois', function () {
    ItemIcon::create(['item_name' => 'Miches de pain', 'game_item_id' => 50]);

    $this->getJson('/api/v1/item-icons?names[]=Miches de pain&names[]=Miches de pain')
        ->assertOk()
        ->assertExactJson(['success' => true, 'icons' => ['Miches de pain' => 50]]);
});

test('lecture publique : cache de 24 h avec revalidation, comme le brief le demande', function () {
    ItemIcon::create(['item_name' => 'Miches de pain', 'game_item_id' => 50]);

    $cc = $this->getJson('/api/v1/item-icons?names[]=Miches de pain')->assertOk()->headers->get('Cache-Control');

    expect($cc)->toContain('public')
        ->toContain('max-age=86400')
        ->toContain('stale-while-revalidate=3600');

    // Une réponse d'erreur ne se met jamais en cache.
    $err = $this->getJson('/api/v1/item-icons')->assertStatus(422)->headers->get('Cache-Control');
    expect($err)->not->toContain('max-age=86400');
});

test('lecture publique : validation de la liste de noms', function () {
    $this->getJson('/api/v1/item-icons')->assertStatus(422)->assertJsonValidationErrors(['names']);

    $tooMany = http_build_query(['names' => array_map(fn ($i) => "Item {$i}", range(1, 201))]);
    $this->getJson("/api/v1/item-icons?{$tooMany}")->assertStatus(422)->assertJsonValidationErrors(['names']);

    $tooLong = http_build_query(['names' => [str_repeat('a', 151)]]);
    $this->getJson("/api/v1/item-icons?{$tooLong}")->assertStatus(422);
});

// ─── API JSON admin ──────────────────────────────────────────────────────────

test('API admin : 401 sans jeton, 403 pour un non-admin', function () {
    $this->getJson('/api/v1/admin/item-icons')->assertUnauthorized();
    $this->postJson('/api/v1/admin/item-icons', ['item_name' => 'X', 'game_item_id' => 1])->assertUnauthorized();

    Passport::actingAs(User::factory()->create());
    $this->getJson('/api/v1/admin/item-icons')->assertForbidden();
    $this->postJson('/api/v1/admin/item-icons', ['item_name' => 'X', 'game_item_id' => 1])->assertForbidden();
    expect(ItemIcon::count())->toBe(0);
});

test('API admin : un admin crée, liste, corrige et supprime', function () {
    Passport::actingAs(iconsAdmin());

    $created = $this->postJson('/api/v1/admin/item-icons', ['item_name' => 'Miches de pain', 'game_item_id' => 50])
        ->assertCreated()
        ->assertJsonPath('icon.icon_url', 'https://lesroyaumes.cdn.oxv.fr/images/items/item50.png');
    $id = $created->json('icon.id');

    $this->getJson('/api/v1/admin/item-icons?search=pain')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('icons.0.item_name', 'Miches de pain');
    $this->getJson('/api/v1/admin/item-icons?search=zzz')->assertOk()->assertJsonPath('meta.total', 0);

    $this->putJson("/api/v1/admin/item-icons/{$id}", ['game_item_id' => 51])
        ->assertOk()
        ->assertJsonPath('icon.game_item_id', 51);
    expect(ItemIcon::find($id)->game_item_id)->toBe(51);

    $this->deleteJson("/api/v1/admin/item-icons/{$id}")->assertOk();
    expect(ItemIcon::count())->toBe(0);
});

test('API admin : doublons et valeurs invalides refusés', function () {
    Passport::actingAs(iconsAdmin());
    ItemIcon::create(['item_name' => 'Miches de pain', 'game_item_id' => 50]);

    $this->postJson('/api/v1/admin/item-icons', ['item_name' => 'Miches de pain', 'game_item_id' => 51])
        ->assertStatus(422)->assertJsonValidationErrors(['item_name']);
    $this->postJson('/api/v1/admin/item-icons', ['item_name' => 'Autre', 'game_item_id' => 50])
        ->assertStatus(422)->assertJsonValidationErrors(['game_item_id']);
    $this->postJson('/api/v1/admin/item-icons', ['item_name' => 'Autre', 'game_item_id' => 0])
        ->assertStatus(422)->assertJsonValidationErrors(['game_item_id']);
    $this->postJson('/api/v1/admin/item-icons', ['item_name' => '', 'game_item_id' => 'abc'])
        ->assertStatus(422)->assertJsonValidationErrors(['item_name', 'game_item_id']);
    expect(ItemIcon::count())->toBe(1);
});

test('API admin : corriger un ID vers l\'ID d\'une autre icône est refusé, vers le sien est permis', function () {
    Passport::actingAs(iconsAdmin());
    $a = ItemIcon::create(['item_name' => 'A', 'game_item_id' => 1]);
    ItemIcon::create(['item_name' => 'B', 'game_item_id' => 2]);

    $this->putJson("/api/v1/admin/item-icons/{$a->id}", ['game_item_id' => 2])
        ->assertStatus(422)->assertJsonValidationErrors(['game_item_id']);
    $this->putJson("/api/v1/admin/item-icons/{$a->id}", ['game_item_id' => 1])->assertOk();
});

// ─── Page Blade admin ────────────────────────────────────────────────────────

test('page admin : invité redirigé, non-admin refusé', function () {
    $this->get('/item-icons')->assertRedirect('/login');

    $this->actingAs(User::factory()->create())->get('/item-icons')->assertForbidden();
});

test('page admin : la liste, la mention de propriété Celsius et le lien du menu s\'affichent', function () {
    ItemIcon::create(['item_name' => 'Miches de pain', 'game_item_id' => 50]);

    $this->actingAs(iconsAdmin())->get('/item-icons')
        ->assertOk()
        ->assertSee('Miches de pain')
        ->assertSee('item50.png')
        ->assertSee('propriété de Celsius Online');
});

test('page admin : un admin associe, corrige et retire une icône', function () {
    $admin = iconsAdmin();

    $this->actingAs($admin)
        ->post('/item-icons', ['item_name' => 'Miches de pain', 'game_item_id' => 50])
        ->assertSessionHas('status', __('item_icons.created'));
    $icon = ItemIcon::firstOrFail();

    $this->actingAs($admin)
        ->patch("/item-icons/{$icon->id}", ['game_item_id' => 51])
        ->assertSessionHas('status', __('item_icons.updated'));
    expect($icon->fresh()->game_item_id)->toBe(51);

    $this->actingAs($admin)
        ->delete("/item-icons/{$icon->id}")
        ->assertSessionHas('status', __('item_icons.deleted'));
    expect(ItemIcon::count())->toBe(0);
});

test('page admin : doublon refusé avec message d\'erreur', function () {
    $admin = iconsAdmin();
    ItemIcon::create(['item_name' => 'Miches de pain', 'game_item_id' => 50]);

    $this->actingAs($admin)
        ->post('/item-icons', ['item_name' => 'Miches de pain', 'game_item_id' => 51])
        ->assertSessionHasErrors(['item_name']);
    expect(ItemIcon::count())->toBe(1);
});

test('page admin : la recherche filtre la liste', function () {
    ItemIcon::create(['item_name' => 'Miches de pain', 'game_item_id' => 50]);
    ItemIcon::create(['item_name' => 'Sacs de maïs', 'game_item_id' => 52]);

    $this->actingAs(iconsAdmin())->get('/item-icons?search=maïs')
        ->assertOk()
        ->assertSee('Sacs de maïs')
        ->assertDontSee('Miches de pain');
});

// ─── Garde-fous de la vue ────────────────────────────────────────────────────

test('la vue n\'injecte aucune chaîne française à apostrophe dans un JavaScript entre guillemets simples', function () {
    // Régression du 13/11/2026 : « Retirer l'icône… » rompait le `confirm('…')` une fois l'entité
    // décodée par le navigateur ; l'événement ne se compilait pas et la suppression partait sans
    // confirmation. Les deux chaînes passent par @js().
    ItemIcon::create(['item_name' => 'Miches de pain', 'game_item_id' => 50]);

    $html = $this->actingAs(iconsAdmin())->get('/item-icons')->assertOk()->getContent();

    expect($html)
        // L'apostrophe est échappée en ' dans le JavaScript…
        ->toContain("confirm('Retirer l\\u0027")
        ->toContain('Aper\\u00e7u de l\\u0027ic')
        // …et jamais brute ni en entité (qui redeviendrait une apostrophe dans l'attribut).
        ->not->toContain("Retirer l'icône")
        ->not->toContain('Retirer l&#039;')
        ->not->toContain('Aperçu de l&#039;');
});

test('l\'URL du CDN vit à un seul endroit : la constante du modèle', function () {
    $view = file_get_contents(dirname(__DIR__, 3).'/resources/views/item-icons/index.blade.php');

    expect($view)->not->toContain('lesroyaumes.cdn.oxv.fr')
        ->and($view)->toContain('ItemIcon::CDN_URL_PATTERN');
});
