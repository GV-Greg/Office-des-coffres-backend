<?php

use App\Models\Mandate;
use App\Models\MayorMandate;
use App\Notifications\MandateReminder;
use App\Notifications\MandateVerificationDigest;
use App\Support\MandateHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

// Lot 3 : rappels, récapitulatif de la file de vérification, purge des refus, battement du
// planificateur (admin/content/brief-mandats.md ; fil admin/echanges/mandats-lot3).

beforeEach(function () {
    Notification::fake();
    Cache::flush();
    $this->map = mandateMap();
    atGameTime('2026-05-01 12:00');
});

afterEach(fn () => Carbon::setTestNow());

// ─── Rappels (2 jours APRÈS la fin effective — décision de Greg, fil lot 3, tour 07) ─────────

test('le rappel part 2 jours après la fin effective, pas avant', function () {
    $player = mandatePlayer($this->map['cityA']);
    $mandate = approvedMayor($player, $this->map['cityA'], '2026-05-01'); // fin effective le 31/05 00:00
    Notification::fake();

    // Pendant l'élection et juste après la fin : rien, le joueur ne sait pas encore s'il est réélu.
    foreach ([-5, 0, 1] as $days) {
        Carbon::setTestNow($mandate->holds_until->copy()->addDays($days)->addHour());
        $this->artisan('mandates:send-reminders')->assertSuccessful();
    }
    Carbon::setTestNow($mandate->holds_until->copy()->addDays(2)->subSecond());
    $this->artisan('mandates:send-reminders');
    Notification::assertNothingSent();

    Carbon::setTestNow($mandate->holds_until->copy()->addDays(2));
    $this->artisan('mandates:send-reminders')->assertSuccessful();
    Notification::assertSentToTimes($player->user, MandateReminder::class, 1);
    expect($mandate->fresh()->reminder_sent_at)->not->toBeNull();
});

test('pas de rappel une fois le mandat devenu non renouvelable (plus de 15 jours)', function () {
    $player = mandatePlayer($this->map['cityA']);
    $mandate = approvedMayor($player, $this->map['cityA'], '2026-05-01');
    Notification::fake();

    Carbon::setTestNow($mandate->holds_until->copy()->addDays(15)->addSecond());
    $this->artisan('mandates:send-reminders');

    Notification::assertNothingSent();
});

test('le même rappel ne part jamais deux fois', function () {
    $player = mandatePlayer($this->map['cityA']);
    $mandate = approvedMayor($player, $this->map['cityA'], '2026-05-01');
    Carbon::setTestNow($mandate->holds_until->copy()->addDays(3));

    $this->artisan('mandates:send-reminders');
    Carbon::setTestNow($mandate->holds_until->copy()->addDays(4));
    $this->artisan('mandates:send-reminders');

    Notification::assertSentToTimes($player->user, MandateReminder::class, 1);
});

test('aucun rappel dès qu\'un renouvellement existe, en attente ou validé', function (bool $approveRenewal) {
    $player = mandatePlayer($this->map['cityA']);
    $mandate = approvedMayor($player, $this->map['cityA'], '2026-05-01');
    Carbon::setTestNow($mandate->holds_until->copy()->addDay());
    $renewal = mandateWorkflow()->renew($mandate, Carbon::now('Europe/Paris')->subDay()->format('Y-m-d'), 'https://forum.example/r');
    if ($approveRenewal) {
        mandateWorkflow()->approve($renewal);
    }
    Carbon::setTestNow($mandate->holds_until->copy()->addDays(3));
    Notification::fake();

    $this->artisan('mandates:send-reminders');

    Notification::assertNotSentTo($player->user, MandateReminder::class);
})->with(['renouvellement en attente' => false, 'renouvellement validé' => true]);

