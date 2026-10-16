<?php

use App\Models\User;
use App\Notifications\PolicyUpdated;
use App\Support\PolicyChangelog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
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

    $this->artisan('policy:notify', ['entries' => ['absente'], '--force' => true])->assertFailed();

    Notification::assertNothingSent();
    expect(DB::table('policy_notifications')->count())->toBe(0);
});

test('une entrée mal formée est refusée', function (array $overrides) {
    Notification::fake();
    withChangelog(['e' => policyEntry($overrides)]);
    User::factory()->create();

    $this->artisan('policy:notify', ['entries' => ['e'], '--force' => true])->assertFailed();

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

    $this->artisan('policy:notify', ['entries' => ['e'], '--force' => true])->assertFailed();

    Notification::assertNothingSent();
});

test('sans aucun compte vérifié, rien ne part et rien n\'est noté', function () {
    Notification::fake();
    withChangelog(['e' => policyEntry()]);
    User::factory()->unverified()->create();

    $this->artisan('policy:notify', ['entries' => ['e'], '--force' => true])->assertFailed();

    expect(DB::table('policy_notifications')->count())->toBe(0);
});

// --- Envoi ---

test('envoi aux seuls comptes vérifiés, noté, et jamais deux fois — même avec --force', function () {
    Notification::fake();
    withChangelog(['e' => policyEntry()]);
    [$a, $b] = User::factory()->count(2)->create();
    $unverified = User::factory()->unverified()->create();

    $this->artisan('policy:notify', ['entries' => ['e'], '--force' => true])->assertSuccessful();

    Notification::assertSentTo([$a, $b], PolicyUpdated::class);
    Notification::assertNotSentTo($unverified, PolicyUpdated::class);
    expect(DB::table('policy_notifications')->where('entry_id', 'e')->first())
        ->recipients->toBe(2)->failures->toBe(0);

    $this->artisan('policy:notify', ['entries' => ['e'], '--force' => true])->assertFailed();
    Notification::assertSentToTimes($a, PolicyUpdated::class, 1);
});

test('sans --force, la commande affiche le nombre de destinataires et attend la confirmation', function () {
    Notification::fake();
    withChangelog(['e' => policyEntry()]);
    User::factory()->count(3)->create();

    $this->artisan('policy:notify', ['entries' => ['e']])
        ->expectsOutputToContain(__('policy.command.recipients', ['count' => 3]))
        ->expectsConfirmation(__('policy.command.confirm'), 'no')
        ->assertFailed();

    Notification::assertNothingSent();
    expect(DB::table('policy_notifications')->count())->toBe(0);

    $this->artisan('policy:notify', ['entries' => ['e']])
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

    $this->artisan('policy:notify', ['entries' => ['e'], '--force' => true])->assertFailed();

    expect(DB::table('policy_notifications')->where('entry_id', 'e')->first())
        ->recipients->toBe(2)->failures->toBe(1);
});

// --- Un seul email par jour (Greg, 16/10/2026) ---

test('--day regroupe les entrées substantielles du jour en UN email, une ligne notée par entrée', function () {
    Notification::fake();
    withChangelog([
        'a' => policyEntry(['date' => '2026-10-16', 'summary' => ['fr' => 'Premier changement.', 'en' => 'First change.']]),
        'b' => policyEntry(['date' => '2026-10-16', 'summary' => ['fr' => 'Second changement.', 'en' => 'Second change.']]),
        'c' => policyEntry(['date' => '2026-10-16', 'substantial' => false]),
        'autre' => policyEntry(['date' => '2026-10-15']),
    ]);
    [$u1, $u2] = User::factory()->count(2)->create();

    $this->artisan('policy:notify', ['--day' => '2026-10-16', '--force' => true])->assertSuccessful();

    Notification::assertSentToTimes($u1, PolicyUpdated::class, 1);
    Notification::assertSentToTimes($u2, PolicyUpdated::class, 1);
    expect(DB::table('policy_notifications')->pluck('entry_id')->sort()->values()->all())->toBe(['a', 'b']);

    $html = (new PolicyUpdated(policyEntry(['summary' => ['fr' => 'Premier changement.', 'en' => 'First change.']]), [
        policyEntry(['summary' => ['fr' => 'Second changement.', 'en' => 'Second change.']]),
    ]))->toMail($u1)->render()->toHtml();
    expect(strpos($html, 'Premier changement.'))->toBeLessThan(strpos($html, 'Second changement.'))
        ->and(strpos($html, 'Second changement.'))->toBeLessThan(strpos($html, 'First change.'))
        ->and(strpos($html, 'First change.'))->toBeLessThan(strpos($html, 'Second change.'));
});

test('sans argument, la commande prend le jour d\'aujourd\'hui à Paris', function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-16 12:00', 'Europe/Paris'));
    withChangelog(['a' => policyEntry(['date' => '2026-10-16']), 'vieille' => policyEntry(['date' => '2026-10-04'])]);
    User::factory()->create();

    $this->artisan('policy:notify', ['--force' => true])->assertSuccessful();

    expect(DB::table('policy_notifications')->pluck('entry_id')->all())->toBe(['a']);
});

test('un second envoi le même jour est refusé, sauf --again', function () {
    Notification::fake();
    withChangelog(['a' => policyEntry(['date' => '2026-10-16']), 'b' => policyEntry(['date' => '2026-10-17'])]);
    User::factory()->create();

    $this->artisan('policy:notify', ['entries' => ['a'], '--force' => true])->assertSuccessful();
    $this->artisan('policy:notify', ['entries' => ['b'], '--force' => true])->assertFailed();
    expect(DB::table('policy_notifications')->count())->toBe(1);

    $this->artisan('policy:notify', ['entries' => ['b'], '--force' => true, '--again' => true])->assertSuccessful();
    expect(DB::table('policy_notifications')->count())->toBe(2);
});

test('jour sans entrée, jour illisible, ids et --day mêlés, jours différents : refusés, rien ne part', function () {
    Notification::fake();
    withChangelog(['a' => policyEntry(['date' => '2026-10-16']), 'b' => policyEntry(['date' => '2026-10-17'])]);
    User::factory()->create();

    $this->artisan('policy:notify', ['--day' => '2026-01-01', '--force' => true])->assertFailed();
    $this->artisan('policy:notify', ['--day' => 'hier', '--force' => true])->assertFailed();
    $this->artisan('policy:notify', ['entries' => ['a'], '--day' => '2026-10-16', '--force' => true])->assertFailed();
    $this->artisan('policy:notify', ['entries' => ['a', 'b'], '--force' => true])->assertFailed();

    Notification::assertNothingSent();
    expect(DB::table('policy_notifications')->count())->toBe(0);
});

test('--day ignore sans bruit une entrée déjà envoyée, mais ne renvoie rien si tout est parti', function () {
    Notification::fake();
    withChangelog(['a' => policyEntry(['date' => '2026-10-16']), 'b' => policyEntry(['date' => '2026-10-16'])]);
    User::factory()->create();
    DB::table('policy_notifications')->insert(['entry_id' => 'a', 'recipients' => 1, 'failures' => 0, 'sent_at' => '2026-10-10 10:00:00']);

    $this->artisan('policy:notify', ['--day' => '2026-10-16', '--force' => true])->assertSuccessful();
    expect(DB::table('policy_notifications')->pluck('entry_id')->sort()->values()->all())->toBe(['a', 'b']);

    $this->artisan('policy:notify', ['--day' => '2026-10-16', '--force' => true, '--again' => true])->assertFailed();
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
