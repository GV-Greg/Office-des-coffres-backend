<?php

use App\Http\Controllers\Web\MandateAdminController;
use App\Models\Character;
use App\Models\City;
use App\Models\Mandate;
use App\Models\MayorMandate;
use App\Models\User;
use App\Notifications\MandateDecision;
use App\Services\AccountDeletion;
use App\Support\MandateCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

// Administration des mandats (Blade, role:admin) et gestes de l'administrateur.

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    $this->admin = mandateAdmin();
    atGameTime('2026-05-10 12:00');
});

afterEach(fn () => Carbon::setTestNow());

function pendingMayor(Character $character, City $city, string $startedAt = '2026-05-01'): MayorMandate
{
    return mandateWorkflow()->request($character, 'mayor', [
        'city_id' => $city->id, 'started_at' => $startedAt, 'announcement_url' => 'https://forum.example/m',
    ]);
}

// ─── Accès et pages ────────────────────────────────────────────────────────────────────────

test('les pages et les gestes des mandats sont réservés au rôle admin', function () {
    $this->get('/mandates')->assertRedirect('/login');

    $player = User::factory()->create();
    $this->actingAs($player)->get('/mandates')->assertForbidden();
    $mandate = pendingMayor(mandatePlayer($this->map['cityA']), $this->map['cityA']);
    $this->actingAs($player)->post("/mandates/mayor/{$mandate->id}/approve")->assertForbidden();
    expect($mandate->fresh()->status)->toBe(Mandate::PENDING);
});

