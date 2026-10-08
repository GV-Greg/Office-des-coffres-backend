<?php

use App\Models\Mandate;
use App\Services\MandateAuthority;
use App\Support\MandateCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

// Autorité des mandats : MandateAuthority est le SEUL point d'entrée des modules. Règles :
// admin/content/brief-mandats.md et admin/echanges/mandats-lot1/ (Q1 à Q29).

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    $this->authority = app(MandateAuthority::class);
});

afterEach(fn () => Carbon::setTestNow());

// ─── Dates ─────────────────────────────────────────────────────────────────────────────────

test('la fin nominale compte 30 jours fixes pour un maire, sans effet de fin de mois', function () {
    // 31/01 + 30 jours = 02/03 (2026 n'est pas bissextile), et non « le 31/02 » ni le 28/02.
    expect(MandateCalendar::validUntil('mayor', '2026-01-31')->setTimezone('Europe/Paris')->format('Y-m-d H:i'))
        ->toBe('2026-03-02 00:00');
});

test('la fin nominale compte 60 jours pour un conseiller', function () {
    expect(MandateCalendar::validUntil('council', '2026-01-31')->setTimezone('Europe/Paris')->format('Y-m-d H:i'))
        ->toBe('2026-04-01 00:00');
});

test('un mandat qui traverse le passage à l\'heure d\'été finit à 00:00 heure de Paris', function () {
    // Passage à l'heure d'été le 29/03/2026 : la fin reste à minuit, jour civil, sans décalage d'une heure.
    $end = MandateCalendar::validUntil('mayor', '2026-03-15');

    expect($end->copy()->setTimezone('Europe/Paris')->format('Y-m-d H:i'))->toBe('2026-04-14 00:00')
        ->and($end->format('Y-m-d H:i'))->toBe('2026-04-13 22:00'); // stocké en UTC
});

test('les durées viennent de config/mandates.php', function () {
    config(['mandates.mayor_days' => 10, 'mandates.council_days' => 20, 'mandates.council_grace_days' => 5]);

    $mayorEnd = MandateCalendar::validUntil('mayor', '2026-05-01');
    $councilEnd = MandateCalendar::validUntil('council', '2026-05-01');

    expect($mayorEnd->setTimezone('Europe/Paris')->format('Y-m-d'))->toBe('2026-05-11')
        ->and($councilEnd->copy()->setTimezone('Europe/Paris')->format('Y-m-d'))->toBe('2026-05-21')
        ->and(MandateCalendar::nominalHoldsUntil('council', $councilEnd)->setTimezone('Europe/Paris')->format('Y-m-d'))->toBe('2026-05-26');
});

// ─── Maire ─────────────────────────────────────────────────────────────────────────────────

test('un maire validé est actif, avec une fin effective égale à sa fin nominale', function () {
    atGameTime('2026-05-10 12:00');
    $character = mandatePlayer($this->map['cityA']);
    $mandate = approvedMayor($character, $this->map['cityA'], '2026-05-01');

    expect($mandate->holds_until->equalTo($mandate->valid_until))->toBeTrue()
        ->and($mandate->holds_until_set_by)->toBe('nominal')
        ->and($this->authority->activeMayorMandate($character)?->id)->toBe($mandate->id);
});

test('l\'expiration est sèche : actif une seconde avant la fin, inactif à la fin', function () {
    atGameTime('2026-05-10 12:00');
    $character = mandatePlayer($this->map['cityA']);
    $mandate = approvedMayor($character, $this->map['cityA'], '2026-05-01');

    Carbon::setTestNow($mandate->holds_until->copy()->subSecond());
    expect($this->authority->activeMayorMandate($character))->not->toBeNull();

    Carbon::setTestNow($mandate->holds_until->copy());
    expect($this->authority->activeMayorMandate($character))->toBeNull()
        ->and($mandate->fresh()->effectiveStatus())->toBe('expired');
});

// ─── Conseiller ────────────────────────────────────────────────────────────────────────────

test('un conseiller élu sans passation n\'a aucune autorité, même après sa date de début', function () {
    atGameTime('2026-05-10 12:00');
    $character = mandatePlayer($this->map['cityA']);
    $mandate = approvedCouncil($character, $this->map['provinceA'], '2026-05-01');

    expect($mandate->in_office_from)->toBeNull()
        ->and($mandate->effectiveStatus())->toBe('elected')
        ->and($this->authority->activeCouncilMandate($character))->toBeNull();
});

