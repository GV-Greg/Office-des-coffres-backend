<?php

use App\Models\AnimActivityType;
use App\Models\AnimDecodeActivity;
use App\Models\AnimDecodeSecret;
use App\Services\DecodeActor;
use App\Services\DecodeProposals;
use App\Services\DecodeWorkflow;
use App\Support\DecodeSlug;
use App\Support\MandateHeartbeat;
use Illuminate\Support\Carbon;

/*
| anim:close-inactive — 30 jours sans action (brief). Dernière action = dernière proposition, lancement,
| création ou réouverture.
*/

beforeEach(function () {
    $this->map = mandateMap();
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
    $activity = $test->workflow->create($orga, $test->type, ['name' => $name, 'slug' => DecodeSlug::propose($name)]);
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
    $this->workflow->generateSecret(DecodeActor::forAnonymous($anon), $anon);
    atGameTime('2026-10-27 09:00');

    $this->artisan('anim:close-inactive')->assertSuccessful();

    $anon->refresh();
    expect($anon->status)->toBe('closed')
        ->and($anon->closing_snapshot)->not->toBeNull()
        ->and($anon->password_hash)->toBeNull()
        ->and($anon->creator_ip_hash)->toBeNull()
        ->and(AnimDecodeSecret::count())->toBe(0);
});
