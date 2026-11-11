<?php

use App\Models\AnimActivityType;
use App\Models\AnimDecodeActivity;
use App\Models\AnimDecodeSecret;
use App\Models\AnimRewardsGrid;
use App\Models\AnimRewardsList;
use App\Services\AnimActor;
use App\Services\DecodeProposals;
use App\Services\DecodeWorkflow;
use App\Services\RewardsGridWorkflow;
use App\Support\AnimSlug;
use App\Support\MandateHeartbeat;
use Illuminate\Support\Carbon;

/*
| anim:close-inactive — 30 jours sans action (brief). Dernière action = dernière proposition, lancement,
| création ou réouverture.
*/

beforeEach(function () {
    $this->map = mandateMap();
    activateRewardsType();
    $this->type = AnimActivityType::where('key', 'decode')->firstOrFail();
    $this->workflow = app(DecodeWorkflow::class);
    $this->proposals = app(DecodeProposals::class);
});

afterEach(fn () => Carbon::setTestNow());

/** Une activité lancée il y a $days jours, avec une proposition du même jour. */
function launchedDaysAgo($test, int $days, string $name): AnimDecodeActivity
{
    atGameTime(Carbon::now()->subDays($days)->toDateTimeString());
    $orga = mandatePlayer($test->map['cityA']);
    $activity = $test->workflow->create($orga, $test->type, ['name' => $name, 'slug' => AnimSlug::propose($name)]);
    $secret = $test->workflow->generateSecret($orga, $activity);
    $test->proposals->submit($orga, $activity, $secret, '00000', 'Aldric');
    Carbon::setTestNow();

    return $activity;
}

test('inactive depuis 31 jours : fermée ; depuis 29 jours : intacte', function () {
    atGameTime('2026-10-27 09:00');
    $old = launchedDaysAgo($this, 31, 'Vieille');
    atGameTime('2026-10-27 09:00');
    $recent = launchedDaysAgo($this, 29, 'Récente');
    atGameTime('2026-10-27 09:00');

    $this->artisan('anim:close-inactive')->assertSuccessful();

    expect($old->refresh()->status)->toBe('closed')->and($old->closed_at)->not->toBeNull()
        ->and($recent->refresh()->status)->not->toBe('closed');
});

test('une activité jamais lancée se mesure depuis sa création', function () {
    atGameTime('2026-09-20 09:00');
    $orga = mandatePlayer($this->map['cityA']);
    $never = $this->workflow->create($orga, $this->type, ['name' => 'Jamais lancée', 'slug' => 'jamais-lancee']);
    atGameTime('2026-10-27 09:00');

    $this->artisan('anim:close-inactive')->assertSuccessful();

    expect($never->refresh()->status)->toBe('closed');
});

test('une réouverture relance l\'horloge des 30 jours', function () {
    atGameTime('2026-08-01 09:00');
    $orga = mandatePlayer($this->map['cityA']);
    $activity = $this->workflow->create($orga, $this->type, ['name' => 'Rouverte', 'slug' => 'rouverte']);
    $this->workflow->generateSecret($orga, $activity);
    $this->workflow->close($orga, $activity);
    atGameTime('2026-10-20 09:00');
    $this->workflow->reopen($orga, $activity);
    atGameTime('2026-10-27 09:00');

    $this->artisan('anim:close-inactive')->assertSuccessful();

    expect($activity->refresh()->status)->toBe('launched');
});

test('ne touche pas une activité déjà close, et note son passage', function () {
    atGameTime('2026-10-27 09:00');
    expect(MandateHeartbeat::lastSuccess('decode'))->toBeNull();

    $this->artisan('anim:close-inactive')->assertSuccessful();

    expect(MandateHeartbeat::lastSuccess('decode'))->not->toBeNull();
});

test('une activité SANS COMPTE inactive depuis 31 jours se clôt définitivement : photo figée, données personnelles effacées', function () {
    atGameTime('2026-09-20 09:00');
    $anon = $this->workflow->createAnonymous($this->type, [
        'slug' => 'sans-compte', 'name' => 'Sans compte', 'organizer_name' => 'Artifice', 'password' => 'un-bon-mot-de-passe',
    ], hash('sha256', 'une-connexion'));
    $this->workflow->generateSecret(AnimActor::forAnonymous($anon), $anon);
    atGameTime('2026-10-27 09:00');

    $this->artisan('anim:close-inactive')->assertSuccessful();

    $anon->refresh();
    expect($anon->status)->toBe('closed')
        ->and($anon->closing_snapshot)->not->toBeNull()
        ->and($anon->password_hash)->toBeNull()
        ->and($anon->creator_ip_hash)->toBeNull()
        ->and(AnimDecodeSecret::count())->toBe(0);
});

