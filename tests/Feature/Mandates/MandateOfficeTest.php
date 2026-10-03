<?php

use App\Models\CouncilOffice;
use App\Models\CouncilOfficePeriod;
use App\Models\User;
use App\Notifications\MandateDecision;
use App\Services\MandateAuthority;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

// Postes d'un conseil comtal : historique council_office_periods (Q17), exclusivité, transfert,
// vidage à l'élection du dirigeant (fait 2), synchronisation avec holds_until (Q19).

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    $this->authority = app(MandateAuthority::class);
    atGameTime('2026-05-10 12:00');
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Exclusivité (tour 11) : deux périodes d'un même poste dans une même province n'ont jamais de
 * fenêtres [started_at, ended_at) qui se chevauchent. MySQL ne sait pas l'exprimer : ce contrôle
 * est le SEUL garde-fou. Renvoie les paires en conflit.
 */
function officeOverlaps(): array
{
    $periods = CouncilOfficePeriod::query()
        ->join('council_mandates', 'council_mandates.id', '=', 'council_office_periods.council_mandate_id')
        ->get(['council_office_periods.*', 'council_mandates.province_id']);

    $conflicts = [];
    foreach ($periods as $a) {
        foreach ($periods as $b) {
            if ($a->id >= $b->id || $a->province_id !== $b->province_id || $a->council_office_id !== $b->council_office_id) {
                continue;
            }
            if ($a->started_at->lessThan($b->ended_at) && $b->started_at->lessThan($a->ended_at)) {
                $conflicts[] = [$a->id, $b->id];
            }
        }
    }

    return $conflicts;
}

function sentTypes(): array
{
    return collect(Notification::sent(User::all(), MandateDecision::class) ?? [])->all();
}

test('valider B comme capitaine laisse A sans poste, à la même seconde, avec la trace sur la ligne de A', function () {
    $a = mandatePlayer($this->map['cityA']);
    $mandateA = approvedCouncil($a, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'capitaine');
    $b = mandatePlayer($this->map['cityA2']);
    $mandateB = approvedCouncil($b, $this->map['provinceA'], '2026-05-01', '2026-05-03');

    mandateWorkflow()->declareOffice($mandateB, 'capitaine');
    atGameTime('2026-05-10 15:00');
    mandateWorkflow()->approveOfficeRequest($mandateB->fresh());

    $periodA = $mandateA->officePeriods()->first();
    $periodB = $mandateB->officePeriods()->first();
    expect($this->authority->holdsCouncilOffice($a, 'capitaine'))->toBeFalse()
        ->and($this->authority->holdsCouncilOffice($b, 'capitaine'))->toBeTrue()
        ->and($periodA->end_reason)->toBe('reattribue')
        ->and($periodA->ended_at->equalTo($periodB->started_at))->toBeTrue()
        ->and($mandateA->fresh()->note)->toContain('réattribué')
        ->and(officeOverlaps())->toBe([]);
});

test('exclusivité : aucun chevauchement après une suite de transferts, retraits et vidages', function () {
    $members = collect(range(1, 3))->map(fn ($i) => approvedCouncil(
        mandatePlayer($this->map[$i === 1 ? 'cityA' : 'cityA2']), $this->map['provinceA'], '2026-05-01', '2026-05-03'));

    mandateWorkflow()->setOffice($members[0], 'juge');
    atGameTime('2026-05-10 13:00');
    mandateWorkflow()->setOffice($members[1], 'juge');
    atGameTime('2026-05-10 14:00');
    mandateWorkflow()->setOffice($members[2], 'juge');
    mandateWorkflow()->setOffice($members[0]->fresh(), 'capitaine');
    atGameTime('2026-05-10 15:00');
    mandateWorkflow()->setOffice($members[0]->fresh(), null, 'retire_par_le_dirigeant');
    mandateWorkflow()->clearOffices($this->map['provinceA'], '2026-05-10');

    expect(officeOverlaps())->toBe([]);
});

