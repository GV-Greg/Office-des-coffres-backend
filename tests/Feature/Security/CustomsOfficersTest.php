<?php

use App\Exceptions\MandateRefusal;
use App\Models\Character;
use App\Models\CustomsOfficer;
use App\Models\ProvinceSecuritySettings;
use App\Notifications\CustomsInactivityNotice;
use App\Services\AccountDeletion;
use App\Services\AccountExport;
use App\Services\CustomsOfficers;
use App\Services\MandateAuthority;
use App\Support\MandateHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

/*
| Module Sécurité — douaniers (brief admin/content/brief-module-securite.md §4 ; fil admin/echanges/module-securite, réponses de Greg
| du 21/11/2026). Le douanier DEMANDE (opt-in), le trio valide en cascade (niveau 1 prévôt/connétable, niveau 2 capitaine/comte/maire
| seulement sans prévôt ni connétable connu, niveau 3 administrateur) ; seule la saisie d'un bilan remet le compteur d'inactivité à zéro.
*/

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    $this->province = $this->map['provinceA'];
    $this->authority = app(MandateAuthority::class);
    $this->officers = app(CustomsOfficers::class);

    // Les postes naissent à la validation (period.started_at = « maintenant ») : on valide le 03/05, puis on avance.
    atGameTime('2026-05-03 12:00');
    $this->applicant = mandatePlayer($this->map['cityA'], ['pseudo' => 'Candidat']);
    $this->prevot = mandatePlayer($this->map['cityB'], ['pseudo' => 'Prevot']);
    $this->connetable = mandatePlayer($this->map['cityB'], ['pseudo' => 'Connetable']);
    $this->capitaine = mandatePlayer($this->map['cityB'], ['pseudo' => 'Capitaine']);
    $this->comte = mandatePlayer($this->map['cityB'], ['pseudo' => 'Comte']);
    $this->juge = mandatePlayer($this->map['cityB'], ['pseudo' => 'Juge']);
    $this->seat = function (Character $character, string $office, $province = null) {
        approvedCouncil($character, $province ?? $this->province, '2026-05-01', '2026-05-03', $office);
    };
    atGameTime('2026-05-10 12:00');
});

afterEach(fn () => Carbon::setTestNow());

// ─── Autorité : la cascade de validation ───────────────────────────────────────────────────

test('le prévôt et le connétable valident au niveau 1, dans la province de leur POSTE', function () {
    atGameTime('2026-05-03 12:00');
    ($this->seat)($this->prevot, 'prevot_des_marechaux');
    ($this->seat)($this->connetable, 'connetable');
    atGameTime('2026-05-10 12:00');

    expect($this->authority->customsDeciderLevel($this->prevot, $this->province))->toBe(1)
        ->and($this->authority->customsDeciderLevel($this->connetable, $this->province))->toBe(1)
        ->and($this->authority->customsDeciderLevel($this->prevot, $this->map['provinceB']))->toBeNull()
        ->and($this->authority->customsDeciderScope($this->prevot))->toMatchArray(['level' => 1]);
});

test('le capitaine, le comte et un maire de la province valident au niveau 2 SEULEMENT sans prévôt ni connétable', function () {
    atGameTime('2026-05-03 12:00');
    ($this->seat)($this->capitaine, 'capitaine');
    ($this->seat)($this->comte, 'leader');
    approvedMayor($this->applicant, $this->map['cityA'], '2026-05-01');
    atGameTime('2026-05-10 12:00');

    expect($this->authority->customsDeciderLevel($this->capitaine, $this->province))->toBe(2)
        ->and($this->authority->customsDeciderLevel($this->comte, $this->province))->toBe(2)
        ->and($this->authority->customsDeciderLevel($this->applicant, $this->province))->toBe(2)
        ->and($this->authority->customsDeciderLevel($this->applicant, $this->map['provinceB']))->toBeNull();

    // Un prévôt entre en fonction : le niveau 2 s'éteint.
    atGameTime('2026-05-11 12:00');
    ($this->seat)($this->prevot, 'prevot_des_marechaux');
    atGameTime('2026-05-12 12:00');

    expect($this->authority->customsDeciderLevel($this->capitaine, $this->province))->toBeNull()
        ->and($this->authority->customsDeciderLevel($this->comte, $this->province))->toBeNull()
        ->and($this->authority->customsDeciderLevel($this->applicant, $this->province))->toBeNull();
});

