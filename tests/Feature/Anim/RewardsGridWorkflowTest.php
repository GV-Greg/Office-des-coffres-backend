<?php

use App\Models\AnimActivityType;
use App\Models\AnimRewardsGrid;
use App\Models\AnimRewardsList;
use App\Services\AnimActor;
use App\Services\RewardsGridWorkflow;
use Illuminate\Support\Facades\DB;

/*
| Grille des lots — machine d'état, autorisations, lots, tirage, assignation, clôture, réouverture.
| Règle de base : autorisation PAR PROPRIÉTÉ (AnimActor), jamais par mandat.
| Catégorie C : character_id nullOnDelete — une suppression de compte ne supprime PAS la grille.
*/

beforeEach(function () {
    $this->map = mandateMap();
    $this->type = AnimActivityType::where('key', 'rewards')->firstOrFail();
    $this->orga = mandatePlayer($this->map['cityA']);
    $this->other = mandatePlayer($this->map['cityB']);
    $this->workflow = app(RewardsGridWorkflow::class);
});

function rewardsGrid($test, string $name = 'Grille des Marches', array $extra = []): AnimRewardsGrid
{
    return $test->workflow->create($test->orga, $test->type, [
        'slug' => 'grille-des-marches',
        'name' => $name,
        'width' => 2,
        'height' => 2,
        'province_id' => $test->map['provinceA']->id,
    ] + $extra);
}

/** Remplit la grille avec width*height lots et retourne la grille rafraîchie. */
function fillGrid(RewardsGridWorkflow $workflow, $actor, AnimRewardsGrid $grid): AnimRewardsGrid
{
    $n = $grid->capacity();
    for ($i = 1; $i <= $n; $i++) {
        $workflow->addLot($actor, $grid->refresh(), "Lot $i");
    }

    return $grid->refresh();
}

function wfRefusal(callable $call): \App\Exceptions\MandateRefusal
{
    try {
        $call();
    } catch (\App\Exceptions\MandateRefusal $e) {
        return $e;
    }
    throw new \RuntimeException('Un refus était attendu.');
}

// ─── Création ────────────────────────────────────────────────────────────────

test('créer une grille privée : statut new, is_anonymous false, dimensions enregistrées', function () {
    $grid = rewardsGrid($this);

    expect($grid->status)->toBe('new')
        ->and($grid->is_anonymous)->toBeFalse()
        ->and($grid->width)->toBe(2)
        ->and($grid->height)->toBe(2)
        ->and($grid->character_id)->toBe($this->orga->id);
});

test('slug invalide ou déjà pris : refus bilingue', function () {
    $e = wfRefusal(fn () => $this->workflow->create($this->orga, $this->type, ['slug' => 'grille-123', 'name' => 'X', 'width' => 2, 'height' => 2, 'province_id' => $this->map['provinceA']->id]));
    expect($e->codes)->toBe(['slug' => 'slug_invalid']);

    rewardsGrid($this);

    $e2 = wfRefusal(fn () => $this->workflow->create($this->other, $this->type, ['slug' => 'grille-des-marches', 'name' => 'Autre', 'width' => 2, 'height' => 2, 'province_id' => $this->map['provinceA']->id]));
    expect($e2->codes)->toBe(['slug' => 'slug_taken']);
});

test('dimensions hors 1–10 : refusées', function () {
    $e = wfRefusal(fn () => $this->workflow->create($this->orga, $this->type, ['slug' => 'grille-x', 'name' => 'X', 'width' => 0, 'height' => 2, 'province_id' => $this->map['provinceA']->id]));
    expect($e->codes)->toBe(['dimensions' => 'grid_dimensions_invalid']);

    $e2 = wfRefusal(fn () => $this->workflow->create($this->orga, $this->type, ['slug' => 'grille-x', 'name' => 'X', 'width' => 11, 'height' => 2, 'province_id' => $this->map['provinceA']->id]));
    expect($e2->codes)->toBe(['dimensions' => 'grid_dimensions_invalid']);
});

test('deux niveaux de lieu à la fois : refusé', function () {
    $e = wfRefusal(fn () => $this->workflow->create($this->orga, $this->type, [
        'slug' => 'grille-x', 'name' => 'X', 'width' => 2, 'height' => 2,
        'kingdom_id' => $this->map['provinceA']->kingdom_id,
        'province_id' => $this->map['provinceA']->id,
    ]));
    expect($e->codes)->toBe(['place' => 'place_exclusive']);
});

