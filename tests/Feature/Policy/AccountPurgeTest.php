<?php

use App\Console\Commands\PurgeAccounts;
use App\Models\OfficeHistoryArchive;
use App\Models\User;
use App\Notifications\InactiveAccountNotice;
use App\Notifications\UnverifiedAccountReminder;
use App\Services\AccountPurge;
use App\Support\LastSeen;
use App\Support\MandateHeartbeat;
use Database\Seeders\PassportClientSeeder;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

// Purge des comptes (/legal/privacy §5 ; brief admin/content/brief-politique-promesses.md §2 ; fil
// admin/echanges/politique-promesses, Q3-Q4, Q8-Q9). Horloge forcée : en prod, rien ne peut agir
// avant l'automne 2027 (§2.5) — ces tests sont le seul endroit où on la voit travailler.

const PURGE_NOW = '2026-10-04 09:00:00';

beforeEach(function () {
    Notification::fake();
    Carbon::setTestNow(PURGE_NOW);
    config(['accounts.enforce' => true]);
});

afterEach(fn () => Carbon::setTestNow());

function daysAgo(int $days): Carbon
{
    return Carbon::parse(PURGE_NOW)->subDays($days);
}

function verifiedAccount(?int $lastSeenDaysAgo, ?int $noticeDaysAgo = null): User
{
    return User::factory()->create([
        'last_seen_at' => $lastSeenDaysAgo === null ? null : daysAgo($lastSeenDaysAgo),
        'deletion_notice_sent_at' => $noticeDaysAgo === null ? null : daysAgo($noticeDaysAgo),
    ]);
}

function unverifiedAccount(int $createdDaysAgo, ?int $reminderDaysAgo = null): User
{
    return User::factory()->unverified()->create([
        'created_at' => daysAgo($createdDaysAgo),
        'last_seen_at' => daysAgo($createdDaysAgo),
        'deletion_notice_sent_at' => $reminderDaysAgo === null ? null : daysAgo($reminderDaysAgo),
    ]);
}

// ─── Comptes vérifiés : inactivité ─────────────────────────────────────────────────────────

test('préavis à 11 mois sans passage (335 jours), pas avant', function () {
    $due = verifiedAccount(335);
    $early = verifiedAccount(334);

    $this->artisan('accounts:purge')->assertSuccessful();

    Notification::assertSentTo($due, InactiveAccountNotice::class);
    Notification::assertNotSentTo($early, InactiveAccountNotice::class);
    expect($due->fresh()->deletion_notice_sent_at->toDateTimeString())->toBe(PURGE_NOW)
        ->and($early->fresh()->deletion_notice_sent_at)->toBeNull();
});

test('suppression à 12 mois ET 30 jours après le préavis — l\'un sans l\'autre ne suffit pas', function () {
    $due = verifiedAccount(365, 30);
    $noticeTooRecent = verifiedAccount(400, 29);
    $notInactiveEnough = verifiedAccount(364, 40);

    $this->artisan('accounts:purge')->assertSuccessful();

    expect(User::find($due->id))->toBeNull()
        ->and(User::find($noticeTooRecent->id))->not->toBeNull()
        ->and(User::find($notInactiveEnough->id))->not->toBeNull();
});

test('un joueur qui revient annule son préavis : il n\'est plus supprimable', function () {
    $user = verifiedAccount(340, 31);

    LastSeen::record($user->fresh());
    $this->artisan('accounts:purge')->assertSuccessful();

    expect(User::find($user->id))->not->toBeNull()
        ->and($user->fresh()->deletion_notice_sent_at)->toBeNull();
});

test('🔴 garde-fou : un compte inactif depuis 2 ans SANS préavis enregistré n\'est pas supprimé — il est prévenu', function () {
    $user = verifiedAccount(730);

    $this->artisan('accounts:purge')->assertSuccessful();

    expect(User::find($user->id))->not->toBeNull();
    Notification::assertSentTo($user, InactiveAccountNotice::class);
});

test('🔴 garde-fou : la suppression de la purge refuse tout compte sans avis, quel que soit l\'appelant', function (?int $noticeDaysAgo) {
    $user = verifiedAccount(730, $noticeDaysAgo);

    expect(fn () => app(AccountPurge::class)->delete($user))->toThrow(LogicException::class);
    expect(User::find($user->id))->not->toBeNull();
})->with(['aucun avis' => [null], 'avis de 29 jours' => [29]]);