test('un juge, un conseiller sans titre ou un joueur quelconque ne valide rien', function () {
    atGameTime('2026-05-03 12:00');
    ($this->seat)($this->juge, 'juge');
    atGameTime('2026-05-10 12:00');

    expect($this->authority->customsDeciderLevel($this->juge, $this->province))->toBeNull()
        ->and($this->authority->customsDeciderScope($this->juge))->toBeNull()
        ->and($this->authority->customsDeciderLevel($this->applicant, $this->province))->toBeNull();
});

test('le trio de sécurité = prévôt, connétable ou capitaine connu et en fonction', function () {
    expect($this->authority->provinceHasSecurityTrio($this->province))->toBeFalse();

    atGameTime('2026-05-03 12:00');
    ($this->seat)($this->capitaine, 'capitaine');
    atGameTime('2026-05-10 12:00');

    expect($this->authority->provinceHasSecurityTrio($this->province))->toBeTrue()
        ->and($this->authority->hasPrimaryCustomsDecider($this->province))->toBeFalse();
});

// ─── La demande ────────────────────────────────────────────────────────────────────────────

test('un personnage validé demande, la province est celle de la ville de rattachement', function () {
    $officer = $this->officers->request($this->applicant, $this->map['cityA2']);

    expect($officer->status)->toBe(CustomsOfficer::PENDING)
        ->and($officer->active)->toBe(1)
        ->and($officer->province_id)->toBe($this->province->id)
        ->and($officer->city_id)->toBe($this->map['cityA2']->id);
});

test('un personnage non validé ne peut pas demander', function () {
    $unvalidated = mandatePlayer($this->map['cityA'], ['is_validated' => false]);

    expect(fn () => $this->officers->request($unvalidated, $this->map['cityA']))->toThrow(MandateRefusal::class);
});

test('une seule demande ou un seul accès en cours par personnage', function () {
    $this->officers->request($this->applicant, $this->map['cityA']);

    expect(fn () => $this->officers->request($this->applicant, $this->map['cityB']))->toThrow(MandateRefusal::class);
});

// ─── La décision ───────────────────────────────────────────────────────────────────────────

test('le prévôt valide : le douanier est en vigueur, trace du niveau et du décideur', function () {
    atGameTime('2026-05-03 12:00');
    ($this->seat)($this->prevot, 'prevot_des_marechaux');
    atGameTime('2026-05-10 12:00');
    $officer = $this->officers->request($this->applicant, $this->map['cityA']);

    $this->officers->decide($officer, $this->prevot, true);
    $officer->refresh();

    expect($officer->status)->toBe(CustomsOfficer::APPROVED)
        ->and($officer->decision_level)->toBe(1)
        ->and($officer->decided_by_character_id)->toBe($this->prevot->id)
        ->and($officer->in_office_from)->not->toBeNull()
        ->and($this->authority->activeCustomsOfficer($this->applicant)?->id)->toBe($officer->id);
});

test('personne ne valide sa propre demande, et un non-autorisé est refusé', function () {
    atGameTime('2026-05-03 12:00');
    ($this->seat)($this->prevot, 'prevot_des_marechaux');
    atGameTime('2026-05-10 12:00');
    $mine = $this->officers->request($this->prevot, $this->map['cityA']);
    $other = $this->officers->request($this->applicant, $this->map['cityA']);

    expect(fn () => $this->officers->decide($mine, $this->prevot, true))->toThrow(MandateRefusal::class)
        ->and(fn () => $this->officers->decide($other, $this->juge, true))->toThrow(MandateRefusal::class);
});