test('type désactivé : refusé', function () {
    $this->type->update(['is_active' => false]);
    $e = wfRefusal(fn () => rewardsGrid($this));
    expect($e->codes)->toBe(['type' => 'type_inactive']);
});

test('une seule grille non close par type et par personnage', function () {
    rewardsGrid($this);
    $e = wfRefusal(fn () => $this->workflow->create($this->orga, $this->type, ['slug' => 'autre-grille', 'name' => 'Autre', 'width' => 1, 'height' => 1, 'province_id' => $this->map['provinceA']->id]));
    expect($e->codes)->toBe(['name' => 'activity_already_active']);
});

// ─── Lots et transitions d'état ──────────────────────────────────────────────

test('ajouter un lot : new → incomplete → filled, statut mis à jour', function () {
    $grid = rewardsGrid($this); // 2×2

    $this->workflow->addLot($this->orga, $grid, 'Épée');
    expect($grid->refresh()->status)->toBe('incomplete');

    $this->workflow->addLot($this->orga, $grid->refresh(), 'Bouclier');
    $this->workflow->addLot($this->orga, $grid->refresh(), 'Casque');
    $this->workflow->addLot($this->orga, $grid->refresh(), 'Armure');
    expect($grid->refresh()->status)->toBe('filled')
        ->and($grid->lots()->count())->toBe(4);
});

test('grille remplie : un lot de plus est refusé', function () {
    $grid = rewardsGrid($this);
    fillGrid($this->workflow, $this->orga, $grid);

    $e = wfRefusal(fn () => $this->workflow->addLot($this->orga, $grid->refresh(), 'Surplus'));
    expect($e->codes)->toBe(['name' => 'grid_full']);
});

test('retirer un lot : filled → incomplete → new, statut mis à jour', function () {
    $grid = fillGrid($this->workflow, $this->orga, rewardsGrid($this));
    expect($grid->status)->toBe('filled');

    $lot = $grid->lots()->first();
    $this->workflow->removeLot($this->orga, $grid, $lot);
    expect($grid->refresh()->status)->toBe('incomplete');

    foreach ($grid->lots()->get() as $l) {
        $this->workflow->removeLot($this->orga, $grid->refresh(), $l);
    }
    expect($grid->refresh()->status)->toBe('new');
});

test('ajouter / retirer un lot sur une grille tirée (drawed) : refusé', function () {
    $grid = fillGrid($this->workflow, $this->orga, rewardsGrid($this));
    $this->workflow->draw($this->orga, $grid->refresh());
    $grid->refresh();

    $e1 = wfRefusal(fn () => $this->workflow->addLot($this->orga, $grid, 'Extra'));
    expect($e1->codes)->toBe(['activity' => 'grid_not_editable']);

    $lot = $grid->lots()->first();
    $e2 = wfRefusal(fn () => $this->workflow->removeLot($this->orga, $grid, $lot));
    expect($e2->codes)->toBe(['activity' => 'grid_not_editable']);
});

// ─── Tirage ──────────────────────────────────────────────────────────────────

test('draw : chaque lot reçoit une place unique 1..capacity, statut confirmed', function () {
    $grid = fillGrid($this->workflow, $this->orga, rewardsGrid($this)); // 2×2 = 4
    $drawn = $this->workflow->draw($this->orga, $grid->refresh());

    expect($drawn->status)->toBe('confirmed')
        ->and($drawn->drawn_at)->not->toBeNull()
        ->and($drawn->confirmed_at)->not->toBeNull();

    $places = $drawn->lots()->pluck('place')->sort()->values()->all();
    expect($places)->toBe([1, 2, 3, 4]);
});

test('draw refusé si la grille n\'est pas remplie', function () {
    $grid = rewardsGrid($this);
    $this->workflow->addLot($this->orga, $grid, 'Un seul');
    $e = wfRefusal(fn () => $this->workflow->draw($this->orga, $grid->refresh()));
    expect($e->codes)->toBe(['activity' => 'grid_not_filled']);
});


// ─── Assignation ─────────────────────────────────────────────────────────────