test('🔴 garde-fou : un compte au last_seen_at nul n\'est jamais prévenu ni supprimé', function () {
    // Deux comptes vieux de presque 3 ans : un COALESCE(last_seen_at, created_at) les rendrait
    // l'un prévenable, l'autre supprimable.
    $neverNotified = verifiedAccount(null);
    $alreadyNotified = verifiedAccount(null, 400);
    foreach ([$neverNotified, $alreadyNotified] as $user) {
        $user->forceFill(['created_at' => daysAgo(1000)])->saveQuietly();
    }

    $this->artisan('accounts:purge')->assertSuccessful();

    Notification::assertNothingSent();
    expect(User::find($neverNotified->id))->not->toBeNull()
        ->and(User::find($alreadyNotified->id))->not->toBeNull();
    expect(fn () => app(AccountPurge::class)->delete($alreadyNotified->fresh()))->toThrow(LogicException::class);
});

test('la suppression passe par la porte unique : historique archivé, jetons et traces effacés', function () {
    $this->seed(PassportClientSeeder::class);
    $map = mandateMap();
    $user = mandatePlayer($map['cityA'])->user;
    Carbon::setTestNow(daysAgo(400));
    approvedMayor($user->characters()->first(), $map['cityA'], daysAgo(400)->toDateString());
    Carbon::setTestNow(PURGE_NOW);
    $user->createToken('vieux');
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'x', 'created_at' => now()]);
    $user->forceFill(['last_seen_at' => daysAgo(365), 'deletion_notice_sent_at' => daysAgo(30)])->saveQuietly();

    $this->artisan('accounts:purge')->assertSuccessful();

    expect(User::find($user->id))->toBeNull()
        ->and(OfficeHistoryArchive::count())->toBeGreaterThan(0)
        ->and(DB::table('oauth_access_tokens')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', $user->email)->count())->toBe(0);
});

test('un compte dont un personnage tient un mandat en cours n\'est pas supprimé : signalé sur le tableau de bord', function () {
    $map = mandateMap();
    $character = mandatePlayer($map['cityA']);
    approvedMayor($character, $map['cityA'], daysAgo(2)->toDateString());
    $user = $character->user;
    $user->forceFill(['last_seen_at' => daysAgo(365), 'deletion_notice_sent_at' => daysAgo(30)])->saveQuietly();

    $this->artisan('accounts:purge')->expectsOutputToContain(__('accounts.command.actions.blocked'))->assertSuccessful();

    expect(User::find($user->id))->not->toBeNull()
        ->and(Cache::get(PurgeAccounts::BLOCKED_CACHE_KEY))->toBe([
            ['id' => $user->id, 'email' => $user->email, 'characters' => [$character->pseudo]],
        ]);
    $this->actingAs(mandateAdmin())->get('/dashboard')->assertOk()
        ->assertSee(__('accounts.dashboard.blocked_title'))
        ->assertSee($character->pseudo);
});

test('contrôle positif : sans compte bloqué, le tableau de bord ne dit rien', function () {
    $this->artisan('accounts:purge')->assertSuccessful();

    $this->actingAs(mandateAdmin())->get('/dashboard')->assertOk()
        ->assertDontSee(__('accounts.dashboard.blocked_title'));
});

// ─── Comptes jamais confirmés ──────────────────────────────────────────────────────────────

test('rappel à J+23, pas avant', function () {
    $due = unverifiedAccount(23);
    $early = unverifiedAccount(22);

    $this->artisan('accounts:purge')->assertSuccessful();

    Notification::assertSentTo($due, UnverifiedAccountReminder::class);
    Notification::assertNotSentTo($early, UnverifiedAccountReminder::class);
});

test('suppression à J+30 et au moins 7 jours après le rappel', function () {
    $due = unverifiedAccount(30, 7);
    $reminderTooRecent = unverifiedAccount(31, 6);

    $this->artisan('accounts:purge')->assertSuccessful();

    expect(User::find($due->id))->toBeNull()
        ->and(User::find($reminderTooRecent->id))->not->toBeNull();
});

test('Q3 : un compte non confirmé déjà ancien reçoit d\'abord son rappel, puis 7 jours pleins', function () {
    $old = unverifiedAccount(90);

    $this->artisan('accounts:purge')->assertSuccessful();
    expect(User::find($old->id))->not->toBeNull();
    Notification::assertSentTo($old, UnverifiedAccountReminder::class);

    Carbon::setTestNow(Carbon::parse(PURGE_NOW)->addDays(6));
    $this->artisan('accounts:purge')->assertSuccessful();
    expect(User::find($old->id))->not->toBeNull();

    Carbon::setTestNow(Carbon::parse(PURGE_NOW)->addDays(7));
    $this->artisan('accounts:purge')->assertSuccessful();
    expect(User::find($old->id))->toBeNull();
    Notification::assertSentToTimes($old, UnverifiedAccountReminder::class, 1);
});