test('le repli (niveau 2) valide quand aucun prévôt ni connétable n\'est connu', function () {
    atGameTime('2026-05-03 12:00');
    ($this->seat)($this->capitaine, 'capitaine');
    atGameTime('2026-05-10 12:00');
    $officer = $this->officers->request($this->applicant, $this->map['cityA']);

    $this->officers->decide($officer, $this->capitaine, true);

    expect($officer->refresh()->decision_level)->toBe(2);
});

test('un refus libère la place : une nouvelle demande est possible', function () {
    atGameTime('2026-05-03 12:00');
    ($this->seat)($this->prevot, 'prevot_des_marechaux');
    atGameTime('2026-05-10 12:00');
    $officer = $this->officers->request($this->applicant, $this->map['cityA']);

    $this->officers->decide($officer, $this->prevot, false);

    expect($officer->refresh()->status)->toBe(CustomsOfficer::REJECTED)->and($officer->active)->toBeNull()
        ->and($this->officers->request($this->applicant, $this->map['cityA'])->status)->toBe(CustomsOfficer::PENDING);
});

test('l\'administrateur décide au niveau 3, sans personnage décideur', function () {
    $officer = $this->officers->request($this->applicant, $this->map['cityA']);

    $this->officers->decideAsAdmin($officer, true);
    $officer->refresh();

    expect($officer->status)->toBe(CustomsOfficer::APPROVED)
        ->and($officer->decision_level)->toBe(3)
        ->and($officer->decided_by_character_id)->toBeNull();
});

test('une demande déjà traitée ne se traite pas deux fois', function () {
    $officer = $this->officers->request($this->applicant, $this->map['cityA']);
    $this->officers->decideAsAdmin($officer, true);

    expect(fn () => $this->officers->decideAsAdmin($officer, false))->toThrow(MandateRefusal::class);
});

// ─── Retrait et révocation ─────────────────────────────────────────────────────────────────

test('le douanier retire sa demande en attente (la ligne disparaît) ou son accès (trace gardée)', function () {
    $pending = $this->officers->request($this->applicant, $this->map['cityA']);
    $this->officers->withdraw($this->applicant);
    expect(CustomsOfficer::find($pending->id))->toBeNull();

    $officer = $this->officers->request($this->applicant, $this->map['cityA']);
    $this->officers->decideAsAdmin($officer, true);
    $this->officers->withdraw($this->applicant);
    $officer->refresh();

    expect($officer->status)->toBe(CustomsOfficer::REVOKED)
        ->and($officer->revocation_reason)->toBe(CustomsOfficer::REASON_SELF)
        ->and($officer->active)->toBeNull()
        ->and($this->authority->activeCustomsOfficer($this->applicant))->toBeNull();
});

test('retirer sans demande ni accès est refusé', function () {
    expect(fn () => $this->officers->withdraw($this->applicant))->toThrow(MandateRefusal::class);
});

test('révocation par le niveau 1, le niveau 2 ou l\'administrateur : la cause est tracée', function () {
    atGameTime('2026-05-03 12:00');
    ($this->seat)($this->prevot, 'prevot_des_marechaux');
    atGameTime('2026-05-10 12:00');

    $officer = $this->officers->request($this->applicant, $this->map['cityA']);
    $this->officers->decideAsAdmin($officer, true);
    $this->officers->revoke($officer, $this->prevot);
    expect($officer->refresh()->revocation_reason)->toBe(CustomsOfficer::REASON_PRIMARY)
        ->and($officer->revoked_by_character_id)->toBe($this->prevot->id);

    $second = $this->officers->request($this->applicant, $this->map['cityA']);
    $this->officers->decideAsAdmin($second, true);
    $this->officers->revoke($second, null);
    expect($second->refresh()->revocation_reason)->toBe(CustomsOfficer::REASON_ADMIN);

    $third = $this->officers->request($this->applicant, $this->map['cityA']);
    $this->officers->decideAsAdmin($third, true);
    expect(fn () => $this->officers->revoke($third, $this->juge))->toThrow(MandateRefusal::class);
});