test('aucun rappel pour un mandat révoqué', function () {
    $player = mandatePlayer($this->map['cityA']);
    $mandate = approvedMayor($player, $this->map['cityA'], '2026-05-01');
    mandateWorkflow()->revoke($mandate, 'demission', null, null, null);
    Carbon::setTestNow($mandate->fresh()->holds_until->copy()->addDays(3));
    Notification::fake();

    $this->artisan('mandates:send-reminders');

    Notification::assertNothingSent();
});

test('pas de rappel au maire sortant si un autre personnage a été validé pour la même mairie', function () {
    $outgoing = mandatePlayer($this->map['cityA']);
    $mandate = approvedMayor($outgoing, $this->map['cityA'], '2026-05-01'); // fin le 31/05
    atGameTime('2026-06-01 12:00');
    approvedMayor(mandatePlayer($this->map['cityA2']), $this->map['cityA'], '2026-05-31'); // successeur validé
    Notification::fake();

    Carbon::setTestNow($mandate->holds_until->copy()->addDays(3));
    $this->artisan('mandates:send-reminders');

    Notification::assertNotSentTo($outgoing->user, MandateReminder::class);
});

test('contrôle positif : une demande concurrente encore en attente n\'empêche pas le rappel', function () {
    $outgoing = mandatePlayer($this->map['cityA']);
    $mandate = approvedMayor($outgoing, $this->map['cityA'], '2026-05-01');
    atGameTime('2026-06-01 12:00');
    mandateWorkflow()->request(mandatePlayer($this->map['cityA2']), 'mayor', [
        'city_id' => $this->map['cityA']->id, 'started_at' => '2026-05-31', 'announcement_url' => 'https://forum.example/s',
    ]);
    Notification::fake();

    Carbon::setTestNow($mandate->holds_until->copy()->addDays(3));
    $this->artisan('mandates:send-reminders');

    Notification::assertSentTo($outgoing->user, MandateReminder::class);
});

test('un conseiller est rappelé 2 jours après sa passation, pas après son 60e jour, et le texte dit la date effective', function () {
    $player = mandatePlayer($this->map['cityA']);
    $mandate = approvedCouncil($player, $this->map['provinceA'], '2026-05-01', '2026-05-01');
    // Fin nominale le 30/06 ; prolongé, puis passation le 03/07.
    Carbon::setTestNow($mandate->valid_until->copy()->addHour());
    mandateWorkflow()->extendProvince($this->map['provinceA'], 'vote en cours');
    atGameTime('2026-07-03 12:00');
    mandateWorkflow()->handover($this->map['provinceA'], '2026-07-03', [$mandate->id], []);
    Notification::fake();

    atGameTime('2026-07-02 12:00'); // 2 jours après la fin NOMINALE : encore trop tôt
    $this->artisan('mandates:send-reminders');
    Notification::assertNothingSent();

    atGameTime('2026-07-05 00:00'); // 2 jours après la passation
    $this->artisan('mandates:send-reminders');
    Notification::assertSentTo($player->user, MandateReminder::class, function (MandateReminder $notification) {
        $text = implode("\n", $notification->lines());

        return str_contains($text, '03/07/2026') && str_contains($text, "s'est terminé")
            && str_contains($text, 'ended on') && ! str_contains($text, 'mandates.');
    });
});