// ─── Grille des lots : le même cycle de vie, dans la même commande ─────────────────────────────────

/** Une grille créée il y a $days jours (au temps simulé courant), privée ou sans compte, avec un lot. */
function rewardsGridDaysAgo($test, int $days, string $slug, bool $anonymous = false): AnimRewardsGrid
{
    $workflow = app(RewardsGridWorkflow::class);
    $type = AnimActivityType::where('key', 'rewards')->firstOrFail();
    $now = Carbon::now();
    Carbon::setTestNow($now->copy()->subDays($days));
    $data = ['slug' => $slug, 'name' => ucfirst($slug), 'width' => 3, 'height' => 3, 'province_id' => $test->map['provinceA']->id];
    if ($anonymous) {
        $grid = $workflow->createAnonymous($type, $data + ['organizer_name' => 'Artifice', 'password' => 'un-bon-mot-de-passe'], hash('sha256', $slug));
    } else {
        $grid = $workflow->create(mandatePlayer($test->map['cityA']), $type, $data);
    }
    $grid->lots()->create(['name' => 'Pain']);
    Carbon::setTestNow($now);

    return $grid->refresh();
}

test('grille privée inactive depuis 31 jours : close, TOUT est gardé ; depuis 29 jours : intacte', function () {
    atGameTime('2026-11-11 09:00');
    $old = rewardsGridDaysAgo($this, 31, 'vieille-grille');
    $recent = rewardsGridDaysAgo($this, 29, 'recente-grille');

    $this->artisan('anim:close-inactive')->assertSuccessful();

    expect($old->refresh()->status)->toBe(AnimRewardsGrid::CLOSED)
        ->and($old->closed_at)->not->toBeNull()
        ->and($old->lots()->count())->toBe(1) // une grille privée garde tout : elle se rouvre
        ->and($recent->refresh()->status)->not->toBe(AnimRewardsGrid::CLOSED);
});

test('grille SANS COMPTE inactive depuis 31 jours : clôture définitive, données personnelles effacées, lots conservés', function () {
    atGameTime('2026-11-11 09:00');
    $anon = rewardsGridDaysAgo($this, 31, 'sans-compte-grille', true);

    $this->artisan('anim:close-inactive')->assertSuccessful();

    $anon->refresh();
    expect($anon->status)->toBe(AnimRewardsGrid::CLOSED)
        ->and($anon->closing_snapshot)->not->toBeNull()
        ->and($anon->password_hash)->toBeNull()
        ->and($anon->creator_ip_hash)->toBeNull()
        ->and($anon->organizer_email)->toBeNull()
        ->and(AnimRewardsList::where('grid_id', $anon->id)->count())->toBe(1);
});

test('un lot ajouté plus tard relance l\'horloge des 30 jours d\'une grille', function () {
    atGameTime('2026-11-11 09:00');
    $grid = rewardsGridDaysAgo($this, 40, 'relancee-grille');
    atGameTime('2026-11-06 09:00'); // il y a 5 jours
    app(RewardsGridWorkflow::class)->addLot(AnimActor::of($grid->refresh()->character), $grid, 'Épée');
    atGameTime('2026-11-11 09:00');

    $this->artisan('anim:close-inactive')->assertSuccessful();

    expect($grid->refresh()->status)->not->toBe(AnimRewardsGrid::CLOSED);
});

test('la même passe traite Décode le code ET la Grille des lots, et le dit', function () {
    atGameTime('2026-11-11 09:00');
    $decode = launchedDaysAgo($this, 31, 'Vieille décode');
    atGameTime('2026-11-11 09:00');
    $grid = rewardsGridDaysAgo($this, 31, 'vieille-grille');

    $this->artisan('anim:close-inactive')
        ->expectsOutputToContain('Décode le code : 1 fermée(s)')
        ->expectsOutputToContain('Grille des lots : 1 fermée(s)')
        ->expectsOutputToContain('2 activité(s) fermée(s)')
        ->assertSuccessful();

    expect($decode->refresh()->status)->toBe('closed')->and($grid->refresh()->status)->toBe(AnimRewardsGrid::CLOSED);
});

test('lastActionAt d\'une grille rend toujours un Carbon (le dernier lot sort de la base en texte)', function () {
    atGameTime('2026-11-11 09:00');
    $grid = rewardsGridDaysAgo($this, 3, 'carbon-grille');

    expect(app(RewardsGridWorkflow::class)->lastActionAt($grid))->toBeInstanceOf(Carbon::class);
});