// ─── Inactivité ────────────────────────────────────────────────────────────────────────────

function approvedOfficer($test, $city = null): CustomsOfficer
{
    $officer = $test->officers->request($test->applicant, $city ?? $test->map['cityA']);

    return $test->officers->decideAsAdmin($officer, true);
}

test('la durée d\'inactivité vaut 90 jours par défaut, se règle de 30 à 365 par le niveau 1', function () {
    atGameTime('2026-05-03 12:00');
    ($this->seat)($this->prevot, 'prevot_des_marechaux');
    atGameTime('2026-05-10 12:00');

    expect($this->officers->inactivityDays($this->province))->toBe(90);

    $this->officers->setInactivityDays($this->prevot, $this->province, 120);
    expect($this->officers->inactivityDays($this->province))->toBe(120)
        ->and(ProvinceSecuritySettings::first()->updated_by_character_id)->toBe($this->prevot->id);

    expect(fn () => $this->officers->setInactivityDays($this->prevot, $this->province, 29))->toThrow(MandateRefusal::class)
        ->and(fn () => $this->officers->setInactivityDays($this->prevot, $this->province, 366))->toThrow(MandateRefusal::class)
        ->and(fn () => $this->officers->setInactivityDays($this->juge, $this->province, 100))->toThrow(MandateRefusal::class);
});

test('le compteur part de l\'approbation et ne se remet à zéro que sur la saisie d\'un bilan', function () {
    $officer = approvedOfficer($this);
    $start = $officer->refresh()->in_office_from;

    expect($this->officers->lapsesAt($officer)->equalTo($start->copy()->addDays(90)))->toBeTrue();

    atGameTime('2026-06-10 12:00');
    $this->officers->recordReport($officer);

    expect($this->officers->lapsesAt($officer->refresh())->equalTo(Carbon::now()->addDays(90)))->toBeTrue()
        ->and($officer->inactivity_notice_sent_at)->toBeNull();
});

test('avec un trio connu : l\'inactivité est signalée, rien n\'est révoqué ni envoyé', function () {
    atGameTime('2026-05-03 12:00');
    ($this->seat)($this->capitaine, 'capitaine');
    atGameTime('2026-05-10 12:00');
    // Le mandat du capitaine dure 60 jours : on resserre la durée à 30 jours (réglage du niveau 2) pour rester dans son mandat.
    $this->officers->setInactivityDays($this->capitaine, $this->province, 30);
    $officer = approvedOfficer($this);

    atGameTime('2026-06-15 12:00'); // 36 jours après le 10/05, mandat du capitaine encore en cours
    Artisan::call('customs:inactivity');

    expect($officer->refresh()->status)->toBe(CustomsOfficer::APPROVED)
        ->and($this->officers->isInactive($officer))->toBeTrue()
        ->and($this->officers->present($officer)['inactive'])->toBeTrue();
    Notification::assertNotSentTo($this->applicant->user, CustomsInactivityNotice::class);
});

test('sans trio : avis une seule fois à 7 jours, puis révocation automatique à l\'échéance', function () {
    $officer = approvedOfficer($this);
    $lapse = $this->officers->lapsesAt($officer);

    // Avant la fenêtre d'avis : rien.
    Carbon::setTestNow($lapse->copy()->subDays(8));
    Artisan::call('customs:inactivity');
    Notification::assertNotSentTo($this->applicant->user, CustomsInactivityNotice::class);

    // 7 jours avant : un avis, une seule fois.
    Carbon::setTestNow($lapse->copy()->subDays(7));
    Artisan::call('customs:inactivity');
    Artisan::call('customs:inactivity');
    Notification::assertSentToTimes($this->applicant->user, CustomsInactivityNotice::class, 1);
    expect($officer->refresh()->status)->toBe(CustomsOfficer::APPROVED)->and($officer->inactivity_notice_sent_at)->not->toBeNull();

    // À l'échéance : révocation automatique, qui laisse refaire une demande.
    Carbon::setTestNow($lapse->copy()->addMinute());
    Artisan::call('customs:inactivity');
    $officer->refresh();

    expect($officer->status)->toBe(CustomsOfficer::REVOKED)
        ->and($officer->revocation_reason)->toBe(CustomsOfficer::REASON_INACTIVITY)
        ->and($officer->revoked_by_character_id)->toBeNull()
        ->and($this->officers->request($this->applicant, $this->map['cityA'])->status)->toBe(CustomsOfficer::PENDING);
});