test('une tâche qui échoue à mi-parcours n\'écrit pas son passage, et la relance ne renvoie pas les rappels partis', function () {
    $first = mandatePlayer($this->map['cityA']);
    $mandateA = approvedMayor($first, $this->map['cityA'], '2026-05-01');
    $second = mandatePlayer($this->map['cityA2']);
    approvedMayor($second, $this->map['cityA2'], '2026-05-01');
    Carbon::setTestNow($mandateA->holds_until->copy()->addDays(3));

    // Le deuxième envoi lève une exception : la tâche s'interrompt.
    $calls = 0;
    $this->app->instance(Dispatcher::class, new class($calls) implements Dispatcher
    {
        public function __construct(public int &$calls) {}

        public function send($notifiables, $notification)
        {
            if (++$this->calls > 1) {
                throw new RuntimeException('serveur mail indisponible');
            }
        }

        public function sendNow($notifiables, $notification, ?array $channels = null) {}
    });

    expect(fn () => $this->artisan('mandates:send-reminders')->run())->toThrow(RuntimeException::class);
    expect(MandateHeartbeat::lastSuccess('reminders'))->toBeNull()
        ->and(MayorMandate::whereNotNull('reminder_sent_at')->count())->toBe(1);

    // Contrôle positif : la relance aboutit, écrit son passage, et n'envoie que le rappel manquant.
    Notification::fake();
    $this->app->instance(Dispatcher::class, Notification::getFacadeRoot()); // rebranche le faux serveur sain
    $this->artisan('mandates:send-reminders')->assertSuccessful();
    expect(MandateHeartbeat::lastSuccess('reminders'))->not->toBeNull();
    Notification::assertSentTimes(MandateReminder::class, 1);
});

// ─── Récapitulatif de la file de vérification ──────────────────────────────────────────────

test('une file sans retard n\'envoie aucun email, mais la tâche écrit son passage', function () {
    mandateAdmin();
    $mandate = approvedMayor(mandatePlayer($this->map['cityA']), $this->map['cityA'], '2026-05-01');
    Carbon::setTestNow($mandate->holds_until->copy()->addDays(3)); // seuil maire : 4 jours
    Notification::fake(); // oublie l'email de validation

    $this->artisan('mandates:verification-digest')->assertSuccessful();

    Notification::assertNothingSent();
    expect(MandateHeartbeat::lastSuccess('verification'))->not->toBeNull();
});

test('un maire terminé depuis 5 jours, un conseiller titré depuis 8 : un seul email aux admins, avec l\'ancienneté', function () {
    $admin = mandateAdmin();
    $mayor = approvedMayor(mandatePlayer($this->map['cityA'], ['pseudo' => 'MaireAncien']), $this->map['cityA'], '2026-05-01');
    $council = approvedCouncil(mandatePlayer($this->map['cityA2'], ['pseudo' => 'JugeAncien']), $this->map['provinceA'], '2026-04-28', '2026-04-30', 'juge');
    // Maire fini le 31/05 ; conseiller fini le 29/06. On se place au 07/07 : 37 et 8 jours.
    Carbon::setTestNow($council->holds_until->copy()->addDays(8));

    $this->artisan('mandates:verification-digest')->assertSuccessful();

    Notification::assertSentToTimes($admin, MandateVerificationDigest::class, 1);
    Notification::assertSentTo($admin, MandateVerificationDigest::class, function (MandateVerificationDigest $notification) {
        $text = implode("\n", $notification->lines());

        return str_contains($text, 'MaireAncien') && str_contains($text, 'JugeAncien')
            && str_contains($text, 'en retard depuis 1 jour') // conseiller : 8 − 7
            && str_contains($text, __('mandates.digest.section_mayors'))
            && str_contains($text, __('mandates.digest.section_councillors'));
    });
});

test('un conseiller sans poste terminé depuis 30 jours n\'est jamais signalé', function () {
    mandateAdmin();
    $plain = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-01');
    Carbon::setTestNow($plain->holds_until->copy()->addDays(30));
    Notification::fake(); // oublie l'email de validation

    $this->artisan('mandates:verification-digest');

    Notification::assertNothingSent();
});

test('le récapitulatif repart chaque jour tant que la ligne n\'est pas vérifiée', function () {
    $admin = mandateAdmin();
    $mayor = approvedMayor(mandatePlayer($this->map['cityA']), $this->map['cityA'], '2026-05-01');
    Carbon::setTestNow($mayor->holds_until->copy()->addDays(5));
    $this->artisan('mandates:verification-digest');
    Carbon::setTestNow($mayor->holds_until->copy()->addDays(6));
    $this->artisan('mandates:verification-digest');

    Notification::assertSentToTimes($admin, MandateVerificationDigest::class, 2);

    mandateWorkflow()->verify($mayor);
    Notification::fake();
    $this->artisan('mandates:verification-digest');
    Notification::assertNothingSent();
});

