<?php

use App\Exceptions\MandateRefusal;
use App\Models\AnimRewardsGrid;
use App\Models\AnimRewardsList;
use App\Services\AnimActor;
use App\Services\RewardsGridPasswordReset;
use App\Services\RewardsGridWorkflow;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/*
| Grille des lots — mode SANS COMPTE (RewardsAnonymousController).
| Slug + mot de passe dans le CORPS de chaque geste. Aucun jeton.
| Clôture définitive : données personnelles effacées, lots CONSERVÉS.
| Aucun email dans ce module.
*/

const REWARDS_PASSWORD = 'un-bon-mot-de-passe';
const REWARDS_PUBLIC_IP = '93.184.216.34';

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->map = mandateMap();
});

function rewardsAnonPayload(array $over = []): array
{
    return $over + [
        'slug' => 'grille-des-fees',
        'name' => 'Grille des Fées',
        'width' => 2,
        'height' => 2,
        'organizer_name' => 'Elfie',
        'password' => REWARDS_PASSWORD,
        'password_confirmation' => REWARDS_PASSWORD,
    ];
}

function createAnonGrid($test, array $over = [], string $ip = REWARDS_PUBLIC_IP): TestResponse
{
    $place = array_intersect_key($over, array_flip(['kingdom_id', 'province_id', 'city_id'])) ? [] : ['province_id' => $test->map['provinceA']->id];

    return $test->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/anim/rewards-manage', rewardsAnonPayload($over + $place));
}

function rewardsAnonCall($test, string $slug, string $path, array $body = [], string $password = REWARDS_PASSWORD, string $method = 'postJson'): TestResponse
{
    return $test->{$method}("/api/v1/anim/rewards-manage/{$slug}/{$path}", ['password' => $password] + $body);
}

// ─── Création ────────────────────────────────────────────────────────────────

test('créer une grille sans compte : 201, mode anonymous, statut new', function () {
    createAnonGrid($this)
        ->assertCreated()
        ->assertJsonPath('grid.name', 'Grille des Fées')
        ->assertJsonPath('grid.slug', 'grille-des-fees')
        ->assertJsonPath('grid.mode', 'anonymous')
        ->assertJsonPath('grid.status', 'new')
        ->assertJsonPath('grid.organizer', 'Elfie');
});

test('pseudo obligatoire : 422 codes.organizer_name', function () {
    $this->postJson('/api/v1/anim/rewards-manage', rewardsAnonPayload(['organizer_name' => '', 'province_id' => $this->map['provinceA']->id]))
        ->assertStatus(422)
        ->assertJsonPath('codes.organizer_name', 'organizer_required');
});

test('mot de passe identique au slug : 422', function () {
    createAnonGrid($this, ['password' => 'grille-des-fees', 'password_confirmation' => 'grille-des-fees'])
        ->assertStatus(422)
        ->assertJsonPath('codes.password', 'password_too_guessable');
});

test('une seule grille sans compte ouverte par connexion (IP)', function () {
    createAnonGrid($this)->assertCreated();
    // Slug différent mais même IP → ip_limit (le slug n'est pas pris, c'est l'IP qui bloque).
    createAnonGrid($this, ['slug' => 'grille-bis', 'name' => 'Grille Bis'], REWARDS_PUBLIC_IP)->assertStatus(422)
        ->assertJsonPath('codes.activity', 'ip_limit');

    // Une IP différente peut créer.
    createAnonGrid($this, ['slug' => 'grille-deux', 'name' => 'Grille 2'], '8.8.8.8')->assertCreated();
});

test('slug invalide : 422', function () {
    createAnonGrid($this, ['slug' => 'grille-123'])->assertStatus(422)
        ->assertJsonPath('codes.slug', 'slug_invalid');
});

// ─── Aperçu slug ─────────────────────────────────────────────────────────────

test('GET rewards-slug : available + taken', function () {
    $this->getJson('/api/v1/anim/rewards-slug?name=Ma+Grille&slug=ma-grille')
        ->assertOk()
        ->assertJsonPath('taken', false)
        ->assertJsonPath('valid', true);

    createAnonGrid($this, ['slug' => 'ma-grille', 'name' => 'Ma Grille'])->assertCreated();

    $this->getJson('/api/v1/anim/rewards-slug?slug=ma-grille')
        ->assertOk()
        ->assertJsonPath('taken', true);
});