test('un bilan saisi après l\'avis annule l\'avis et repousse l\'échéance', function () {
    $officer = approvedOfficer($this);
    Carbon::setTestNow($this->officers->lapsesAt($officer)->copy()->subDays(7));
    Artisan::call('customs:inactivity');

    $this->officers->recordReport($officer);
    Artisan::call('customs:inactivity');

    expect($officer->refresh()->status)->toBe(CustomsOfficer::APPROVED)->and($officer->inactivity_notice_sent_at)->toBeNull();
    Notification::assertSentToTimes($this->applicant->user, CustomsInactivityNotice::class, 1);
});

test('la tâche est planifiée à 09:00 Paris et écrit son passage réussi', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'customs:inactivity'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 9 * * *')->and($event->timezone)->toBe('Europe/Paris');

    Cache::flush();
    Artisan::call('customs:inactivity');

    expect(MandateHeartbeat::lastSuccess('customs'))->not->toBeNull();
});

test('l\'avis d\'inactivité est bilingue et ne contient aucun lien d\'administration', function () {
    $officer = approvedOfficer($this);
    $mail = (new CustomsInactivityNotice($officer, Carbon::now()->addDays(7)))->toMail($this->applicant->user);
    $text = implode("\n", $mail->introLines).' '.$mail->actionUrl.' '.$mail->subject;

    expect($text)->toContain('prendra fin')->toContain('will end')->not->toContain('admin');
});

// ─── API ───────────────────────────────────────────────────────────────────────────────────

test('l\'API exige un jeton : 401 et jamais 404 sur les routes ajoutées', function () {
    $id = $this->applicant->id;

    $this->getJson("/api/v1/characters/{$id}/customs")->assertUnauthorized();
    $this->postJson("/api/v1/characters/{$id}/customs", [])->assertUnauthorized();
    $this->postJson('/api/v1/customs-officers/1/approve')->assertUnauthorized();
});

test('un personnage d\'un autre compte répond 404 bilingue, jamais 403', function () {
    Passport::actingAs($this->prevot->user);

    $response = $this->getJson("/api/v1/characters/{$this->applicant->id}/customs")->assertNotFound();

    expect($response->json('code'))->toBe('character_not_found')
        ->and($response->json('messages'))->toHaveKeys(['fr', 'en']);
});

test('parcours complet par l\'API : demande, validation par le prévôt, révocation', function () {
    atGameTime('2026-05-03 12:00');
    ($this->seat)($this->prevot, 'prevot_des_marechaux');
    atGameTime('2026-05-10 12:00');

    Passport::actingAs($this->applicant->user);
    $created = $this->postJson("/api/v1/characters/{$this->applicant->id}/customs", ['city_id' => $this->map['cityA']->id])
        ->assertCreated();
    $id = $created->json('officer.id');
    expect($created->json('officer.status'))->toBe('pending')->and($created->json('officer.pseudo'))->toBeNull();

    // Le prévôt voit la demande (avec le pseudo du demandeur : il l'a demandée) et la valide.
    Passport::actingAs($this->prevot->user);
    $state = $this->getJson("/api/v1/characters/{$this->prevot->id}/customs")->assertOk();
    expect($state->json('decider.level'))->toBe(1)
        ->and($state->json('decider.pending.0.pseudo'))->toBe('Candidat')
        ->and($state->json('decider.has_security_trio'))->toBeTrue()
        ->and($state->json('decider.inactivity_days'))->toBe(90);

    $this->postJson("/api/v1/customs-officers/{$id}/approve")->assertOk()->assertJsonPath('officer.status', 'approved');

    Passport::actingAs($this->applicant->user);
    $this->getJson("/api/v1/characters/{$this->applicant->id}/customs")->assertJsonPath('officer.status', 'approved')
        ->assertJsonPath('can_request', false);

    Passport::actingAs($this->prevot->user);
    $this->postJson("/api/v1/customs-officers/{$id}/revoke")->assertOk()->assertJsonPath('officer.status', 'revoked');
});