test('contrôle positif de l\'exclusivité : deux périodes forcées en base sur le même poste sont détectées', function () {
    $one = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03');
    $two = approvedCouncil(mandatePlayer($this->map['cityA2']), $this->map['provinceA'], '2026-05-01', '2026-05-03');
    $judge = CouncilOffice::where('key', 'juge')->value('id');
    foreach ([$one, $two] as $mandate) {
        DB::table('council_office_periods')->insert([
            'council_mandate_id' => $mandate->id, 'council_office_id' => $judge,
            'started_at' => Carbon::now()->subHour(), 'ended_at' => $mandate->holds_until,
            'end_reason' => 'fin_du_mandat', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(officeOverlaps())->toHaveCount(1);
});

test('un transfert envoie un seul email, au gagnant ; un retrait sans successeur, un seul, au perdant', function () {
    $a = mandatePlayer($this->map['cityA']);
    $mandateA = approvedCouncil($a, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    $b = mandatePlayer($this->map['cityA2']);
    $mandateB = approvedCouncil($b, $this->map['provinceA'], '2026-05-01', '2026-05-03');
    Notification::fake();

    mandateWorkflow()->setOffice($mandateB, 'juge');
    Notification::assertSentTo($b->user, MandateDecision::class, fn ($n) => $n->type === 'office_granted');
    Notification::assertNotSentTo($a->user, MandateDecision::class);

    Notification::fake();
    mandateWorkflow()->setOffice($mandateB->fresh(), null, 'declaration_erronee');
    Notification::assertSentToTimes($b->user, MandateDecision::class, 1);
    Notification::assertSentTo($b->user, MandateDecision::class, fn ($n) => $n->type === 'office_removed'
        && $n->details['reason'] === 'declaration_erronee');
    expect($mandateB->officePeriods()->latest('id')->first()->end_reason)->not->toBe('reattribue');
});

test('le retrait sans successeur refuse le motif « reattribue », réservé au transfert', function () {
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');

    expect(fn () => mandateWorkflow()->setOffice($mandate, null, 'reattribue'))->toThrow(ValidationException::class);
});

test('un transfert ne touche ni le début, ni la fin nominale, ni la fin effective du mandat', function () {
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03');
    $before = $mandate->only(['started_at', 'valid_until', 'holds_until', 'status']);

    mandateWorkflow()->setOffice($mandate, 'capitaine');

    expect($mandate->fresh()->only(['started_at', 'valid_until', 'holds_until', 'status']))->toEqual($before);
});

// ─── Q15 : titre d'une première inscription ───────────────────────────────────────────────

test('Q15 — le titre d\'un mandat élu est ignoré, sans transfert, et ne subsiste nulle part', function () {
    $captain = mandatePlayer($this->map['cityA']);
    approvedCouncil($captain, $this->map['provinceA'], '2026-03-15', '2026-03-17', 'capitaine'); // sortant
    $entrant = approvedCouncil(mandatePlayer($this->map['cityA2']), $this->map['provinceA'], '2026-05-09', null, 'capitaine');

    expect($this->authority->holdsCouncilOffice($captain, 'capitaine'))->toBeTrue()
        ->and($entrant->officePeriods()->count())->toBe(0)
        ->and($entrant->pending_office_id)->toBeNull()
        ->and($entrant->office_requested_at)->toBeNull();
    Notification::assertSentTo($entrant->character->user, MandateDecision::class,
        fn ($n) => $n->type === 'approved' && $n->details['title_dropped'] === true);
});

test('Q15 contrôle positif — le même titre sur un mandat EN FONCTION à la validation est gardé et transféré', function () {
    $captain = mandatePlayer($this->map['cityA']);
    approvedCouncil($captain, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'capitaine');
    $joiner = mandatePlayer($this->map['cityA2']);
    approvedCouncil($joiner, $this->map['provinceA'], '2026-05-01', '2026-05-10', 'capitaine');

    expect($this->authority->holdsCouncilOffice($captain, 'capitaine'))->toBeFalse()
        ->and($this->authority->holdsCouncilOffice($joiner, 'capitaine'))->toBeTrue();
});

test('aucun geste de l\'admin ne pose un poste sur un mandat élu', function () {
    $entrant = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-09');

    expect(fn () => mandateWorkflow()->setOffice($entrant, 'juge'))->toThrow(ValidationException::class);
});

// ─── Q19 : la période suit holds_until ────────────────────────────────────────────────────

test('Q19 — une période naît fermée à la fin du mandat, avec fin_du_mandat', function () {
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    $period = $mandate->officePeriods()->first();

    expect($period->ended_at->equalTo($mandate->holds_until))->toBeTrue()
        ->and($period->end_reason)->toBe('fin_du_mandat');
});

test('Q19 — prolongation, passation et révocation déplacent holds_until ET la fin de la période en cours', function (string $gesture) {
    atGameTime('2026-03-01 12:00');
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-01-03', '2026-01-05', 'juge');
    atGameTime('2026-03-05 12:00');

    match ($gesture) {
        'extension' => mandateWorkflow()->extendProvince($this->map['provinceA'], 'vote en cours'),
        'handover' => mandateWorkflow()->handover($this->map['provinceA'], '2026-03-05', [$mandate->id], []),
        'revocation' => mandateWorkflow()->revoke($mandate, 'revolte', '2026-03-05', null, null),
    };

    $mandate->refresh();
    $period = $mandate->officePeriods()->first();
    expect($mandate->holds_until_set_by)->toBe($gesture)
        ->and($period->ended_at->equalTo($mandate->holds_until))->toBeTrue()
        ->and($period->end_reason)->toBe('fin_du_mandat');
})->with(['extension', 'handover', 'revocation']);

test('Q19 — une période terminée par un événement de poste ne suit plus holds_until', function () {
    atGameTime('2026-03-05 12:00');
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-01-03', '2026-01-05', 'juge');
    mandateWorkflow()->setOffice($mandate, null, 'retire_par_le_dirigeant');
    $closedAt = $mandate->officePeriods()->first()->ended_at->copy();

    mandateWorkflow()->extendProvince($this->map['provinceA'], 'vote en cours');

    expect($mandate->officePeriods()->first()->ended_at->equalTo($closedAt))->toBeTrue();
});

// ─── Vidage à l'élection d'un dirigeant (fait 2, Q22, Q26) ────────────────────────────────

test('un nouveau dirigeant élu vide toutes les périodes de la province, sans toucher aucun mandat, un email par titulaire', function () {
    $judge = mandatePlayer($this->map['cityA']);
    $mandateJudge = approvedCouncil($judge, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    $captain = mandatePlayer($this->map['cityA2']);
    approvedCouncil($captain, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'capitaine');
    $before = $mandateJudge->fresh()->only(['started_at', 'valid_until', 'holds_until', 'status', 'in_office_from']);
    Notification::fake();

    // Postes attribués le 10/05 à 12:00 ; le dirigeant est élu le 11/05.
    atGameTime('2026-05-11 12:00');
    $count = mandateWorkflow()->clearOffices($this->map['provinceA'], '2026-05-11');

    expect($count)->toBe(2)
        ->and($this->authority->holdsCouncilOffice($judge, 'juge'))->toBeFalse()
        ->and($this->authority->activeCouncilMandate($judge))->not->toBeNull()
        ->and($mandateJudge->fresh()->only(['started_at', 'valid_until', 'holds_until', 'status', 'in_office_from']))->toEqual($before)
        ->and($mandateJudge->officePeriods()->first()->end_reason)->toBe('retire_par_le_dirigeant');
    Notification::assertSentToTimes($judge->user, MandateDecision::class, 1);
    Notification::assertSentToTimes($captain->user, MandateDecision::class, 1);
});

test('une province sans aucun poste déclaré ne produit aucun email de vidage', function () {
    approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03');
    Notification::fake();

    expect(mandateWorkflow()->clearOffices($this->map['provinceA'], '2026-05-10'))->toBe(0);
    Notification::assertNothingSent();
});

test('une passation n\'envoie aucun email de vidage aux sortants, et ferme leurs périodes avec fin_du_mandat', function () {
    atGameTime('2026-03-01 12:00');
    $old = mandatePlayer($this->map['cityA']);
    $oldMandate = approvedCouncil($old, $this->map['provinceA'], '2026-01-03', '2026-01-05', 'juge');
    atGameTime('2026-03-05 12:00');
    $newMandate = approvedCouncil(mandatePlayer($this->map['cityA2']), $this->map['provinceA'], '2026-03-02');
    Notification::fake();

    mandateWorkflow()->handover($this->map['provinceA'], '2026-03-05', [$oldMandate->id], [$newMandate->id]);

    Notification::assertSentToTimes($old->user, MandateDecision::class, 1);
    Notification::assertSentTo($old->user, MandateDecision::class, fn ($n) => $n->type === 'handover_out');
    expect($oldMandate->officePeriods()->first()->end_reason)->toBe('fin_du_mandat')
        ->and($newMandate->fresh()->officePeriods()->count())->toBe(0);
});

test('la passation vide le poste d\'un mandat qui continue, et le lui signale', function () {
    atGameTime('2026-03-01 12:00');
    // Un conseiller du même conseil qui ne sort pas (décoché) : son poste tombe quand même.
    $stays = mandatePlayer($this->map['cityA']);
    $staying = approvedCouncil($stays, $this->map['provinceA'], '2026-01-03', '2026-01-05', 'capitaine');
    atGameTime('2026-03-05 12:00');
    $newMandate = approvedCouncil(mandatePlayer($this->map['cityA2']), $this->map['provinceA'], '2026-03-02');
    Notification::fake();

    mandateWorkflow()->handover($this->map['provinceA'], '2026-03-05', [], [$newMandate->id]);

    expect($staying->officePeriods()->first()->end_reason)->toBe('retire_par_le_dirigeant');
    Notification::assertSentTo($stays->user, MandateDecision::class, fn ($n) => $n->type === 'office_removed');
});

test('un vidage ne ferme pas une nomination postérieure à l\'élection du dirigeant', function () {
    // Dirigeant élu le 10/05 ; Greg n'enregistre le vidage que le 11/05, après une nomination du 10/05 à 12:00.
    $judge = mandatePlayer($this->map['cityA']);
    approvedCouncil($judge, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    atGameTime('2026-05-11 09:00');

    expect(mandateWorkflow()->clearOffices($this->map['provinceA'], '2026-05-10'))->toBe(0)
        ->and($this->authority->holdsCouncilOffice($judge, 'juge'))->toBeTrue();
});

test('une révocation antidatée avant le début d\'un poste est refusée et nomme le poste', function () {
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');

    expect(fn () => mandateWorkflow()->revoke($mandate, 'revolte', '2026-05-05', null, null))
        ->toThrow(ValidationException::class, 'Juge');
});
