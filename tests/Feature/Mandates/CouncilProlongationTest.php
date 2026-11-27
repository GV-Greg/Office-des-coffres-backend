<?php

use App\Models\CouncilMandate;
use App\Services\MandateAuthority;
use App\Support\CouncilWaiting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

/*
| L'attente du successeur (Greg, 27/11/2026) : après sa fin enregistrée (terme + 2 jours), un conseiller garde les droits de
| son poste tant que l'Office ne connaît pas de successeur, jusqu'au terme + 30 jours au plus — SAUF écrire un prix. C'est une
| souplesse de LECTURE : la fin enregistrée ne change pas.
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

/** Dix jours après le terme : la fin enregistrée est passée, l'attente court. */
function waitingNow($test): void
{
    Carbon::setTestNow($test->mandate->valid_until->copy()->addDays(10));
}

test('la fin enregistrée reste le terme plus 2 jours, avant comme pendant l\'attente', function () {
    $end = $this->mandate->valid_until->copy()->addDays(2);
    expect($this->mandate->holds_until->equalTo($end))->toBeTrue();

    waitingNow($this);
    $this->authority->canManageMines($this->bailli);
    $mandate = $this->mandate->fresh();

    expect($mandate->holds_until->equalTo($end))->toBeTrue()
        ->and($mandate->officePeriods()->first()->ended_at->equalTo($end))->toBeTrue()
        ->and($mandate->effectiveStatus())->toBe('expired');
});

test('pendant l\'attente le poste donne ses droits, sauf l\'écriture des prix', function () {
    waitingNow($this);

    expect($this->authority->canManageMines($this->bailli)?->id)->toBe($this->province->id)
        ->and($this->authority->canReadPrices($this->bailli)?->id)->toBe($this->province->id)
        ->and($this->authority->activeCouncilMandate($this->bailli)?->id)->toBe($this->mandate->id)
        ->and($this->authority->currentHolders($this->province, ['bailli'])->pluck('id')->all())->toBe([$this->bailli->id])
        ->and($this->mandate->fresh()->currentOfficeKey())->toBe('bailli')
        ->and($this->authority->canManagePrices($this->bailli))->toBeNull()
        ->and($this->authority->canEditPriceOn($this->bailli, $this->province, '2026-05-10'))->toBeFalse()
        // En attente, le mandat est lu comme « en cours », pas comme un mandat passé.
        ->and($this->authority->pastMineReaderMandates($this->bailli))->toHaveCount(0);
});

test('après le terme plus 30 jours, plus aucun droit ; le mandat devient un mandat passé', function () {
    Carbon::setTestNow($this->mandate->valid_until->copy()->addDays(30)->addHour());

    expect($this->authority->canManageMines($this->bailli))->toBeNull()
        ->and($this->authority->activeCouncilMandate($this->bailli))->toBeNull()
        ->and($this->authority->pastMineReaderMandates($this->bailli))->toHaveCount(1);
});

test('un nouveau conseil en fonction depuis le terme met fin à l\'attente', function () {
    atGameTime('2026-07-05 12:00');
    expect($this->authority->canManageMines($this->bailli))->not->toBeNull();

    approvedCouncil(mandatePlayer($this->map['cityA2']), $this->province, '2026-07-04', '2026-07-05');

    expect($this->authority->canManageMines($this->bailli))->toBeNull();
});

test('un autre titulaire du même poste le reprend à l\'ancien', function () {
    atGameTime('2026-07-05 12:00');
    $next = mandatePlayer($this->map['cityA2'], ['pseudo' => 'Suivant']);
    approvedCouncil($next, $this->province, '2026-07-04', '2026-07-05', 'bailli');

    expect($this->authority->canManageMines($this->bailli))->toBeNull()
        ->and($this->authority->canManageMines($next)?->id)->toBe($this->province->id);
});

test('une relève dans une AUTRE province ne met pas fin à l\'attente', function () {
    atGameTime('2026-07-05 12:00');
    approvedCouncil(mandatePlayer($this->map['cityB']), $this->map['provinceB'], '2026-07-04', '2026-07-05', 'bailli');

    expect($this->authority->canManageMines($this->bailli)?->id)->toBe($this->province->id);
});

test('aucune attente après une passation enregistrée ni après une révocation', function () {
    atGameTime('2026-07-01 12:00'); // après le terme, avant la fin enregistrée
    mandateWorkflow()->handover($this->province, '2026-07-01', [$this->mandate->id], []);
    atGameTime('2026-07-05 12:00');
    expect($this->authority->canManageMines($this->bailli))->toBeNull();

    atGameTime('2026-05-20 12:00');
    $other = mandatePlayer($this->map['cityB']);
    $revoked = approvedCouncil($other, $this->map['provinceB'], '2026-05-01', '2026-05-03', 'bailli');
    mandateWorkflow()->revoke($revoked, 'demission', '2026-05-20', 'Parti.', 'fr');
    atGameTime('2026-07-05 12:00');
    expect($this->authority->canManageMines($other))->toBeNull();
});

test('sans la durée en configuration (cache pas refait), aucune souplesse', function () {
    waitingNow($this);
    config(['mandates.council_waiting_days' => null]);

    expect($this->authority->canManageMines($this->bailli))->toBeNull();
});

test('l\'API du profil dit jusqu\'à quand le poste est conservé, et rien hors de l\'attente', function () {
    Passport::actingAs($this->bailli->user);
    $this->getJson('/api/v1/mandates')->assertOk()->assertJsonPath('mandates.0.kept_until', null);

    waitingNow($this);
    $deadline = CouncilWaiting::until($this->mandate->fresh());

    $this->getJson('/api/v1/mandates')->assertOk()
        ->assertJsonPath('mandates.0.status', 'expired')
        ->assertJsonPath('mandates.0.office_key', 'bailli')
        ->assertJsonPath('mandates.0.kept_until', $deadline->toIso8601String());
});

test('la commande remet à la normale une fin repoussée, après simulation, sans toucher une passation', function () {
    $normal = $this->mandate->valid_until->copy()->addDays(2);
    $pushed = $this->mandate->valid_until->copy()->addDays(30);
    mandateWorkflow()->setHoldsUntil($this->mandate, $pushed, 'nominal');
    $handed = approvedCouncil(mandatePlayer($this->map['cityB']), $this->map['provinceB'], '2026-05-01', '2026-05-03');
    mandateWorkflow()->setHoldsUntil($handed, $pushed, 'handover');

    Artisan::call('mandates:restore-council-end');
    expect($this->mandate->fresh()->holds_until->equalTo($pushed))->toBeTrue();

    Artisan::call('mandates:restore-council-end', ['--force' => true]);
    $mandate = $this->mandate->fresh();
    expect($mandate->holds_until->equalTo($normal))->toBeTrue()
        ->and($mandate->officePeriods()->first()->ended_at->equalTo($normal))->toBeTrue()
        ->and(CouncilMandate::find($handed->id)->holds_until->equalTo($pushed))->toBeTrue();
});

test('la commande refuse de tourner avec la configuration pas encore remise en cache', function () {
    config(['mandates.council_waiting_days' => null]);

    $this->artisan('mandates:restore-council-end', ['--force' => true])->assertFailed();
});
