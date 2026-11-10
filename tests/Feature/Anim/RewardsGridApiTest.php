<?php

use App\Models\AnimRewardsGrid;
use App\Models\AnimRewardsList;
use Laravel\Passport\Passport;

/*
| Grille des lots — API mode PRIVÉ (RewardsGridController) + lecture publique par slug.
| Machine d'état via API : création → lots → tirage → confirmation → assignation → clôture.
| Contrat de refus bilingue (AnimApiErrors) sur chaque violation.
*/

beforeEach(function () {
    $this->map = mandateMap();
    $this->orga = mandatePlayer($this->map['cityA']);
    $this->other = mandatePlayer($this->map['cityB']);
    $this->base = "/api/v1/characters/{$this->orga->id}/rewards-grids";
});

function createGrid($test, array $extra = []): \Illuminate\Testing\TestResponse
{
    Passport::actingAs($test->orga->user);
    $place = array_intersect_key($extra, array_flip(['kingdom_id', 'province_id', 'city_id'])) ? [] : ['province_id' => $test->map['provinceA']->id];

    return $test->postJson($test->base, [
        'slug' => 'grille-des-marches',
        'name' => 'Grille des Marches',
        'width' => 2,
        'height' => 2,
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
        ->assertJsonPath('grid.width', 2)
        ->assertJsonPath('grid.height', 2);
});

test('slug invalide : 422 bilingue codes.slug', function () {
    Passport::actingAs($this->orga->user);
    $this->postJson($this->base, ['slug' => 'grille-123', 'name' => 'Grille X', 'width' => 2, 'height' => 2, 'province_id' => $this->map['provinceA']->id])
        ->assertStatus(422)
        ->assertJsonPath('codes.slug', 'slug_invalid')
        ->assertJsonStructure(['messages' => ['slug' => ['fr', 'en']]]);
});

test('dimensions hors 1–10 : 422 codes.dimensions', function () {
    Passport::actingAs($this->orga->user);
    $this->postJson($this->base, ['slug' => 'grille-x', 'name' => 'Grille X', 'width' => 0, 'height' => 2, 'province_id' => $this->map['provinceA']->id])
        ->assertStatus(422)
        ->assertJsonPath('codes.dimensions', 'grid_dimensions_invalid');
});

test('lieu manquant : 422 codes.place', function () {
    Passport::actingAs($this->orga->user);
    $this->postJson($this->base, ['slug' => 'grille-x', 'name' => 'Grille X', 'width' => 2, 'height' => 2])
        ->assertStatus(422)
        ->assertJsonPath('codes.place', 'place_required');
});

test('deux niveaux de lieu : 422 codes.place place_exclusive', function () {
    Passport::actingAs($this->orga->user);
    $this->postJson($this->base, ['slug' => 'grille-x', 'name' => 'Grille X', 'width' => 2, 'height' => 2, 'kingdom_id' => $this->map['provinceA']->kingdom_id, 'province_id' => $this->map['provinceA']->id])
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
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['name' => 'A'])->assertCreated();
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['name' => 'B'])->assertCreated();
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['name' => 'C'])->assertCreated();
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['name' => 'D'])->assertCreated();
    $this->postJson("/api/v1/rewards-grids/{$grid->id}/draw")->assertOk();

    // Visible depuis confirmed (draw → confirmed en un seul geste).
    $this->getJson("/api/v1/anim/rewards-grids/{$grid->slug}")->assertOk()
        ->assertJsonPath('grid.status', 'confirmed');
});

test('lecture publique : noms des lots masqués (suspense) avant close', function () {
    Passport::actingAs($this->orga->user);
    createGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();

    for ($i = 1; $i <= 4; $i++) {
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

// ─── Workflow complet ─────────────────────────────────────────────────────────

test('workflow complet : créer → lots → draw → assign → markTaken → close', function () {
    Passport::actingAs($this->orga->user);
    createGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();

    // Lots.
    for ($i = 1; $i <= 4; $i++) {
        $this->postJson("/api/v1/rewards-grids/{$grid->id}/lots", ['name' => "Lot $i"])->assertCreated()
            ->assertJsonPath('grid.status', $i < 4 ? 'incomplete' : 'filled');
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

    for ($i = 1; $i <= 4; $i++) {
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
    for ($i = 1; $i <= 4; $i++) {
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
    $this->postJson($this->base, ['slug' => 'x', 'name' => 'X', 'width' => 2, 'height' => 2])->assertUnauthorized();
});