test('les quatre pages s\'affichent avec des mandats de chaque sorte', function () {
    $pending = pendingMayor(mandatePlayer($this->map['cityA']), $this->map['cityA']);
    $council = approvedCouncil(mandatePlayer($this->map['cityA2']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    mandateWorkflow()->declareOffice($council, 'capitaine');
    $revoked = approvedMayor(mandatePlayer($this->map['cityB']), $this->map['cityB'], '2026-05-01');
    mandateWorkflow()->revoke($revoked, 'demission', null, 'Il est parti.', 'fr');

    $this->actingAs($this->admin)->get('/mandates')->assertOk()
        ->assertSee($pending->character->pseudo)->assertSee(__('mandates.admin.office_requests'));
    $this->actingAs($this->admin)->get('/mandates/running')->assertOk()->assertSee('Artois');
    $this->actingAs($this->admin)->get('/mandates/decisions')->assertOk()->assertSee('Il est parti.');
    $this->actingAs($this->admin)->get('/mandates/verification')->assertOk()->assertSee($revoked->character->pseudo);
});

// Garde-fou de mise en page (03/10/2026) : un </div> orphelin, laissé par un remaniement de
// running.blade.php, faisait sortir les gestes de province de la carte — sans aucune erreur, le
// HTML étant « réparé » par le navigateur. Une page des mandats ferme exactement ce qu'elle ouvre.
test('les pages des mandats ferment exactement les balises qu\'elles ouvrent', function () {
    pendingMayor(mandatePlayer($this->map['cityA']), $this->map['cityA']);
    $council = approvedCouncil(mandatePlayer($this->map['cityA2']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03');
    mandateWorkflow()->declareOffice($council, 'capitaine');
    $revoked = approvedMayor(mandatePlayer($this->map['cityB']), $this->map['cityB'], '2026-05-01');
    mandateWorkflow()->revoke($revoked, 'demission', null, 'Il est parti.', 'fr');

    foreach (['/mandates', '/mandates/running', '/mandates/decisions', '/mandates/verification', '/mandates/history', "/mandates/history?province={$this->map['provinceA']->id}"] as $page) {
        $html = $this->actingAs($this->admin)->get($page)->assertOk()->getContent();
        foreach (['div', 'details', 'section', 'form', 'ul', 'dl', 'label'] as $tag) {
            expect(preg_match_all("/<{$tag}[\\s>]/", $html))
                ->toBe(substr_count($html, "</{$tag}>"), "{$page} : <{$tag}> déséquilibré");
        }
    }
});

// ─── Approbation et refus ──────────────────────────────────────────────────────────────────

test('un refus sans motif, ou « autre » sans commentaire, est refusé', function () {
    $mandate = pendingMayor(mandatePlayer($this->map['cityA']), $this->map['cityA']);

    $this->actingAs($this->admin)->post("/mandates/mayor/{$mandate->id}/reject", [])->assertSessionHasErrors('reason');
    $this->actingAs($this->admin)->post("/mandates/mayor/{$mandate->id}/reject", ['reason' => 'autre'])->assertSessionHasErrors('message');
    $this->actingAs($this->admin)->post("/mandates/mayor/{$mandate->id}/reject", ['reason' => 'annonce_non_concluante'])->assertSessionHasNoErrors();

    expect($mandate->fresh()->status)->toBe(Mandate::REJECTED)
        ->and($mandate->fresh()->decision_reason)->toBe('annonce_non_concluante');
});

test('un mandat mort-né bloque l\'approbation', function () {
    $mandate = pendingMayor(mandatePlayer($this->map['cityA']), $this->map['cityA'], '2026-05-01');
    Carbon::setTestNow(MandateCalendar::validUntil('mayor', '2026-05-01')->addMinute());

    $this->actingAs($this->admin)->post("/mandates/mayor/{$mandate->id}/approve")->assertSessionHasErrors('started_at');
    expect($mandate->fresh()->status)->toBe(Mandate::PENDING);
});

test('la date de début corrigée à la validation est tracée, et la déclaration du joueur reste intacte', function () {
    $mandate = pendingMayor(mandatePlayer($this->map['cityA']), $this->map['cityA'], '2026-05-01');

    $this->actingAs($this->admin)->post("/mandates/mayor/{$mandate->id}/approve", ['started_at' => '2026-05-03'])->assertSessionHasNoErrors();

    $mandate->refresh();
    expect($mandate->declared_started_at->format('Y-m-d'))->toBe('2026-05-01')
        ->and($mandate->started_at->format('Y-m-d'))->toBe('2026-05-03')
        ->and($mandate->note)->toContain('01/05/2026 → 03/05/2026');
});

test('Q2 — remplacer le maire le clôt à l\'entrée en fonction du successeur, pas à l\'heure de l\'approbation', function () {
    $holder = approvedMayor(mandatePlayer($this->map['cityA']), $this->map['cityA'], '2026-05-01');
    $successor = pendingMayor(mandatePlayer($this->map['cityA2']), $this->map['cityA'], '2026-05-08');

    $this->actingAs($this->admin)->post("/mandates/mayor/{$successor->id}/approve", ['replace_holder' => '1'])->assertSessionHasNoErrors();

    $holder->refresh();
    expect($holder->status)->toBe(Mandate::REVOKED)
        ->and($holder->decision_reason)->toBe('remplace')
        ->and($holder->holds_until->equalTo($successor->fresh()->in_office_from))->toBeTrue()
        ->and(MayorMandate::query()->inOfficeNow()->where('city_id', $this->map['cityA']->id)->count())->toBe(1);
});

test('Q2 — un successeur déclaré avant l\'entrée en fonction du maire en place est refusé, avec son nom', function () {
    $holder = approvedMayor(mandatePlayer($this->map['cityA'], ['pseudo' => 'Gaspard']), $this->map['cityA'], '2026-05-05');
    $successor = pendingMayor(mandatePlayer($this->map['cityA2']), $this->map['cityA'], '2026-05-02');

    $this->actingAs($this->admin)->post("/mandates/mayor/{$successor->id}/approve", ['replace_holder' => '1'])
        ->assertSessionHasErrors(['replace_holder' => __('mandates.admin.errors.replace_before_holder', ['pseudo' => 'Gaspard'])]);
    expect($holder->fresh()->status)->toBe(Mandate::APPROVED)->and($successor->fresh()->status)->toBe(Mandate::PENDING);
});

test('la validation groupée refuse une première demande, et valide les renouvellements en vérifiant le mandat renouvelé', function () {
    $first = pendingMayor(mandatePlayer($this->map['cityA']), $this->map['cityA']);
    $this->actingAs($this->admin)->post('/mandates/batch-approve', ['items' => ["mayor:{$first->id}"]])->assertSessionHasErrors('mandates');
    expect($first->fresh()->status)->toBe(Mandate::PENDING);

    $mandate = approvedMayor(mandatePlayer($this->map['cityA2']), $this->map['cityA2'], '2026-05-01');
    $renewal = mandateWorkflow()->renew($mandate, '2026-05-10', 'https://forum.example/r');
    $this->actingAs($this->admin)->post('/mandates/batch-approve', ['items' => ["mayor:{$renewal->id}"]])->assertSessionHasNoErrors();

    expect($renewal->fresh()->status)->toBe(Mandate::APPROVED)
        ->and($mandate->fresh()->verified_at)->not->toBeNull();
});

test('une validation envoie un email ; un refus aussi, sans jamais la note interne', function () {
    $player = mandatePlayer($this->map['cityA']);
    $mandate = pendingMayor($player, $this->map['cityA']);
    mandateWorkflow()->reject($mandate, 'date_incoherente', null, null, 'NOTE-INTERNE');

    Notification::assertSentToTimes($player->user, MandateDecision::class, 1);
    Notification::assertSentTo($player->user, MandateDecision::class, function (MandateDecision $notification) {
        return $notification->type === 'rejected'
            && ! str_contains(implode("\n", $notification->lines()), 'NOTE-INTERNE');
    });
});

// ─── Révocation et son annulation ──────────────────────────────────────────────────────────

test('révoquer sans successeur, avec une date d\'effet saisie : fin effective à cette date, email au titulaire', function () {
    $player = mandatePlayer($this->map['cityA']);
    $mandate = approvedMayor($player, $this->map['cityA'], '2026-05-01');

    $this->actingAs($this->admin)->post("/mandates/mayor/{$mandate->id}/revoke", [
        'reason' => 'demission', 'effective_at' => '2026-05-08',
    ])->assertSessionHasNoErrors();

    $mandate->refresh();
    expect($mandate->status)->toBe(Mandate::REVOKED)
        ->and($mandate->revoked_at)->not->toBeNull()
        ->and($mandate->holds_until->equalTo(MandateCalendar::startOfGameDay('2026-05-08')))->toBeTrue()
        ->and($mandate->holds_until_set_by)->toBe('revocation');
    Notification::assertSentTo($player->user, MandateDecision::class, fn ($n) => $n->type === 'revoked');
});

test('une date d\'effet future, ou antérieure à l\'entrée en fonction, est refusée', function () {
    $mandate = approvedMayor(mandatePlayer($this->map['cityA']), $this->map['cityA'], '2026-05-05');

    expect(fn () => mandateWorkflow()->revoke($mandate, 'demission', '2026-05-11', null, null))->toThrow(ValidationException::class)
        ->and(fn () => mandateWorkflow()->revoke($mandate, 'demission', '2026-05-04', null, null))->toThrow(ValidationException::class);
});

test('Q23 — annuler une révocation ne rouvre jamais la période de poste, même si le poste est libre', function () {
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    atGameTime('2026-05-11 12:00');
    mandateWorkflow()->revoke($mandate, 'revolte', null, null, null);
    $closedAt = $mandate->officePeriods()->first()->ended_at->copy();

    mandateWorkflow()->unrevoke($mandate->fresh());

    $mandate->refresh();
    expect($mandate->status)->toBe(Mandate::APPROVED)
        ->and($mandate->isInOffice())->toBeTrue()
        ->and($mandate->holds_until_set_by)->toBe('nominal')
        ->and($mandate->decision_reason)->toBeNull()
        ->and($mandate->officePeriods()->count())->toBe(1)
        ->and($mandate->officePeriods()->first()->ended_at->equalTo($closedAt))->toBeTrue()
        ->and($mandate->currentOfficeKey())->toBeNull();
});

test('Q24 — annuler la révocation d\'un maire est refusé si la ville a un maire actif, et le message le nomme', function () {
    $old = approvedMayor(mandatePlayer($this->map['cityA']), $this->map['cityA'], '2026-05-01');
    mandateWorkflow()->revoke($old, 'revolte', '2026-05-05', null, null);
    approvedMayor(mandatePlayer($this->map['cityA2'], ['pseudo' => 'Successeur']), $this->map['cityA'], '2026-05-05');

    expect(fn () => mandateWorkflow()->unrevoke($old->fresh()))->toThrow(ValidationException::class, 'Successeur');
});

test('Q24 — annuler la révocation d\'un conseiller est refusé s\'il a un autre mandat de conseil en cours', function () {
    $character = mandatePlayer($this->map['cityA']);
    $old = approvedCouncil($character, $this->map['provinceA'], '2026-05-01', '2026-05-03');
    mandateWorkflow()->revoke($old, 'demission', null, null, null);
    approvedCouncil($character, $this->map['provinceB'], '2026-05-09');

    expect(fn () => mandateWorkflow()->unrevoke($old->fresh()))->toThrow(ValidationException::class);
});

test('Q27 — une révocation ne s\'annule que pendant la période nominale du mandat', function () {
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-03-15', '2026-03-17');
    mandateWorkflow()->revoke($mandate, 'demission', null, null, null);
    $after = approvedCouncil(mandatePlayer($this->map['cityA2']), $this->map['provinceA'], '2026-03-15', '2026-03-17');

    // Contrôle positif : avant la fin nominale (14/05), l'annulation passe.
    mandateWorkflow()->unrevoke($mandate->fresh());
    expect($mandate->fresh()->status)->toBe(Mandate::APPROVED);

    // Pendant la prolongation (après le 14/05), elle est refusée.
    mandateWorkflow()->revoke($after, 'demission', null, null, null);
    Carbon::setTestNow($after->fresh()->valid_until->copy()->addHour());
    expect(fn () => mandateWorkflow()->unrevoke($after->fresh()))->toThrow(ValidationException::class);
});

// ─── Corrections de dates (Q25, Q28) ───────────────────────────────────────────────────────

test('Q25 — corriger le début d\'un mandat à fin nominale déplace la fin effective et la période, jamais la déclaration', function () {
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');

    expect(mandateWorkflow()->correctStart($mandate, '2026-04-28'))->toBeTrue();

    $mandate->refresh();
    $expected = MandateCalendar::nominalHoldsUntil('council', MandateCalendar::validUntil('council', '2026-04-28'));
    expect($mandate->declared_started_at->format('Y-m-d'))->toBe('2026-05-01')
        ->and($mandate->holds_until->equalTo($expected))->toBeTrue()
        ->and($mandate->officePeriods()->first()->ended_at->equalTo($expected))->toBeTrue()
        ->and($mandate->note)->toContain('01/05/2026 → 28/04/2026');
});

test('Q25 — sur une fin fixée par une passation, seule la fin nominale bouge, et l\'écran le dit', function () {
    atGameTime('2026-03-01 12:00');
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-01-03', '2026-01-05');
    atGameTime('2026-03-05 12:00');
    mandateWorkflow()->handover($this->map['provinceA'], '2026-03-05', [$mandate->id], []);
    $holds = $mandate->fresh()->holds_until->copy();

    $this->actingAs($this->admin)->post("/mandates/council/{$mandate->id}/correct-start", ['started_at' => '2026-01-04'])
        ->assertSessionHas('status', __('mandates.admin.done.start_corrected_end_kept'));

    $mandate->refresh();
    expect($mandate->holds_until->equalTo($holds))->toBeTrue()
        ->and($mandate->valid_until->equalTo(MandateCalendar::validUntil('council', '2026-01-04')))->toBeTrue();
});

test('Q28 — corriger l\'entrée en fonction après le début d\'une période est refusé et nomme le poste', function () {
    atGameTime('2026-05-09 12:00'); // poste attribué le 09/05 à 12:00
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    atGameTime('2026-05-10 12:00');

    $this->actingAs($this->admin)->post("/mandates/council/{$mandate->id}/in-office", ['in_office_from' => '2026-05-10'])
        ->assertSessionHasErrors('in_office_from');
    expect(session('errors')->first('in_office_from'))->toContain('Juge');
});

test('Q28 — corriger l\'entrée en fonction vers une date antérieure passe, sans déplacer aucune période', function () {
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-05', 'juge');
    $start = $mandate->officePeriods()->first()->started_at->copy();

    mandateWorkflow()->correctInOffice($mandate, '2026-05-02');

    expect($mandate->fresh()->in_office_from->equalTo(MandateCalendar::startOfGameDay('2026-05-02')))->toBeTrue()
        ->and($mandate->officePeriods()->first()->started_at->equalTo($start))->toBeTrue();
});

// ─── Correction de motif (tours 15, 17) ────────────────────────────────────────────────────

test('corriger le motif d\'un refus et d\'une révocation ne touche ni statut, ni date, ni période ; l\'email dit l\'ancien et le nouveau', function () {
    $refusedPlayer = mandatePlayer($this->map['cityA']);
    $refused = pendingMayor($refusedPlayer, $this->map['cityA']);
    mandateWorkflow()->reject($refused, 'date_incoherente', null, null);
    $processedAt = $refused->fresh()->processed_at->copy();

    $revoked = approvedCouncil(mandatePlayer($this->map['cityA2']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    mandateWorkflow()->revoke($revoked, 'retranchement', null, null, null);
    $snapshot = $revoked->fresh()->only(['status', 'holds_until', 'valid_until', 'revoked_at']);
    $period = $revoked->officePeriods()->first()->only(['ended_at', 'end_reason']);
    Notification::fake();

    $this->actingAs($this->admin)->post("/mandates/mayor/{$refused->id}/correct-decision", [
        'reason' => 'annonce_non_concluante', 'status' => 'approved',
    ])->assertSessionHasNoErrors();
    mandateWorkflow()->correctDecision($revoked->fresh(), 'demission', null, null);

    expect($refused->fresh()->status)->toBe(Mandate::REJECTED)
        ->and($refused->fresh()->processed_at->equalTo($processedAt))->toBeTrue()
        ->and($revoked->fresh()->only(['status', 'holds_until', 'valid_until', 'revoked_at']))->toEqual($snapshot)
        ->and($revoked->officePeriods()->first()->only(['ended_at', 'end_reason']))->toEqual($period);

    Notification::assertSentTo($refusedPlayer->user, MandateDecision::class, function (MandateDecision $notification) {
        $text = implode("\n", $notification->lines());

        return $notification->type === 'reason_corrected'
            && str_contains($text, __('mandates.reasons.date_incoherente', [], 'fr'))
            && str_contains($text, __('mandates.reasons.annonce_non_concluante', [], 'fr'))
            && str_contains($text, __('mandates.email.reason_corrected_reapply', [], 'en'));
    });
});

test('un refus ne peut pas devenir une acceptation : la validation d\'une demande refusée est refusée', function () {
    $refused = pendingMayor(mandatePlayer($this->map['cityA']), $this->map['cityA']);
    mandateWorkflow()->reject($refused, 'date_incoherente', null, null);

    $this->actingAs($this->admin)->post("/mandates/mayor/{$refused->id}/approve")->assertSessionHasErrors('mandate');
    expect($refused->fresh()->status)->toBe(Mandate::REJECTED);
});

// ─── File de vérification (tour 05, ajout 2) ───────────────────────────────────────────────

test('la file contient le maire terminé et le conseiller terminé avec un poste, pas le conseiller sans poste', function () {
    $mayor = approvedMayor(mandatePlayer($this->map['cityA']), $this->map['cityA'], '2026-05-01');
    $titled = approvedCouncil(mandatePlayer($this->map['cityA2']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    $plain = approvedCouncil(mandatePlayer($this->map['cityB']), $this->map['provinceB'], '2026-05-01', '2026-05-03');

    Carbon::setTestNow($titled->holds_until->copy()->addDays(30));
    $queue = app(MandateAdminController::class)->verificationQueue()
        ->map(fn ($item) => $item['mandate']::LEVEL.':'.$item['mandate']->id)->all();

    expect($queue)->toContain("mayor:{$mayor->id}")
        ->toContain("council:{$titled->id}")
        ->not->toContain("council:{$plain->id}");
});

test('« Vérifié » retire le mandat de la file, et le seuil marque le retard', function () {
    $mayor = approvedMayor(mandatePlayer($this->map['cityA']), $this->map['cityA'], '2026-05-01');
    Carbon::setTestNow($mayor->holds_until->copy()->addDays(5));

    $queue = app(MandateAdminController::class)->verificationQueue();
    expect($queue->first()['overdue'])->toBeTrue(); // 5 jours ≥ 4 pour un maire

    $this->actingAs($this->admin)->post("/mandates/mayor/{$mayor->id}/verify")->assertSessionHasNoErrors();
    expect(app(MandateAdminController::class)->verificationQueue())->toBeEmpty();
});

// ─── Historique des postes (fil mandats-historique) ─────────────────────────────────────────

test('l\'historique est réservé à l\'admin', function () {
    $this->actingAs(mandatePlayer($this->map['cityA'])->user)->get('/mandates/history')->assertForbidden();
});

test('l\'historique montre le conseil par titre, la cause de chaque fin, et les villes sans mandat', function () {
    $first = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    $second = approvedCouncil(mandatePlayer($this->map['cityA2']), $this->map['provinceA'], '2026-05-01', '2026-05-03');
    mandateWorkflow()->setOffice($second, 'juge');   // réattribution : Juge passe au second
    $mayor = approvedMayor(mandatePlayer($this->map['cityA']), $this->map['cityA'], '2026-05-01');
    mandateWorkflow()->revoke($mayor, 'retranchement', null, null, 'fr');

    $page = $this->actingAs($this->admin)->get("/mandates/history?province={$this->map['provinceA']->id}")->assertOk();

    $page->assertSee(__('mandates.offices.juge'))
        ->assertSee($first->character->pseudo)->assertSee($second->character->pseudo)
        ->assertSee(__('mandates.reasons.reattribue'))
        ->assertSee(__('mandates.reasons.retranchement'))
        ->assertSee(__('mandates.admin.history_ongoing'))
        ->assertSee(__('mandates.admin.absence_warning'))
        // Béthune n'a aucun maire déclaré : comptée, jamais masquée (Q3).
        ->assertSee(trans_choice('mandates.admin.history_cities_without', 1))->assertSee('Béthune');
});

test('un compte supprimé reste dans l\'historique, marqué comme tel', function () {
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    $pseudo = $mandate->character->pseudo;

    app(AccountDeletion::class)->deleteUser($mandate->character->user);

    $this->actingAs($this->admin)->get("/mandates/history?province={$this->map['provinceA']->id}")
        ->assertOk()->assertSee($pseudo)->assertSee(__('mandates.admin.history_archived'));
});