test('assign : set et unset du joueur sur un lot, seulement en confirmed', function () {
    $grid = fillGrid($this->workflow, $this->orga, rewardsGrid($this));
    $this->workflow->draw($this->orga, $grid->refresh()); // draw → confirmed directement
    $grid->refresh();

    $lot = $grid->lots()->first();
    $updated = $this->workflow->assign($this->orga, $grid, $lot, 'Brunehaut');
    expect($updated->player)->toBe('Brunehaut');

    $unset = $this->workflow->assign($this->orga, $grid, $lot->refresh(), null);
    expect($unset->player)->toBeNull();
});

test('assign refusé si pas en confirmed', function () {
    // La grille est en filled (tirage pas encore effectué) : assign doit être refusé.
    $grid = fillGrid($this->workflow, $this->orga, rewardsGrid($this));
    $lot = $grid->lots()->first();
    $e = wfRefusal(fn () => $this->workflow->assign($this->orga, $grid->refresh(), $lot, 'X'));
    expect($e->codes)->toBe(['activity' => 'grid_not_confirmed']);
});

// ─── markTaken ───────────────────────────────────────────────────────────────

test('markTaken : fonctionne en confirmed ET en closed', function () {
    $grid = fillGrid($this->workflow, $this->orga, rewardsGrid($this));
    $this->workflow->draw($this->orga, $grid->refresh()); // draw → confirmed directement
    $grid->refresh();

    $lot = $grid->lots()->first();
    $taken = $this->workflow->markTaken($this->orga, $grid, $lot, true);
    expect($taken->is_taken)->toBeTrue();

    $this->workflow->close($this->orga, $grid->refresh());
    $lot2 = $grid->lots()->skip(1)->first();
    $taken2 = $this->workflow->markTaken($this->orga, $grid->refresh(), $lot2, true);
    expect($taken2->is_taken)->toBeTrue();
});

// ─── Clôture ─────────────────────────────────────────────────────────────────

test('clôture PRIVÉE : statut closed, lots et données conservées, pas de snapshot', function () {
    $grid = rewardsGrid($this);
    $this->workflow->addLot($this->orga, $grid, 'Lot A');
    $closed = $this->workflow->close($this->orga, $grid->refresh());

    expect($closed->status)->toBe('closed')
        ->and($closed->closing_snapshot)->toBeNull()
        ->and($grid->lots()->count())->toBe(1); // lots conservés
});

test('clôture SANS COMPTE : données personnelles effacées, lots CONSERVÉS, snapshot non nul', function () {
    $grid = $this->workflow->createAnonymous($this->type, [
        'slug' => 'grille-anon', 'name' => 'Grille anonyme', 'width' => 1, 'height' => 1,
        'organizer_name' => 'Artifice', 'password' => 'un-bon-mot-de-passe',
        'province_id' => $this->map['provinceA']->id,
    ], null);
    $this->workflow->addLot(AnimActor::forAnonymous($grid), $grid, 'Lot A');

    $actor = AnimActor::forAnonymous($grid);
    $closed = $this->workflow->close($actor, $grid->refresh());

    expect($closed->status)->toBe('closed')
        ->and($closed->closing_snapshot)->not->toBeNull()
        ->and($closed->lots()->count())->toBe(1); // lots communautaires conservés

    // Données personnelles effacées.
    $raw = DB::table('anim_rewards_grids')->where('id', $grid->id)->first();
    expect($raw->password_hash)->toBeNull()
        ->and($raw->creator_ip_hash)->toBeNull();
});

// ─── Réouverture ─────────────────────────────────────────────────────────────

test('réouverture PRIVÉE : revient en confirmed (lots et places conservés)', function () {
    $grid = fillGrid($this->workflow, $this->orga, rewardsGrid($this));
    $this->workflow->draw($this->orga, $grid->refresh());
    $this->workflow->close($this->orga, $grid->refresh());

    $reopened = $this->workflow->reopen($this->orga, $grid->refresh());
    expect($reopened->status)->toBe('confirmed');

    // Les lots gardent leurs places.
    expect($reopened->lots()->whereNull('place')->count())->toBe(0);
});

