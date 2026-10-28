<?php

use App\Models\AnimActivityType;
use App\Models\AnimDecodeActivity;
use App\Services\DecodeWorkflow;
use App\Support\DecodeSlug;
use App\Support\MandateHeartbeat;
use Illuminate\Support\Carbon;

/*
| anim:purge-closed — la page de résumé d'une activité SANS COMPTE disparaît 5 ans après sa clôture (D7) et son slug est libéré
| (D9). Une activité PRIVÉE n'est JAMAIS supprimée automatiquement (D20) : seulement par son organisateur ou avec son personnage.
*/

beforeEach(function () {
    $this->map = mandateMap();
    $this->type = AnimActivityType::where('key', 'decode')->firstOrFail();
    $this->workflow = app(DecodeWorkflow::class);
});

afterEach(fn () => Carbon::setTestNow());

/** Une activité sans compte close il y a $closedAgo (ex. « 5 years + 1 day »). */
function closedAnonymous($test, string $slug, string $closedAgo): AnimDecodeActivity
{
    $activity = $test->workflow->createAnonymous($test->type, [
        'slug' => $slug, 'name' => ucfirst($slug), 'organizer_name' => 'Artifice', 'password' => 'un-bon-mot-de-passe',
    ], null);
    $test->workflow->closeNow($activity);
    $activity->forceFill(['closed_at' => Carbon::now()->sub($closedAgo)])->save();

    return $activity->refresh();
}

test('supprime le résumé d\'une activité sans compte close depuis plus de 5 ans, et libère son slug', function () {
    closedAnonymous($this, 'vieille', '5 years 1 day');

    $this->artisan('anim:purge-closed')->assertSuccessful();

    expect(AnimDecodeActivity::count())->toBe(0)->and(DecodeSlug::taken('vieille'))->toBeFalse();
    // Le slug est réutilisable (D9).
    $reborn = $this->workflow->createAnonymous($this->type, ['slug' => 'vieille', 'name' => 'Nouvelle vie', 'organizer_name' => 'Autre', 'password' => 'un-autre-mot-de-passe'], null);
    expect($reborn->slug)->toBe('vieille');
});

test('garde un résumé de moins de 5 ans', function () {
    closedAnonymous($this, 'recente', '4 years 11 months');

    $this->artisan('anim:purge-closed')->assertSuccessful();

    expect(AnimDecodeActivity::where('slug', 'recente')->exists())->toBeTrue();
});

test('ne touche JAMAIS une activité privée, même close depuis très longtemps', function () {
    $orga = mandatePlayer($this->map['cityA']);
    $private = $this->workflow->create($orga, $this->type, ['name' => 'Privée', 'slug' => 'privee']);
    $this->workflow->close($orga, $private);
    $private->forceFill(['closed_at' => Carbon::now()->subYears(20)])->save();

    $this->artisan('anim:purge-closed')->assertSuccessful();

    expect(AnimDecodeActivity::where('slug', 'privee')->exists())->toBeTrue();
});

test('ne touche pas une activité sans compte encore ouverte', function () {
    $this->workflow->createAnonymous($this->type, ['slug' => 'ouverte', 'name' => 'Ouverte', 'organizer_name' => 'Artifice', 'password' => 'un-bon-mot-de-passe'], null);

    $this->artisan('anim:purge-closed')->assertSuccessful();

    expect(AnimDecodeActivity::count())->toBe(1);
});

test('--dry-run liste sans supprimer', function () {
    closedAnonymous($this, 'vieille', '6 years');

    $this->artisan('anim:purge-closed --dry-run')->expectsOutputToContain('1 page(s)')->assertSuccessful();

    expect(AnimDecodeActivity::count())->toBe(1);
});

test('chaque passage réussi est enregistré (heartbeat) et la tâche a son libellé', function () {
    expect(MandateHeartbeat::lastSuccess('decode_purge'))->toBeNull();

    $this->artisan('anim:purge-closed')->assertSuccessful();

    expect(MandateHeartbeat::lastSuccess('decode_purge'))->not->toBeNull()
        ->and(MandateHeartbeat::TASKS)->toHaveKey('decode_purge')
        ->and(__('mandates.scheduler.tasks.decode_purge'))->not->toBe('mandates.scheduler.tasks.decode_purge');
});
