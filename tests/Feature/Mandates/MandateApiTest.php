<?php

use App\Models\CouncilMandate;
use App\Models\CouncilOfficePeriod;
use App\Models\MayorMandate;
use App\Models\User;
use App\Services\MandateAuthority;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

// API joueur des mandats (lot 1) : demande, renouvellement, annulation, déclaration de poste.

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    atGameTime('2026-05-10 12:00');
    $this->character = mandatePlayer($this->map['cityA']);
    Passport::actingAs($this->character->user);
});

afterEach(fn () => Carbon::setTestNow());

function mayorRequest(array $overrides = []): array
{
    return $overrides + [
        'level' => 'mayor', 'city_id' => test()->map['cityA']->id,
        'started_at' => '2026-05-01', 'announcement_url' => 'https://forum.example/annonce',
    ];
}

test('le référentiel des 10 postes titrés est public et sans libellé', function () {
    Passport::actingAs(User::factory()->create()); // indifférent : la route est publique
    $response = $this->getJson('/api/v1/council-offices')->assertOk();

    expect(collect($response->json('offices'))->pluck('key')->all())->toBe([
        'leader', 'commissaire_commerce', 'commissaire_mines', 'juge', 'prevot_des_marechaux',
        'procureur', 'connetable', 'capitaine', 'porte_parole', 'bailli',
    ]);
});

test('une demande de mairie pour un personnage validé répond 201, en attente', function () {
    $this->postJson("/api/v1/characters/{$this->character->id}/mandates", mayorRequest())
        ->assertCreated()
        ->assertJsonPath('mandate.status', 'pending')
        ->assertJsonPath('mandate.level', 'mayor');
});

test('un personnage non validé ne peut pas demander de poste', function () {
    $this->character->update(['is_validated' => false]);

    $this->postJson("/api/v1/characters/{$this->character->id}/mandates", mayorRequest())
        ->assertStatus(422)->assertJsonValidationErrors('character');
});

test('le personnage d\'un autre compte répond 404, jamais 403', function () {
    $other = mandatePlayer($this->map['cityA2']);

    $this->postJson("/api/v1/characters/{$other->id}/mandates", mayorRequest())->assertNotFound();
});

test('une ville ou une province différente de la résidence est acceptée', function () {
    $this->postJson("/api/v1/characters/{$this->character->id}/mandates", mayorRequest(['city_id' => $this->map['cityB']->id]))
        ->assertCreated();
    $this->postJson("/api/v1/characters/{$this->character->id}/mandates", [
        'level' => 'council', 'province_id' => $this->map['provinceB']->id,
        'started_at' => '2026-05-01', 'announcement_url' => 'https://forum.example/conseil',
    ])->assertCreated();
});

test('une résidence en attente n\'empêche pas la demande', function () {
    $this->character->update(['pending_residence_change' => true]);

    $this->postJson("/api/v1/characters/{$this->character->id}/mandates", mayorRequest())->assertCreated();
});

test('un lieu absent, inexistant, ou du mauvais niveau répond 422', function (array $overrides, string $field) {
    $this->postJson("/api/v1/characters/{$this->character->id}/mandates", mayorRequest($overrides))
        ->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'ville absente' => [['city_id' => null], 'city_id'],
    'ville inexistante' => [['city_id' => 999999], 'city_id'],
    'province sur une mairie' => [['province_id' => 1], 'province_id'],
    'titre sur une mairie' => [['council_office_key' => 'juge'], 'council_office_key'],
    'ville sur le conseil' => [['level' => 'council', 'province_id' => 1], 'city_id'],
]);

test('une date de début future répond 422', function () {
    $this->postJson("/api/v1/characters/{$this->character->id}/mandates", mayorRequest(['started_at' => '2026-05-11']))
        ->assertStatus(422)->assertJsonValidationErrors('started_at');
});

test('un mandat mort-né répond 422 avec son propre message', function () {
    $this->postJson("/api/v1/characters/{$this->character->id}/mandates", mayorRequest(['started_at' => '2026-03-01']))
        ->assertStatus(422)
        ->assertJsonPath('errors.started_at.0', __('mandates.api.dead_born'));
});

test('un conseiller qui s\'inscrit pendant la prolongation est accepté', function () {
    // Élu le 10/03 : fin nominale le 09/05, fin effective le 11/05 ; nous sommes le 10/05.
    $this->postJson("/api/v1/characters/{$this->character->id}/mandates", [
        'level' => 'council', 'province_id' => $this->map['provinceA']->id,
        'started_at' => '2026-03-10', 'announcement_url' => 'https://forum.example/conseil',
    ])->assertCreated();
});

test('le lien d\'annonce doit être en https', function () {
    $this->postJson("/api/v1/characters/{$this->character->id}/mandates", mayorRequest(['announcement_url' => 'http://forum.example/a']))
        ->assertStatus(422)->assertJsonValidationErrors('announcement_url');
});

test('cumul : une seconde demande de mairie est refusée, maire et conseiller ensemble sont acceptés', function () {
    $url = "/api/v1/characters/{$this->character->id}/mandates";
    $this->postJson($url, mayorRequest())->assertCreated();
    $this->postJson($url, mayorRequest(['city_id' => $this->map['cityA2']->id]))->assertStatus(422)->assertJsonValidationErrors('level');
    $this->postJson($url, ['level' => 'council', 'province_id' => $this->map['provinceA']->id,
        'started_at' => '2026-05-01', 'announcement_url' => 'https://forum.example/c'])->assertCreated();
    $this->postJson($url, ['level' => 'council', 'province_id' => $this->map['provinceB']->id,
        'started_at' => '2026-05-01', 'announcement_url' => 'https://forum.example/c'])->assertStatus(422);
});

