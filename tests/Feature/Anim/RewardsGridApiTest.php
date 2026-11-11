<?php

use App\Models\AnimRewardsGrid;
use App\Models\AnimRewardsList;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

/*
| Grille des lots — API mode PRIVÉ (RewardsGridController) + lecture publique par slug.
| Machine d'état via API : création → lots → tirage → confirmation → assignation → clôture.
| Contrat de refus bilingue (AnimApiErrors) sur chaque violation.
*/

beforeEach(function () {
    $this->map = mandateMap();
    activateRewardsType();
    $this->orga = mandatePlayer($this->map['cityA']);
    $this->other = mandatePlayer($this->map['cityB']);
    $this->base = "/api/v1/characters/{$this->orga->id}/rewards-grids";
});

function createGrid($test, array $extra = []): TestResponse
{
    Passport::actingAs($test->orga->user);
    $place = array_intersect_key($extra, array_flip(['kingdom_id', 'province_id', 'city_id'])) ? [] : ['province_id' => $test->map['provinceA']->id];

    return $test->postJson($test->base, [
        'slug' => 'grille-des-marches',
        'name' => 'Grille des Marches',
        'width' => 3,
        'height' => 3,
    ] + $extra + $place);
}

// ─── Création ────────────────────────────────────────────────────────────────

test('créer une grille privée : 201, slug, mode, statut new', function () {
    createGrid($this)
        ->assertCreated()
        ->assertJsonPath('grid.name', 'Grille des Marches')
        ->assertJsonPath('grid.slug', 'grille-des-marches')
        ->assertJsonPath('grid.mode', 'private')
        ->assertJsonPath('grid.status', 'new')
        ->assertJsonPath('grid.width', 3)
        ->assertJsonPath('grid.height', 3);
});

test('slug invalide : 422 bilingue codes.slug', function () {
    Passport::actingAs($this->orga->user);
    $this->postJson($this->base, ['slug' => 'grille-123', 'name' => 'Grille X', 'width' => 3, 'height' => 3, 'province_id' => $this->map['provinceA']->id])
        ->assertStatus(422)
        ->assertJsonPath('codes.slug', 'slug_invalid')
        ->assertJsonStructure(['messages' => ['slug' => ['fr', 'en']]]);
});

test('dimensions hors 3–20 : 422 codes.dimensions, le message donne les bornes', function () {
    Passport::actingAs($this->orga->user);
    foreach ([[2, 3], [21, 3], [3, 21]] as [$w, $h]) {
        $this->postJson($this->base, ['slug' => 'grille-x', 'name' => 'Grille X', 'width' => $w, 'height' => $h, 'province_id' => $this->map['provinceA']->id])
            ->assertStatus(422)
            ->assertJsonPath('codes.dimensions', 'grid_dimensions_invalid')
            ->assertJsonPath('messages.dimensions.fr', 'Les dimensions de la grille doivent être comprises entre 3 et 20.')
            ->assertJsonPath('messages.dimensions.en', 'Grid dimensions must be between 3 and 20.');
    }
});

test('lieu manquant : 422 codes.place', function () {
    Passport::actingAs($this->orga->user);
    $this->postJson($this->base, ['slug' => 'grille-x', 'name' => 'Grille X', 'width' => 3, 'height' => 3])
        ->assertStatus(422)
        ->assertJsonPath('codes.place', 'place_required');
});

test('deux niveaux de lieu : 422 codes.place place_exclusive', function () {
    Passport::actingAs($this->orga->user);
    $this->postJson($this->base, ['slug' => 'grille-x', 'name' => 'Grille X', 'width' => 3, 'height' => 3, 'kingdom_id' => $this->map['provinceA']->kingdom_id, 'province_id' => $this->map['provinceA']->id])
        ->assertStatus(422)
        ->assertJsonPath('codes.place', 'place_exclusive');
});

// ─── Lecture publique ─────────────────────────────────────────────────────────

test('lecture publique : 404 avant drawed, 200 depuis drawed', function () {
    Passport::actingAs($this->orga->user);
    createGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();

    // Pas encore visible (statut new).
    $this->getJson("/api/v1/anim/rewards-grids/{$grid->slug}")->assertNotFound();

    // Remplir et tirer.
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['names' => ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I']])->assertCreated();
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/draw")->assertOk();

    // Visible depuis confirmed (draw → confirmed en un seul geste).
    $this->getJson("/api/v1/anim/rewards-grids/{$grid->slug}")->assertOk()
        ->assertJsonPath('grid.status', 'confirmed');
});

test('lecture publique : noms des lots masqués (suspense) avant close', function () {
    Passport::actingAs($this->orga->user);
    createGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();

    for ($i = 1; $i <= 9; $i++) {
        $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['name' => "Lot $i"])->assertCreated();
    }
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/draw")->assertOk();

    $response = $this->getJson("/api/v1/anim/rewards-grids/{$grid->slug}")->assertOk();
    $lots = $response->json('grid.lots');
    // Les noms des cases non prises doivent être masqués.
    foreach ($lots as $lot) {
        if ($lot['player'] === null && ! $lot['is_taken']) {
            expect($lot['name'])->toBeNull();
        }
    }
});

// ─── Plusieurs lots en une requête ─────────────────────────────────

