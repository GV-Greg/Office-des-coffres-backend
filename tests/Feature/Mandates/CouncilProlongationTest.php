<?php

use App\Models\CouncilMandate;
use App\Notifications\MandateReminder;
use App\Services\MandateAuthority;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

/*
| L'attente du successeur (Greg, 27/11/2026) : au terme de son mandat, un conseiller reste en poste tant que l'Office ne
| connaît pas de successeur, 30 jours au plus. Tous les droits du poste, SAUF modifier un prix.
*/

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    $this->province = $this->map['provinceA'];
    $this->authority = app(MandateAuthority::class);
    atGameTime('2026-05-03 12:00');
    $this->bailli = mandatePlayer($this->map['cityA'], ['pseudo' => 'Bailli']);
    $this->mandate = approvedCouncil($this->bailli, $this->province, '2026-05-01', '2026-05-03', 'bailli');
});

afterEach(fn () => Carbon::setTestNow());

test('la fin effective est le terme du mandat plus 30 jours', function () {
    expect($this->mandate->holds_until->equalTo($this->mandate->valid_until->copy()->addDays(30)))->toBeTrue();
});

test('pendant l\'attente le poste donne ses droits, sauf l\'écriture des prix', function () {
    Carbon::setTestNow($this->mandate->valid_until->copy()->subHour());
    expect($this->authority->canManagePrices($this->bailli)?->id)->toBe($this->province->id)
        ->and($this->authority->canEditPriceOn($this->bailli, $this->province, '2026-05-10'))->toBeTrue();

    Carbon::setTestNow($this->mandate->valid_until->copy()->addDays(10));
    expect($this->mandate->fresh()->effectiveStatus())->toBe('extended')
        ->and($this->authority->canManageMines($this->bailli)?->id)->toBe($this->province->id)
        ->and($this->authority->canReadPrices($this->bailli)?->id)->toBe($this->province->id)
        ->and($this->authority->canManagePrices($this->bailli))->toBeNull()
        ->and($this->authority->canEditPriceOn($this->bailli, $this->province, '2026-05-10'))->toBeFalse();
});

test('après 30 jours sans successeur, plus aucun droit', function () {
    Carbon::setTestNow($this->mandate->holds_until->copy()->addMinute());

    expect($this->authority->canManageMines($this->bailli))->toBeNull()
        ->and($this->authority->canReadPrices($this->bailli))->toBeNull();
});

test('la passation met fin à l\'attente', function () {
    atGameTime('2026-07-05 12:00'); // après le terme
    mandateWorkflow()->handover($this->province, '2026-07-04', [$this->mandate->id], []);

    expect($this->mandate->fresh()->holds_until_set_by)->toBe('handover')
        ->and($this->authority->canManageMines($this->bailli))->toBeNull();
});

test('un autre titulaire du même poste le reprend à l\'ancien, sans attendre', function () {
    atGameTime('2026-07-05 12:00');
    $next = mandatePlayer($this->map['cityA2'], ['pseudo' => 'Suivant']);
    approvedCouncil($next, $this->province, '2026-07-04', '2026-07-05', 'bailli');

    expect($this->authority->canManageMines($this->bailli))->toBeNull()
        ->and($this->authority->canManageMines($next)?->id)->toBe($this->province->id);
});

test('le rappel d\'un conseiller sans passation part 4 jours après le terme, et dit le terme', function () {
    Carbon::setTestNow($this->mandate->valid_until->copy()->addDays(3));
    $this->artisan('mandates:send-reminders');
    Notification::assertNotSentTo($this->bailli->user, MandateReminder::class);

    Carbon::setTestNow($this->mandate->valid_until->copy()->addDays(4)->addHour());
    $this->artisan('mandates:send-reminders');
    Notification::assertSentTo($this->bailli->user, MandateReminder::class, function (MandateReminder $notification) {
        $text = implode("\n", $notification->lines());

        return str_contains($text, 'arrivé à son terme') && str_contains($text, 'came to an end')
            && ! str_contains($text, "s'est terminé") && ! str_contains($text, 'mandates.');
    });
});

test('la commande ajuste les mandats en cours fixés par l\'ancienne règle, après simulation', function () {
    $old = $this->mandate->valid_until->copy()->addDays(2);
    mandateWorkflow()->setHoldsUntil($this->mandate, $old, 'nominal');

    Artisan::call('mandates:apply-council-prolongation');
    expect($this->mandate->fresh()->holds_until->equalTo($old))->toBeTrue();

    Artisan::call('mandates:apply-council-prolongation', ['--force' => true]);
    $mandate = $this->mandate->fresh();
    expect($mandate->holds_until->equalTo($mandate->valid_until->copy()->addDays(30)))->toBeTrue()
        ->and($mandate->officePeriods()->first()->ended_at->equalTo($mandate->holds_until))->toBeTrue();

    Artisan::call('mandates:apply-council-prolongation', ['--force' => true]);
    expect($this->mandate->fresh()->holds_until->equalTo($mandate->holds_until))->toBeTrue();
});

test('la commande ne ranime pas un mandat terminé et ne touche ni passation ni révocation', function () {
    $ended = $this->mandate->valid_until->copy()->addDays(2);
    mandateWorkflow()->setHoldsUntil($this->mandate, $ended, 'nominal');
    Carbon::setTestNow($ended->copy()->addDay());
    Artisan::call('mandates:apply-council-prolongation', ['--force' => true]);
    expect($this->mandate->fresh()->holds_until->equalTo($ended))->toBeTrue();

    atGameTime('2026-05-20 12:00');
    $other = mandatePlayer($this->map['cityA2']);
    $handed = approvedCouncil($other, $this->map['provinceB'], '2026-05-01', '2026-05-03');
    $end = $handed->valid_until->copy()->addDay();
    mandateWorkflow()->setHoldsUntil($handed, $end, 'handover');
    Artisan::call('mandates:apply-council-prolongation', ['--force' => true]);

    expect(CouncilMandate::find($handed->id)->holds_until->equalTo($end))->toBeTrue();
});

test('sans la durée en configuration (cache pas refait), la tâche de rappel refuse de tourner', function () {
    Carbon::setTestNow($this->mandate->valid_until->copy()->addDay());
    config(['mandates.council_reminder_after_days' => null]);

    $this->artisan('mandates:send-reminders')->assertFailed();
    Notification::assertNotSentTo($this->bailli->user, MandateReminder::class);
});