test('réouverture refusée : activité non close, sans compte, autre active', function () {
    $grid = fillGrid($this->workflow, $this->orga, rewardsGrid($this));
    $this->workflow->draw($this->orga, $grid->refresh());

    // Pas encore close.
    $e1 = wfRefusal(fn () => $this->workflow->reopen($this->orga, $grid->refresh()));
    expect($e1->codes)->toBe(['activity' => 'activity_not_closed']);

    // Close puis une seconde grille active → réouverture bloquée.
    $this->workflow->close($this->orga, $grid->refresh());
    $second = $this->workflow->create($this->orga, $this->type, ['slug' => 'grille-deux', 'name' => 'Deux', 'width' => 1, 'height' => 1, 'province_id' => $this->map['provinceA']->id]);
    $e2 = wfRefusal(fn () => $this->workflow->reopen($this->orga, $grid->refresh()));
    expect($e2->codes)->toBe(['activity' => 'reopen_blocked']);

    // Grille SANS COMPTE close : jamais rouvrable.
    $anon = $this->workflow->createAnonymous($this->type, [
        'slug' => 'grille-anon', 'name' => 'Anon', 'width' => 1, 'height' => 1,
        'organizer_name' => 'Artifice', 'password' => 'un-bon-mot-de-passe',
        'province_id' => $this->map['provinceA']->id,
    ], null);
    $this->workflow->close(AnimActor::forAnonymous($anon), $anon->refresh());
    $e3 = wfRefusal(fn () => $this->workflow->reopen(AnimActor::forAnonymous($anon), $anon->refresh()));
    expect($e3->codes)->toBe(['activity' => 'reopen_anonymous']);
});

// ─── Suppression ─────────────────────────────────────────────────────────────

test('suppression PRIVÉE close : grille et lots effacés', function () {
    $grid = rewardsGrid($this);
    $this->workflow->addLot($this->orga, $grid, 'X');
    $e = wfRefusal(fn () => $this->workflow->delete($this->orga, $grid->refresh()));
    expect($e->codes)->toBe(['activity' => 'delete_requires_closed']);

    $this->workflow->close($this->orga, $grid->refresh());
    $this->workflow->delete($this->orga, $grid->refresh());

    expect(AnimRewardsGrid::count())->toBe(0)
        ->and(AnimRewardsList::count())->toBe(0);
});

test('suppression SANS COMPTE : refusée pour l\'auteur', function () {
    $anon = $this->workflow->createAnonymous($this->type, [
        'slug' => 'grille-anon', 'name' => 'Anon', 'width' => 1, 'height' => 1,
        'organizer_name' => 'Artifice', 'password' => 'un-bon-mot-de-passe',
        'province_id' => $this->map['provinceA']->id,
    ], null);
    $this->workflow->close(AnimActor::forAnonymous($anon), $anon->refresh());
    $e = wfRefusal(fn () => $this->workflow->delete(AnimActor::forAnonymous($anon), $anon->refresh()));
    expect($e->codes)->toBe(['activity' => 'delete_anonymous']);

    // L'administrateur peut supprimer.
    $this->workflow->deleteByAdmin($anon);
    expect(AnimRewardsGrid::count())->toBe(0);
});

// ─── Autorisation ────────────────────────────────────────────────────────────

test('403 not_owner : un autre personnage ne peut rien faire', function () {
    $grid = fillGrid($this->workflow, $this->orga, rewardsGrid($this));

    foreach ([
        fn () => $this->workflow->addLot($this->other, $grid, 'Extra'),
        fn () => $this->workflow->removeLot($this->other, $grid, $grid->lots()->first()),
        fn () => $this->workflow->draw($this->other, $grid),
        fn () => $this->workflow->close($this->other, $grid),
    ] as $gesture) {
        $e = wfRefusal($gesture);
        expect($e->status)->toBe(403)->and($e->codes)->toBe(['activity' => 'not_owner']);
    }
});

// ─── Catégorie C ─────────────────────────────────────────────────────────────

test('catégorie C : la suppression du personnage passe character_id à NULL, la grille reste', function () {
    $grid = rewardsGrid($this);
    expect($grid->character_id)->not->toBeNull();

    // Simuler la cascade nullOnDelete en supprimant le personnage.
    $this->orga->delete();

    $grid->refresh();
    expect($grid->character_id)->toBeNull()
        ->and(AnimRewardsGrid::count())->toBe(1);
});