test('un renouvellement coexiste avec le mandat actif qu\'il renouvelle, sans poste', function () {
    $mandate = approvedCouncil($this->character, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');

    $response = $this->postJson("/api/v1/mandates/council/{$mandate->id}/renew", [
        'started_at' => '2026-05-10', 'announcement_url' => 'https://forum.example/reelection',
    ])->assertCreated();

    $renewal = CouncilMandate::find($response->json('mandate.id'));
    expect($renewal->renews_id)->toBe($mandate->id)
        ->and($renewal->province_id)->toBe($mandate->province_id)
        ->and($renewal->pending_office_id)->toBeNull()
        ->and($renewal->office_requested_at)->toBeNull();
});

test('un renouvellement exige le lien d\'annonce, et un seul à la fois', function () {
    $mandate = approvedMayor($this->character, $this->map['cityA'], '2026-05-01');
    $url = "/api/v1/mandates/mayor/{$mandate->id}/renew";

    $this->postJson($url, ['started_at' => '2026-05-10'])->assertStatus(422)->assertJsonValidationErrors('announcement_url');
    $this->postJson($url, ['started_at' => '2026-05-10', 'announcement_url' => 'https://forum.example/r'])->assertCreated();
    $this->postJson($url, ['started_at' => '2026-05-10', 'announcement_url' => 'https://forum.example/r'])->assertStatus(422);
});

test('un mandat terminé depuis plus de 15 jours ne se renouvelle plus', function () {
    $mandate = approvedMayor($this->character, $this->map['cityA'], '2026-05-01');
    Carbon::setTestNow($mandate->holds_until->copy()->addDays(16));

    $this->postJson("/api/v1/mandates/mayor/{$mandate->id}/renew", [
        'started_at' => Carbon::now('Europe/Paris')->format('Y-m-d'), 'announcement_url' => 'https://forum.example/r',
    ])->assertStatus(422);
});

test('annuler sa propre demande en attente la supprime ; rien d\'autre ne se supprime', function () {
    $pending = mandateWorkflow()->request($this->character, 'mayor', mayorRequest());
    $this->deleteJson("/api/v1/mandates/mayor/{$pending->id}")->assertOk();
    expect(MayorMandate::find($pending->id))->toBeNull();

    $approved = approvedMayor($this->character, $this->map['cityA'], '2026-05-01');
    $this->deleteJson("/api/v1/mandates/mayor/{$approved->id}")->assertStatus(422);
    expect(MayorMandate::find($approved->id))->not->toBeNull();
});

test('la liste des mandats ne montre jamais la note interne', function () {
    $mandate = mandateWorkflow()->request($this->character, 'mayor', mayorRequest());
    mandateWorkflow()->approve($mandate, ['note' => 'NOTE-INTERNE-SECRETE']);

    $response = $this->getJson('/api/v1/mandates')->assertOk()->assertJsonPath('mandates.0.status', 'active');

    expect($response->getContent())->not->toContain('NOTE-INTERNE-SECRETE')
        ->and($response->json('mandates.0'))->not->toHaveKey('note');
});

test('déclarer son poste fait perdre l\'ancien immédiatement, et le nouveau attend la validation', function () {
    $mandate = approvedCouncil($this->character, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    $authority = app(MandateAuthority::class);
    expect($authority->holdsCouncilOffice($this->character, 'juge'))->toBeTrue();

    $this->postJson("/api/v1/mandates/council/{$mandate->id}/office", ['council_office_key' => 'capitaine'])
        ->assertOk()
        ->assertJsonPath('mandate.office_change_pending', true)
        ->assertJsonPath('mandate.pending_office_key', 'capitaine')
        ->assertJsonPath('mandate.office_key', null);

    expect($authority->holdsCouncilOffice($this->character, 'juge'))->toBeFalse()
        ->and($authority->holdsCouncilOffice($this->character, 'capitaine'))->toBeFalse()
        ->and($mandate->officePeriods()->first()->end_reason)->toBe('declare_par_le_joueur');
});

test('un poste ne se déclare pas sur un mandat élu, pas encore en fonction', function () {
    $mandate = approvedCouncil($this->character, $this->map['provinceA'], '2026-05-01');

    $this->postJson("/api/v1/mandates/council/{$mandate->id}/office", ['council_office_key' => 'juge'])
        ->assertStatus(422);
});

test('changer de résidence ne touche ni les mandats ni les demandes', function () {
    $mayor = approvedMayor($this->character, $this->map['cityA'], '2026-05-01');
    $council = mandateWorkflow()->request($this->character, 'council', [
        'province_id' => $this->map['provinceA']->id, 'started_at' => '2026-05-01', 'announcement_url' => 'https://forum.example/c',
    ]);

    $this->patchJson("/api/v1/characters/{$this->character->id}", ['city_id' => $this->map['cityB']->id])->assertOk();

    expect($mayor->fresh()->status)->toBe('approved')
        ->and($mayor->fresh()->city_id)->toBe($this->map['cityA']->id)
        ->and($council->fresh()->status)->toBe('pending')
        ->and($council->fresh()->province_id)->toBe($this->map['provinceA']->id);
});

test('supprimer le compte emporte les mandats et leurs périodes de poste', function () {
    $user = $this->character->user;
    $user->forceFill(['password' => bcrypt('password123')])->save();
    $mandate = approvedCouncil($this->character, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    approvedMayor($this->character, $this->map['cityA'], '2026-05-01');

    $this->deleteJson('/api/v1/auth/account', ['password' => 'password123'])->assertNoContent();

    expect(CouncilMandate::count())->toBe(0)
        ->and(MayorMandate::count())->toBe(0)
        ->and(CouncilOfficePeriod::where('council_mandate_id', $mandate->id)->count())->toBe(0);
});