test('Q4 : le rappel porte un lien de vérification valable jusqu\'à la suppression, et qui confirme', function () {
    $this->seed(PassportClientSeeder::class); // verifyEmail émet un jeton
    $user = unverifiedAccount(23);
    $this->artisan('accounts:purge')->assertSuccessful();

    $url = null;
    Notification::assertSentTo($user, UnverifiedAccountReminder::class, function ($notification) use ($user, &$url) {
        $url = $notification->verificationUrl($user);

        return $notification->deletionDate->toDateString() === daysAgo(23)->addDays(30)->toDateString();
    });

    Carbon::setTestNow(Carbon::parse(PURGE_NOW)->addDays(6)); // bien au-delà des 60 minutes d'un lien d'inscription
    $this->get($url)->assertRedirect();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and($user->fresh()->deletion_notice_sent_at)->toBeNull();
});

// ─── Simulation, échecs, configuration ─────────────────────────────────────────────────────

test('--dry-run liste sans rien envoyer, écrire ni supprimer', function () {
    $toDelete = verifiedAccount(365, 30);
    $toNotify = verifiedAccount(335);

    $this->artisan('accounts:purge', ['--dry-run' => true])
        ->expectsOutputToContain(__('accounts.command.dry_run'))
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect(User::find($toDelete->id))->not->toBeNull()
        ->and($toNotify->fresh()->deletion_notice_sent_at)->toBeNull();
});

test('accounts.enforce = false impose la simulation, même sans --dry-run', function () {
    config(['accounts.enforce' => false]);
    $toDelete = unverifiedAccount(30, 7);
    $toRemind = unverifiedAccount(23);

    $this->artisan('accounts:purge')
        ->expectsOutputToContain(__('accounts.command.not_enforced'))
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect(User::find($toDelete->id))->not->toBeNull()
        ->and($toRemind->fresh()->deletion_notice_sent_at)->toBeNull();
});

test('le dépôt livre la purge en simulation : le texte en ligne annonce encore 2 ans', function () {
    expect((require config_path('accounts.php'))['enforce'])->toBeFalse();
});

test('un avis qui n\'est pas parti n\'est pas enregistré : pas de suppression possible sur lui', function () {
    config(['mail.default' => 'array']);
    Notification::swap(new ChannelManager(app())); // vrai envoi : le faux de beforeEach ne lève jamais
    $user = verifiedAccount(335);
    Event::listen(NotificationSending::class, fn () => throw new RuntimeException('SMTP refusé'));

    $this->artisan('accounts:purge')->assertFailed();

    expect($user->fresh()->deletion_notice_sent_at)->toBeNull();
});

test('les durées viennent de config/accounts.php', function () {
    config(['accounts.inactivity_days' => 100, 'accounts.inactivity_notice_days' => 10]);
    $user = verifiedAccount(90);

    $this->artisan('accounts:purge')->assertSuccessful();

    Notification::assertSentTo($user, InactiveAccountNotice::class);
});

test('chaque passage réussi est enregistré pour l\'alerte du planificateur', function () {
    $this->artisan('accounts:purge')->assertSuccessful();

    expect(MandateHeartbeat::lastSuccess('accounts'))->not->toBeNull()
        ->and(__('mandates.scheduler.tasks.accounts'))->not->toBe('mandates.scheduler.tasks.accounts');
});

// ─── Les emails ────────────────────────────────────────────────────────────────────────────

test('les deux emails sont bilingues, français d\'abord, sans clé brute', function (string $type) {
    $user = $type === 'inactive' ? verifiedAccount(335) : unverifiedAccount(23);
    $notification = $type === 'inactive'
        ? new InactiveAccountNotice($user->last_seen_at, daysAgo(-30))
        : new UnverifiedAccountReminder(daysAgo(-7));

    $html = $notification->toMail($user)->render()->toHtml();

    expect($html)->toContain('Bonjour')->toContain('Hello')
        ->toContain(daysAgo(-($type === 'inactive' ? 30 : 7))->format('d/m/Y'))
        ->not->toContain('accounts.');
    expect(strpos($html, 'Bonjour'))->toBeLessThan(strpos($html, 'Hello'));
})->with(['inactive', 'unverified']);

test('chaque clé des emails existe en français ET en anglais', function () {
    $keys = fn (string $locale) => array_keys(Arr::dot((require lang_path("{$locale}/accounts.php"))['email']));

    expect($keys('en'))->toBe($keys('fr'));
});