// ─── Workflow lots + tirage ───────────────────────────────────────────────────

test('ouvrir la salle : mauvais mot de passe = 403 access_denied', function () {
    createAnonGrid($this)->assertCreated();
    rewardsAnonCall($this, 'grille-des-fees', 'open', [], 'mauvais-mdp')
        ->assertStatus(403)
        ->assertJsonPath('codes.password', 'access_denied');
});

test('workflow complet sans compte : lots → draw → assign → close', function () {
    createAnonGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();

    // Lots.
    for ($i = 1; $i <= 4; $i++) {
        rewardsAnonCall($this, $grid->slug, 'lots', ['name' => "Lot $i"])
            ->assertCreated()
            ->assertJsonPath('grid.status', $i < 4 ? 'incomplete' : 'filled');
    }

    // Draw → confirmed directement (tirage + confirmation en un geste).
    rewardsAnonCall($this, $grid->slug, 'draw')->assertOk()
        ->assertJsonPath('grid.status', 'confirmed');

    // Assign.
    $lotId = AnimRewardsList::first()->id;
    rewardsAnonCall($this, $grid->slug, "lots/{$lotId}/assign", ['player' => 'Brunehaut'], REWARDS_PASSWORD, 'patchJson')->assertOk();

    // markTaken.
    rewardsAnonCall($this, $grid->slug, "lots/{$lotId}/taken", ['is_taken' => true], REWARDS_PASSWORD, 'patchJson')->assertOk();

    // Clôture DÉFINITIVE.
    rewardsAnonCall($this, $grid->slug, 'close')->assertOk()
        ->assertJsonPath('grid.status', 'closed');

    // Données personnelles effacées, lots conservés.
    $raw = DB::table('anim_rewards_grids')->where('id', $grid->id)->first();
    expect($raw->password_hash)->toBeNull()
        ->and($raw->creator_ip_hash)->toBeNull()
        ->and(AnimRewardsList::count())->toBe(4);
});

test('plusieurs lots sans compte en UNE requête : le mot de passe dans le corps, tous créés', function () {
    createAnonGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();

    rewardsAnonCall($this, $grid->slug, 'lots', ['names' => ['Pain', 'Pain', 'Épée']])
        ->assertCreated()
        ->assertJsonPath('grid.status', 'incomplete')
        ->assertJsonCount(3, 'grid.lots');
    expect(AnimRewardsList::where('name', 'Pain')->count())->toBe(2);

    rewardsAnonCall($this, $grid->slug, 'lots', ['names' => ['Casque']])->assertCreated()->assertJsonPath('grid.status', 'filled');
});

test('plusieurs lots sans compte : liste trop longue refusée en entier, mauvais mot de passe refusé', function () {
    createAnonGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();

    rewardsAnonCall($this, $grid->slug, 'lots', ['names' => ['A', 'B', 'C', 'D', 'E']])
        ->assertStatus(422)
        ->assertJsonPath('codes.name', 'grid_full');
    expect(AnimRewardsList::count())->toBe(0);

    rewardsAnonCall($this, $grid->slug, 'lots', ['names' => ['A']], 'un-mauvais-mot-de-passe')->assertForbidden();
    expect(AnimRewardsList::count())->toBe(0);

    rewardsAnonCall($this, $grid->slug, 'lots', ['names' => []])->assertStatus(422);
});

test('retirer un lot sans compte', function () {
    createAnonGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();

    rewardsAnonCall($this, $grid->slug, 'lots', ['name' => 'A'])->assertCreated();
    $lotId = AnimRewardsList::first()->id;
    rewardsAnonCall($this, $grid->slug, "lots/{$lotId}", [], REWARDS_PASSWORD, 'deleteJson')->assertOk();
    expect(AnimRewardsList::count())->toBe(0);
});

// ─── Clôture ─────────────────────────────────────────────────────────────────