// ─── Purge des refus ───────────────────────────────────────────────────────────────────────

test('la purge supprime un refus de plus de 3 mois, jamais une demande en attente, quel que soit son âge', function () {
    $old = mandateWorkflow()->request(mandatePlayer($this->map['cityA']), 'mayor', [
        'city_id' => $this->map['cityA']->id, 'started_at' => '2026-05-01', 'announcement_url' => 'https://forum.example/a',
    ]);
    mandateWorkflow()->reject($old, 'date_incoherente', null, null);
    // Demande en attente déposée le 01/03 : six mois à la purge du 01/09.
    atGameTime('2026-03-01 12:00');
    $pending = mandateWorkflow()->request(mandatePlayer($this->map['cityA2']), 'mayor', [
        'city_id' => $this->map['cityA2']->id, 'started_at' => '2026-02-25', 'announcement_url' => 'https://forum.example/b',
    ]);
    atGameTime('2026-05-01 12:00');
    $recent = mandateWorkflow()->request(mandatePlayer($this->map['cityB']), 'mayor', [
        'city_id' => $this->map['cityB']->id, 'started_at' => '2026-05-01', 'announcement_url' => 'https://forum.example/c',
    ]);

    atGameTime('2026-08-01 12:00'); // 3 mois pile après le premier refus : pas encore
    mandateWorkflow()->reject($recent, 'autre', 'Trop tôt.', 'fr');
    atGameTime('2026-09-01 12:00');
    $this->artisan('mandates:purge-rejected')->assertSuccessful();

    expect(MayorMandate::find($old->id))->toBeNull()
        ->and(MayorMandate::find($recent->id))->not->toBeNull()
        ->and(MayorMandate::find($pending->id)?->status)->toBe(Mandate::PENDING)
        ->and(MandateHeartbeat::lastSuccess('purge'))->not->toBeNull();
});

// ─── Planificateur et alerte ───────────────────────────────────────────────────────────────

test('les trois tâches sont inscrites au planificateur, chaque jour à 09:00 heure de Paris', function () {
    $events = collect(app(Schedule::class)->events());

    foreach (MandateHeartbeat::TASKS as $command) {
        $event = $events->first(fn ($event) => str_contains($event->command ?? '', $command));
        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('0 9 * * *')
            ->and($event->timezone)->toBe('Europe/Paris');
    }
});

test('une tâche sans passage depuis plus de 36 h alerte sur le tableau de bord, et l\'alerte la nomme', function () {
    $admin = mandateAdmin();
    foreach (array_keys(MandateHeartbeat::TASKS) as $task) {
        MandateHeartbeat::record($task);
    }

    // Contrôle positif : tout est passé il y a une heure, aucune alerte.
    Carbon::setTestNow(Carbon::now()->addHour());
    $this->actingAs($admin)->get('/dashboard')->assertOk()->assertDontSee(__('mandates.scheduler.stale_title'));

    // 48 h plus tard, seule la purge a tourné : les deux autres sont nommées.
    Carbon::setTestNow(Carbon::now()->addHours(47));
    MandateHeartbeat::record('purge');
    $this->actingAs($admin)->get('/dashboard')->assertOk()
        ->assertSee(__('mandates.scheduler.stale_title'))
        ->assertSee(__('mandates.scheduler.tasks.reminders'))
        ->assertSee(__('mandates.scheduler.tasks.verification'))
        ->assertDontSee(__('mandates.scheduler.tasks.purge'));
});

test('mandates:status échoue tant qu\'une tâche est en retard', function () {
    $this->artisan('mandates:status')->assertFailed();

    foreach (array_keys(MandateHeartbeat::TASKS) as $task) {
        MandateHeartbeat::record($task);
    }
    $this->artisan('mandates:status')->assertSuccessful();
});