test('la fin effective d\'un conseiller est la fin nominale + 2 jours, prise dans la config, et il est en prolongation entre les deux', function () {
    atGameTime('2026-05-10 12:00');
    $character = mandatePlayer($this->map['cityA']);
    $mandate = approvedCouncil($character, $this->map['provinceA'], '2026-05-01', '2026-05-03');

    expect(MandateCalendar::addGameDays($mandate->valid_until, config('mandates.council_grace_days'))->equalTo($mandate->holds_until))->toBeTrue();

    Carbon::setTestNow($mandate->valid_until->copy()->addHour());
    expect($mandate->fresh()->effectiveStatus())->toBe('extended')
        ->and($this->authority->activeCouncilMandate($character))->not->toBeNull();
});

test('une demande refusée ou un mandat révoqué ne sont jamais actifs maintenant', function () {
    atGameTime('2026-05-10 12:00');
    $refused = mandatePlayer($this->map['cityA']);
    $request = mandateWorkflow()->request($refused, 'mayor', [
        'city_id' => $this->map['cityA']->id, 'started_at' => '2026-05-01', 'announcement_url' => 'https://forum.example/a',
    ]);
    mandateWorkflow()->reject($request, 'annonce_non_concluante', null, null);

    $revoked = mandatePlayer($this->map['cityA2']);
    $mandate = approvedMayor($revoked, $this->map['cityA2'], '2026-05-01');
    mandateWorkflow()->revoke($mandate, 'demission', null, null, null);

    expect($this->authority->activeMayorMandate($refused))->toBeNull()
        ->and($this->authority->activeMayorMandate($revoked))->toBeNull()
        ->and($mandate->fresh()->status)->toBe(Mandate::REVOKED);
});