test('clôture : snapshot non nul, données personnelles effacées', function () {
    createAnonGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();
    rewardsAnonCall($this, $grid->slug, 'close')->assertOk();

    $grid->refresh();
    // Snapshot figé, données personnelles effacées.
    expect($grid->closing_snapshot)->not->toBeNull()
        ->and($grid->password_hash)->toBeNull()
        ->and($grid->creator_ip_hash)->toBeNull();
    // Note : impossible de tester activity_closed via l'API car le mot de passe est effacé à la
    // clôture : toute tentative reçoit access_denied (403) avant d'atteindre la garde activity_closed.
    // Ce comportement est couvert dans RewardsGridWorkflowTest.
});

// ─── Réouverture impossible ───────────────────────────────────────────────────

test('une grille sans compte ne peut pas être rouverte', function () {
    createAnonGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();
    rewardsAnonCall($this, $grid->slug, 'close');

    // La route de réouverture n'existe pas pour le mode sans compte → le workflow lève reopen_anonymous.
    // La meilleure façon de tester est de passer par l'API privée (aucun jeton disponible côté sans-compte).
    // On vérifie directement le workflow.
    $wf = app(RewardsGridWorkflow::class);
    try {
        $wf->reopen(AnimActor::forAnonymous($grid->refresh()), $grid->refresh());
        expect(true)->toBeFalse('Un refus était attendu.');
    } catch (MandateRefusal $e) {
        expect($e->codes)->toBe(['activity' => 'reopen_anonymous']);
    }
});

// ─── Réinitialisation du mot de passe ────────────────────────────────────────

test('reset-request : toujours 202 (même si slug inconnu)', function () {
    // Slug inexistant.
    $this->postJson('/api/v1/anim/rewards-manage/introuvable/reset-request', [])
        ->assertStatus(202);

    // Slug existant.
    createAnonGrid($this)->assertCreated();
    $this->postJson('/api/v1/anim/rewards-manage/grille-des-fees/reset-request', [])
        ->assertStatus(202);
});

test('reset-complete : token valide permet de changer le mot de passe', function () {
    createAnonGrid($this)->assertCreated();
    $grid = AnimRewardsGrid::first();

    // L'admin émet un lien : il reçoit l'URL contenant le token.
    $reset = app(RewardsGridPasswordReset::class);
    $result = $reset->issueForAdmin($grid);
    parse_str((string) parse_url($result['url'], PHP_URL_QUERY), $query);
    $token = $query['token'];

    // Changer le mot de passe.
    $newPwd = 'un-nouveau-mot-de-passe';
    $this->postJson('/api/v1/anim/rewards-manage/grille-des-fees/reset-complete', [
        'token' => $token,
        'password' => $newPwd,
        'password_confirmation' => $newPwd,
    ])->assertOk();

    // L'ancien mot de passe ne fonctionne plus.
    rewardsAnonCall($this, 'grille-des-fees', 'open', [], REWARDS_PASSWORD)
        ->assertStatus(403);

    // Le nouveau mot de passe fonctionne.
    rewardsAnonCall($this, 'grille-des-fees', 'open', [], $newPwd)
        ->assertOk();
});

test('reset-complete : token invalide → 422', function () {
    createAnonGrid($this)->assertCreated();
    $this->postJson('/api/v1/anim/rewards-manage/grille-des-fees/reset-complete', [
        'token' => 'token-bidon',
        'password' => 'un-nouveau-mot-de-passe',
        'password_confirmation' => 'un-nouveau-mot-de-passe',
    ])->assertStatus(422)->assertJsonPath('codes.token', 'reset_invalid');
});

// ─── Confirmation d'adresse email ────────────────────────────────────────────

test('confirmation d\'adresse par lien signé', function () {
    createAnonGrid($this, ['email' => 'elfie@example.com'])->assertCreated();
    $grid = AnimRewardsGrid::first();

    expect($grid->organizer_email_verified_at)->toBeNull();

    $url = URL::signedRoute('anim.rewards.email-confirm', [
        'slug' => $grid->slug,
        'hash' => sha1($grid->organizer_email),
    ]);
    $this->getJson($url)->assertOk();

    expect($grid->refresh()->organizer_email_verified_at)->not->toBeNull();
});
