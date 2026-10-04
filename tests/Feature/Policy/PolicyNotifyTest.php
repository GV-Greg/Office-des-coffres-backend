<?php

use App\Models\User;
use App\Notifications\PolicyUpdated;
use App\Support\PolicyChangelog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

// Notification des modifications substantielles de la politique (/legal/privacy §10 ; brief
// admin/content/brief-politique-promesses.md §4 ; fil admin/echanges/politique-promesses, Q5-Q6).

function policyEntry(array $overrides = []): array
{
    return array_replace([
        'date' => '2026-10-04',
        'summary' => ['fr' => 'Le délai passe à 1 an.', 'en' => 'The delay becomes 1 year.'],
        'substantial' => true,
        'decided_by' => 'Greg',
    ], $overrides);
}

function withChangelog(array $entries): void
{
    app()->instance(PolicyChangelog::class, new PolicyChangelog($entries));
}

// --- Le journal du dépôt ---

test('chaque entrée du journal du dépôt est bien formée', function () {
    $entries = (new PolicyChangelog)->all();
    expect($entries)->toBeArray();

    foreach ($entries as $id => $entry) {
        expect(PolicyChangelog::problems($entry))->toBe([], "entrée « {$id} »");
    }
});

test('la commande n\'est jamais planifiée : Greg la lance', function () {
    $commands = collect(app(Schedule::class)->events())->pluck('command')->implode("\n");

    expect($commands)->toContain('mandates:purge-rejected') // contrôle positif : le planificateur est bien lu
        ->not->toContain('policy:notify');
});

// --- Refus ---

test('une entrée inconnue est refusée, rien ne part', function () {
    Notification::fake();
    withChangelog([]);
    User::factory()->create();

    $this->artisan('policy:notify', ['entry' => 'absente', '--force' => true])->assertFailed();

    Notification::assertNothingSent();
    expect(DB::table('policy_notifications')->count())->toBe(0);
});

test('une entrée mal formée est refusée', function (array $overrides) {
    Notification::fake();
    withChangelog(['e' => policyEntry($overrides)]);
    User::factory()->create();

    $this->artisan('policy:notify', ['entry' => 'e', '--force' => true])->assertFailed();

    Notification::assertNothingSent();
})->with([
    'date illisible' => [['date' => '2026-02-30']],
    'résumé anglais vide' => [['summary' => ['fr' => 'x', 'en' => ' ']]],
    'substantielle non booléenne' => [['substantial' => 'oui']],
    'décideur absent' => [['decided_by' => '']],
]);

test('une entrée non substantielle est refusée', function () {
    Notification::fake();
    withChangelog(['e' => policyEntry(['substantial' => false])]);
    User::factory()->create();

    $this->artisan('policy:notify', ['entry' => 'e', '--force' => true])->assertFailed();

    Notification::assertNothingSent();
});

test('sans aucun compte vérifié, rien ne part et rien n\'est noté', function () {
    Notification::fake();
    withChangelog(['e' => policyEntry()]);
    User::factory()->unverified()->create();

    $this->artisan('policy:notify', ['entry' => 'e', '--force' => true])->assertFailed();

    expect(DB::table('policy_notifications')->count())->toBe(0);
});

// --- Envoi ---

test('envoi aux seuls comptes vérifiés, noté, et jamais deux fois — même avec --force', function () {
    Notification::fake();
    withChangelog(['e' => policyEntry()]);
    [$a, $b] = User::factory()->count(2)->create();
    $unverified = User::factory()->unverified()->create();

    $this->artisan('policy:notify', ['entry' => 'e', '--force' => true])->assertSuccessful();

    Notification::assertSentTo([$a, $b], PolicyUpdated::class);
    Notification::assertNotSentTo($unverified, PolicyUpdated::class);
    expect(DB::table('policy_notifications')->where('entry_id', 'e')->first())
        ->recipients->toBe(2)->failures->toBe(0);

    $this->artisan('policy:notify', ['entry' => 'e', '--force' => true])->assertFailed();
    Notification::assertSentToTimes($a, PolicyUpdated::class, 1);
});

test('sans --force, la commande affiche le nombre de destinataires et attend la confirmation', function () {
    Notification::fake();
    withChangelog(['e' => policyEntry()]);
    User::factory()->count(3)->create();

    $this->artisan('policy:notify', ['entry' => 'e'])
        ->expectsOutputToContain(__('policy.command.recipients', ['count' => 3]))
        ->expectsConfirmation(__('policy.command.confirm'), 'no')
        ->assertFailed();

    Notification::assertNothingSent();
    expect(DB::table('policy_notifications')->count())->toBe(0);

    $this->artisan('policy:notify', ['entry' => 'e'])
        ->expectsConfirmation(__('policy.command.confirm'), 'yes')
        ->assertSuccessful();

    Notification::assertCount(3);
});

test('un échec d\'envoi n\'interrompt pas les autres, et l\'entrée est notée envoyée', function () {
    config(['mail.default' => 'array']);
    withChangelog(['e' => policyEntry()]);
    [$ok, $bad] = User::factory()->count(2)->create();
    Event::listen(NotificationSending::class, function (NotificationSending $event) use ($bad) {
        if ($event->notifiable->is($bad)) {
            throw new RuntimeException('SMTP refusé');
        }
    });

    $this->artisan('policy:notify', ['entry' => 'e', '--force' => true])->assertFailed();

    expect(DB::table('policy_notifications')->where('entry_id', 'e')->first())
        ->recipients->toBe(2)->failures->toBe(1);
});

// --- L'email ---

test('l\'email est bilingue, français d\'abord, renvoie à la politique, sans désinscription ni clé brute', function () {
    $html = (new PolicyUpdated(policyEntry()))->toMail(User::factory()->make())->render()->toHtml();

    expect($html)
        ->toContain('Le délai passe à 1 an.')
        ->toContain('The delay becomes 1 year.')
        ->toContain('04/10/2026')
        ->toContain(rtrim(config('app.frontend_url'), '/').'/legal/privacy')
        ->not->toContain('policy.')
        ->not->toMatch('/unsubscribe|d[ée]sinscri|d[ée]sabonn/i');
    expect(strpos($html, 'Le délai passe'))->toBeLessThan(strpos($html, 'The delay becomes'));
});

test('chaque clé de l\'email existe en français ET en anglais', function () {
    $keys = fn (string $locale) => array_keys(Arr::dot((require lang_path("{$locale}/policy.php"))['email']));

    expect($keys('en'))->toBe($keys('fr'));
});