test('holdsCouncilOffice distingue les titres, et un conseiller sans poste n\'en détient aucun', function () {
    atGameTime('2026-05-10 12:00');
    $judge = mandatePlayer($this->map['cityA']);
    approvedCouncil($judge, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    $plain = mandatePlayer($this->map['cityA2']);
    approvedCouncil($plain, $this->map['provinceA'], '2026-05-01', '2026-05-03');

    expect($this->authority->holdsCouncilOffice($judge, 'juge'))->toBeTrue()
        ->and($this->authority->holdsCouncilOffice($judge, 'capitaine'))->toBeFalse()
        ->and($this->authority->holdsCouncilOffice($plain, 'juge'))->toBeFalse()
        ->and($this->authority->activeCouncilMandate($plain))->not->toBeNull();
});

// ─── canManageMines (Registre des mines, fil admin/echanges/registre-mines, R1) ───────────────

test('canManageMines renvoie la province du commissaire aux mines comme du bailli', function () {
    atGameTime('2026-05-10 12:00');
    $commissaire = mandatePlayer($this->map['cityA']);
    approvedCouncil($commissaire, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'commissaire_mines');
    $bailli = mandatePlayer($this->map['cityA2']);
    approvedCouncil($bailli, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'bailli');

    expect($this->authority->canManageMines($commissaire)?->id)->toBe($this->map['provinceA']->id)
        ->and($this->authority->canManageMines($bailli)?->id)->toBe($this->map['provinceA']->id);
});

test('canManageMines vérifie le TITRE : un autre poste ou un conseiller sans poste n\'y ont pas droit', function () {
    atGameTime('2026-05-10 12:00');
    $judge = mandatePlayer($this->map['cityA']);
    approvedCouncil($judge, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    $plain = mandatePlayer($this->map['cityA2']);
    approvedCouncil($plain, $this->map['provinceA'], '2026-05-01', '2026-05-03');
    $nobody = mandatePlayer($this->map['cityA']);

    expect($this->authority->canManageMines($judge))->toBeNull()
        ->and($this->authority->canManageMines($plain))->toBeNull()
        ->and($this->authority->activeCouncilMandate($plain))->not->toBeNull() // membre du conseil, et pourtant non
        ->and($this->authority->canManageMines($nobody))->toBeNull();
});

test('canManageMines donne la province du POSTE, pas celle de la résidence (Greg, 05/10/2026)', function () {
    atGameTime('2026-05-10 12:00');
    // Réside à Bourges (Berry), commissaire aux mines en Artois.
    $character = mandatePlayer($this->map['cityB']);
    approvedCouncil($character, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'commissaire_mines');

    expect($this->authority->canManageMines($character)?->id)->toBe($this->map['provinceA']->id)
        ->and($character->city->province_id)->toBe($this->map['provinceB']->id);
});

test('canManageMines ne répond que pour maintenant : un mandat pas encore en fonction ou fini ne compte pas', function () {
    // Un poste ne se pose que sur un mandat en fonction : on le crée le 10/05, puis on regarde avant.
    atGameTime('2026-05-10 12:00');
    $character = mandatePlayer($this->map['cityA']);
    $mandate = approvedCouncil($character, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'commissaire_mines');
    expect($this->authority->canManageMines($character))->not->toBeNull();

    atGameTime('2026-05-02 12:00'); // mandat pas encore en fonction (03/05), poste pas encore posé
    expect($this->authority->canManageMines($character))->toBeNull();

    Carbon::setTestNow($mandate->fresh()->holds_until->copy()->addMinute());
    expect($this->authority->canManageMines($character))->toBeNull();
});

// Greg, 08/10/2026 : le dirigeant (comte, duc…) CONSULTE le Registre des mines de sa province — il en
// est le chef ; l'écriture reste au commissaire aux mines et au bailli. Les autres conseillers, jamais.
test('canReadMines : le dirigeant consulte sans pouvoir écrire, les teneurs du registre consultent aussi', function () {
    atGameTime('2026-05-10 12:00');
    $leader = mandatePlayer($this->map['cityA']);
    approvedCouncil($leader, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'leader');
    $bailli = mandatePlayer($this->map['cityA2']);
    approvedCouncil($bailli, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'bailli');

    expect($this->authority->canReadMines($leader)?->id)->toBe($this->map['provinceA']->id)
        ->and($this->authority->canManageMines($leader))->toBeNull()
        ->and($this->authority->canReadMines($bailli)?->id)->toBe($this->map['provinceA']->id);
});

test('canReadMines vérifie le TITRE : un autre poste ou un conseiller sans poste ne consultent pas', function () {
    atGameTime('2026-05-10 12:00');
    $judge = mandatePlayer($this->map['cityA']);
    approvedCouncil($judge, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    $plain = mandatePlayer($this->map['cityA2']);
    approvedCouncil($plain, $this->map['provinceA'], '2026-05-01', '2026-05-03');

    expect($this->authority->canReadMines($judge))->toBeNull()
        ->and($this->authority->canReadMines($plain))->toBeNull();
});

// ─── Prolongation et passation (règle 2 bis) ───────────────────────────────────────────────

test('la prolongation ajoute 2 jours aux conseillers en prolongation, exige une note et ne touche jamais la fin nominale', function () {
    atGameTime('2026-03-05 12:00');
    $character = mandatePlayer($this->map['cityA']);
    $mandate = approvedCouncil($character, $this->map['provinceA'], '2026-03-01', '2026-03-03');
    $validUntil = $mandate->valid_until->copy();
    $holdsUntil = $mandate->holds_until->copy();

    Carbon::setTestNow($validUntil->copy()->addHour());
    $count = mandateWorkflow()->extendProvince($this->map['provinceA'], 'https://forum.example/vote');

    $mandate->refresh();
    expect($count)->toBe(1)
        ->and($mandate->valid_until->equalTo($validUntil))->toBeTrue()
        ->and($mandate->holds_until->equalTo(MandateCalendar::addGameDays($holdsUntil, 2)))->toBeTrue()
        ->and($mandate->holds_until_set_by)->toBe('extension')
        ->and($mandate->note)->toContain('https://forum.example/vote');
});

test('la prolongation ne s\'applique qu\'aux conseillers déjà en prolongation', function () {
    atGameTime('2026-03-05 12:00');
    approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-03-01', '2026-03-03');

    expect(fn () => mandateWorkflow()->extendProvince($this->map['provinceA'], 'note'))
        ->toThrow(ValidationException::class);
});

test('la passation clôt les sortants et met en fonction les entrants à la même seconde, sans trou ni chevauchement', function () {
    atGameTime('2026-03-05 12:00');
    $old = mandatePlayer($this->map['cityA']);
    $oldMandate = approvedCouncil($old, $this->map['provinceA'], '2026-01-03', '2026-01-05');
    $new = mandatePlayer($this->map['cityA2']);
    $newMandate = approvedCouncil($new, $this->map['provinceA'], '2026-03-02');

    // Dirigeant élu le 05/03 : postérieur à la fin nominale du sortant (04/03).
    mandateWorkflow()->handover($this->map['provinceA'], '2026-03-05', [$oldMandate->id], [$newMandate->id]);
    $leader = MandateCalendar::startOfGameDay('2026-03-05');

    Carbon::setTestNow($leader->copy()->subSecond());
    expect($this->authority->activeCouncilMandate($old))->not->toBeNull()
        ->and($this->authority->activeCouncilMandate($new))->toBeNull();

    Carbon::setTestNow($leader->copy());
    expect($this->authority->activeCouncilMandate($old))->toBeNull()
        ->and($this->authority->activeCouncilMandate($new))->not->toBeNull()
        ->and($oldMandate->fresh()->holds_until_set_by)->toBe('handover');
});

test('la passation refuse une date antérieure à la fin nominale des sortants, à l\'élection des entrants, ou future', function () {
    atGameTime('2026-03-05 12:00');
    $oldMandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-01-03', '2026-01-05');
    $newMandate = approvedCouncil(mandatePlayer($this->map['cityA2']), $this->map['provinceA'], '2026-03-02');
    $workflow = mandateWorkflow();

    // 03/03 : avant la fin nominale du sortant (04/03) ; 01/03 : avant l'élection de l'entrant (02/03).
    expect(fn () => $workflow->handover($this->map['provinceA'], '2026-03-03', [$oldMandate->id], []))
        ->toThrow(ValidationException::class)
        ->and(fn () => $workflow->handover($this->map['provinceA'], '2026-03-01', [], [$newMandate->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $workflow->handover($this->map['provinceA'], '2026-03-06', [$oldMandate->id], [$newMandate->id]))
        ->toThrow(ValidationException::class);
});

test('valider un nouveau conseiller ne clôt jamais le conseil sortant', function () {
    atGameTime('2026-03-05 12:00');
    $old = mandatePlayer($this->map['cityA']);
    $oldMandate = approvedCouncil($old, $this->map['provinceA'], '2026-01-03', '2026-01-05');
    $holdsUntil = $oldMandate->holds_until->copy();

    approvedCouncil(mandatePlayer($this->map['cityA2']), $this->map['provinceA'], '2026-03-02');

    expect($oldMandate->fresh()->holds_until->equalTo($holdsUntil))->toBeTrue();
});

test('Q18 — la validation individuelle ne préremplit jamais l\'entrée en fonction avec la date du dirigeant', function () {
    atGameTime('2026-03-05 12:00');
    $first = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-03-02');
    mandateWorkflow()->handover($this->map['provinceA'], '2026-03-04', [], [$first->id]);

    // Un collègue de la même élection, validé après la passation, reste « élu » sans date saisie.
    $late = approvedCouncil(mandatePlayer($this->map['cityA2']), $this->map['provinceA'], '2026-03-02');

    expect($late->in_office_from)->toBeNull()->and($late->effectiveStatus())->toBe('elected');
});

test('fait 1 — un remplaçant en cours de mandat entre à sa prise de siège et expire avec les autres', function () {
    atGameTime('2026-04-10 12:00');
    // Conseil élu le 01/03 ; le remplaçant prend le siège le 10/04 (J+40) en déclarant la date du conseil.
    $replacement = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-03-01', '2026-04-10');

    expect($replacement->in_office_from->equalTo(MandateCalendar::startOfGameDay('2026-04-10')))->toBeTrue()
        ->and($replacement->valid_until->setTimezone('Europe/Paris')->format('Y-m-d'))->toBe('2026-04-30');
});

test('un conseiller réélu passe de son ancien mandat au nouveau à la passation, sans trou ni chevauchement', function () {
    atGameTime('2026-03-04 12:00');
    $character = mandatePlayer($this->map['cityA']);
    $old = approvedCouncil($character, $this->map['provinceA'], '2026-01-03', '2026-01-05');

    // Réélu à l'élection du 04/03 : le renouvellement déclare cette date.
    $renewal = mandateWorkflow()->renew($old, '2026-03-04', 'https://forum.example/reelection');
    mandateWorkflow()->approve($renewal);

    atGameTime('2026-03-05 12:00');
    mandateWorkflow()->handover($this->map['provinceA'], '2026-03-05', [$old->id], [$renewal->id]);
    $leader = MandateCalendar::startOfGameDay('2026-03-05');

    Carbon::setTestNow($leader->copy()->subSecond());
    expect($this->authority->activeCouncilMandate($character)?->id)->toBe($old->id);
    Carbon::setTestNow($leader->copy());
    expect($this->authority->activeCouncilMandate($character)?->id)->toBe($renewal->id);
});