test('un non-décideur reçoit 404 sur une demande, le demandeur ne valide pas la sienne', function () {
    $officer = $this->officers->request($this->applicant, $this->map['cityA']);

    Passport::actingAs($this->juge->user);
    $this->postJson("/api/v1/customs-officers/{$officer->id}/approve")->assertNotFound();

    Passport::actingAs($this->applicant->user);
    $this->postJson("/api/v1/customs-officers/{$officer->id}/approve")->assertNotFound();
    expect($officer->refresh()->status)->toBe(CustomsOfficer::PENDING);
});

test('l\'API règle la durée d\'inactivité et refuse hors bornes, avec les deux langues', function () {
    atGameTime('2026-05-03 12:00');
    ($this->seat)($this->prevot, 'prevot_des_marechaux');
    atGameTime('2026-05-10 12:00');
    Passport::actingAs($this->prevot->user);

    $this->putJson("/api/v1/characters/{$this->prevot->id}/customs/settings", ['inactivity_days' => 45])
        ->assertOk()->assertJsonPath('inactivity_days', 45);

    $refused = $this->putJson("/api/v1/characters/{$this->prevot->id}/customs/settings", ['inactivity_days' => 10])
        ->assertUnprocessable();
    expect($refused->json('codes.inactivity_days'))->toBe('inactivity_range')
        ->and($refused->json('messages.inactivity_days'))->toHaveKeys(['fr', 'en']);

    Passport::actingAs($this->juge->user);
    $this->putJson("/api/v1/characters/{$this->juge->id}/customs/settings", ['inactivity_days' => 45])->assertForbidden();
});

// ─── Export RGPD et suppression ────────────────────────────────────────────────────────────

test('l\'export du joueur porte son statut de douanier, sans nommer le décideur', function () {
    atGameTime('2026-05-03 12:00');
    ($this->seat)($this->prevot, 'prevot_des_marechaux');
    atGameTime('2026-05-10 12:00');
    $officer = $this->officers->request($this->applicant, $this->map['cityA']);
    $this->officers->decide($officer, $this->prevot, true);

    $export = app(AccountExport::class)->build($this->applicant->user);

    expect($export['customs'])->toHaveCount(1)
        ->and($export['customs'][0])->toMatchArray(['character' => 'Candidat', 'status' => 'approved', 'decision_level' => 1])
        ->and(json_encode($export['customs']))->not->toContain('Prevot');
});

test('supprimer le compte du douanier efface sa ligne ; celui du décideur laisse la décision sans auteur', function () {
    atGameTime('2026-05-03 12:00');
    ($this->seat)($this->prevot, 'prevot_des_marechaux');
    atGameTime('2026-05-10 12:00');
    $officer = $this->officers->request($this->applicant, $this->map['cityA']);
    $this->officers->decide($officer, $this->prevot, true);

    app(AccountDeletion::class)->deleteUser($this->prevot->user);
    expect($officer->refresh()->decided_by_character_id)->toBeNull()
        ->and($officer->status)->toBe(CustomsOfficer::APPROVED);

    app(AccountDeletion::class)->deleteUser($this->applicant->user);
    expect(CustomsOfficer::find($officer->id))->toBeNull();
});