test('plusieurs lots en UNE requête : 201, tous créés dans l\'ordre, statut recalculé une fois', function () {
    Passport::actingAs($this->orga->user);
    createGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();

    $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['names' => ['Pain', 'Épée']])
        ->assertCreated()
        ->assertJsonPath('grid.status', 'incomplete')
        ->assertJsonCount(2, 'grid.lots');

    $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['names' => ['Bouclier', 'Casque', 'Heaume', 'Lance', 'Arc', 'Dague', 'Torche']])
        ->assertCreated()
        ->assertJsonPath('grid.status', 'filled');
    expect(AnimRewardsList::orderBy('id')->pluck('name')->all())->toBe(['Pain', 'Épée', 'Bouclier', 'Casque', 'Heaume', 'Lance', 'Arc', 'Dague', 'Torche']);
});

test('une quantité n\'est que des doublons : deux lots peuvent porter le même nom', function () {
    Passport::actingAs($this->orga->user);
    createGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();

    $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['names' => ['50 écus', '50 écus', '50 écus']])->assertCreated();

    expect(AnimRewardsList::where('name', '50 écus')->count())->toBe(3);
});

test('une liste qui dépasse les cases libres est refusée EN ENTIER : rien n\'est ajouté', function () {
    Passport::actingAs($this->orga->user);
    createGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['name' => 'Pain'])->assertCreated();

    $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['names' => ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I']])
        ->assertStatus(422)
        ->assertJsonPath('codes.name', 'grid_full');

    expect(AnimRewardsList::count())->toBe(1);
    expect($grid->refresh()->status)->toBe('incomplete');
});

test('forme d\'un ajout : ni name ni names, liste vide, nom vide ou trop long = 422, rien n\'est ajouté', function () {
    Passport::actingAs($this->orga->user);
    createGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();
    $url = "/api/v1/rewards-grids/{$grid->id}/lots";

    $this->postJson($url, [])->assertStatus(422);
    $this->postJson($url, ['names' => []])->assertStatus(422);
    $this->postJson($url, ['names' => ['Pain', '']])->assertStatus(422);
    $this->postJson($url, ['names' => ['Pain', str_repeat('x', 51)]])->assertStatus(422);
    $this->postJson($url, ['names' => 'Pain'])->assertStatus(422);

    expect(AnimRewardsList::count())->toBe(0);
});

test('plusieurs lots : seul l\'organisateur ; refusé aussi une fois la grille tirée', function () {
    Passport::actingAs($this->orga->user);
    createGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();

    Passport::actingAs($this->other->user);
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['names' => ['A', 'B']])
        ->assertForbidden()
        ->assertJsonPath('codes.activity', 'not_owner');

    Passport::actingAs($this->orga->user);
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['names' => ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I']])->assertCreated();
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/draw")->assertOk();
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['names' => ['E']])
        ->assertStatus(422)
        ->assertJsonPath('codes.activity', 'grid_not_editable');
});

// ─── Workflow complet ─────────────────────────────────────────────────────────

test('workflow complet : créer → lots → draw → assign → markTaken → close', function () {
    Passport::actingAs($this->orga->user);
    createGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();

    // Lots.
    for ($i = 1; $i <= 9; $i++) {
        $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['name' => "Lot $i"])->assertCreated()
            ->assertJsonPath('grid.status', $i < 9 ? 'incomplete' : 'filled');
    }

    // Draw → confirmed directement (tirage + confirmation en un geste).
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/draw")->assertOk()
        ->assertJsonPath('grid.status', 'confirmed');

    // Assign.
    $lotId = AnimRewardsList::first()->id;
    $this->patchJson("/api/v1/rewards-grids/{$grid->id}/lots/{$lotId}/assign", ['player' => 'Brunehaut'])
        ->assertOk();

    // markTaken.
    $this->patchJson("/api/v1/rewards-grids/{$grid->id}/lots/{$lotId}/taken", ['is_taken' => true])
        ->assertOk();

    // Close.
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/close")->assertOk()
        ->assertJsonPath('grid.status', 'closed');
});

test('retirer un lot et vérifier le statut', function () {
    Passport::actingAs($this->orga->user);
    createGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();

    for ($i = 1; $i <= 9; $i++) {
        $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['name' => "Lot $i"])->assertCreated();
    }
    // Statut filled, en retirer un.
    $lotId = AnimRewardsList::first()->id;
    $this->deleteJson("/api/v1/rewards-grids/{$grid->id}/lots/{$lotId}")->assertOk()
        ->assertJsonPath('grid.status', 'incomplete');
});

test('réouverture d\'une grille close', function () {
    Passport::actingAs($this->orga->user);
    createGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();
    for ($i = 1; $i <= 9; $i++) {
        $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['name' => "Lot $i"]);
    }
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/draw");
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/close");

    $this->postJson("/api/v1/rewards-grids/{$grid->id}/reopen")->assertOk()
        ->assertJsonPath('grid.status', 'confirmed');
});

test('suppression d\'une grille close', function () {
    Passport::actingAs($this->orga->user);
    createGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/close");
    $this->deleteJson("/api/v1/rewards-grids/{$grid->id}")->assertOk();
    expect(AnimRewardsGrid::count())->toBe(0);
});

// ─── Accès refusé ─────────────────────────────────────────────────────────────

test('403 not_owner : un autre personnage ne peut rien faire', function () {
    Passport::actingAs($this->orga->user);
    createGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();

    Passport::actingAs($this->other->user);

    $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['name' => 'X'])->assertStatus(403)
        ->assertJsonPath('codes.activity', 'not_owner');
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/draw")->assertStatus(403);
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/close")->assertStatus(403);
    $this->deleteJson("/api/v1/rewards-grids/{$grid->id}")->assertStatus(403);
});

test('non authentifié : 401', function () {
    $this->postJson($this->base, ['slug' => 'x', 'name' => 'X', 'width' => 3, 'height' => 3])->assertUnauthorized();
});
