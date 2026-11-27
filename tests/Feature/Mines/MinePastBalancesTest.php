<?php

use App\Models\MineReport;
use App\Services\MandateAuthority;
use App\Services\MineRegistryReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

/*
| Registre des mines — bilans de mandat PASSÉS (22/11/2026). Un ancien titulaire relit, en LECTURE SEULE, les relevés de la fenêtre de SON mandat — du début de la
| fenêtre « prédécesseur » (in_office_from − 30 j) à la fin du mandat (holds_until exclu) — jamais le travail de ses successeurs. Mandats `approved`
| seulement (jamais `revoked`). Un mandat EN COURS garde le comportement d'avant : aucune borne haute.
*/

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    $this->province = $this->map['provinceA'];
    // Un mandat du 01/03 : 60 jours (terme fin avril) puis 30 jours d'attente au plus, donc TERMINÉ à la date du jeu ci-dessous.
    atGameTime('2026-03-05 12:00');
    $this->holder = mandatePlayer($this->map['cityB'], ['pseudo' => 'Ancien']);
    $this->mandate = approvedCouncil($this->holder, $this->province, '2026-02-27', '2026-03-01', 'commissaire_mines');
    atGameTime('2026-06-10 12:00');
    $this->mandate->refresh();
});

afterEach(fn () => Carbon::setTestNow());

function pastReport(array $overrides = []): MineReport
{
    return MineReport::create($overrides + [
        'province_id' => test()->province->id,
        'reported_at' => '2026-04-10',
        'character_id' => null,
        'office_key' => 'commissaire_mines',
        'payload' => ['raw' => 'TEXTE BRUT', 'report' => ['mines' => [['number' => 1, 'noeud' => '236', 'days' => []]], 'states' => []], 'prices' => ['OR' => 1], 'rate' => 0.7],
    ]);
}

function readMandate($test, $character, $mandate)
{
    return $test->getJson("/api/v1/characters/{$character->id}/mine-registry/mandates/{$mandate->id}");
}

test('le mandat est bien terminé et aucun accès « en cours » ne subsiste (le défaut décrit par le brief)', function () {
    expect($this->mandate->holds_until->lt(Carbon::now()))->toBeTrue()
        ->and(app(MandateAuthority::class)->mineReaderMandate($this->holder))->toBeNull();

    Passport::actingAs($this->holder->user);
    $this->getJson("/api/v1/characters/{$this->holder->id}/mine-registry/reports")->assertForbidden(); // inchangé : « maintenant » seulement
});

test('un ancien commissaire lit son mandat : fenêtre [in_office_from − 30 j, holds_until[, rien de postérieur', function () {
    $holdsDay = $this->mandate->holds_until->copy()->setTimezone('Europe/Paris')->toDateString();
    $successor = mandatePlayer($this->map['cityA'], ['pseudo' => 'Successeur']);

    pastReport(['reported_at' => '2026-01-29', 'character_id' => $successor->id]);  // AVANT la fenêtre (01/03 − 30 j = 29/01 : la veille)
    pastReport(['reported_at' => '2026-01-30']);                                      // première journée de la fenêtre
    pastReport(['reported_at' => '2026-04-10', 'character_id' => $this->holder->id]); // pendant le mandat
    $last = pastReport(['reported_at' => date('Y-m-d', strtotime($holdsDay.' -1 day'))]); // dernier jour du mandat
    pastReport(['reported_at' => $holdsDay, 'character_id' => $successor->id]);       // le jour où le successeur entre : plus le sien
    pastReport(['reported_at' => '2026-06-05', 'character_id' => $successor->id]);    // POSTÉRIEUR : jamais servi
    Passport::actingAs($this->holder->user);

    $response = readMandate($this, $this->holder, $this->mandate)->assertOk();
    $served = collect($response->json('reports'))->pluck('reported_at')->all();

    expect($served)->toBe([$last->reported_at->toDateString(), '2026-04-10', '2026-01-30'])
        ->and($response->json('read_only'))->toBeTrue()
        ->and($response->json('window'))->toBe(['from' => '2026-01-30', 'to' => $holdsDay])
        ->and($response->json('mandate.id'))->toBe($this->mandate->id)
        ->and($response->json('mandate.in_office_from'))->toBe('2026-03-01')
        ->and($response->json('mandate.ended_at'))->toBe($holdsDay)
        ->and($response->json('mandate.mid_at'))->toBe('2026-03-31')   // dates de bilan : R4, inchangées
        ->and($response->json('mandate.end_at'))->toBe('2026-04-30')
        ->and($response->json('province.name'))->toBe('Artois')
        ->and($response->getContent())->not->toContain('TEXTE BRUT')->not->toContain('Successeur'); // pas un seul relevé du successeur
});

test('le prédécesseur d\'un mandat passé reste dans la fenêtre (relevés d\'AUTRES avant l\'entrée en fonction)', function () {
    $before = mandatePlayer($this->map['cityA'], ['pseudo' => 'Avant']);
    pastReport(['character_id' => $before->id, 'reported_at' => '2026-02-10']);
    pastReport(['character_id' => $before->id, 'reported_at' => '2026-01-10']); // hors fenêtre
    Passport::actingAs($this->holder->user);

    readMandate($this, $this->holder, $this->mandate)->assertOk()
        ->assertJsonCount(1, 'predecessor')
        ->assertJsonPath('predecessor.0.pseudo', 'Avant')
        ->assertJsonPath('predecessor.0.count', 1);
});

test('un ancien DIRIGEANT lit aussi (lecture seule) ; un ancien conseiller sans poste éligible ne lit pas', function () {
    $leader = mandatePlayer($this->map['cityB'], ['pseudo' => 'Comte']);
    atGameTime('2026-03-05 12:00');
    $leaderMandate = approvedCouncil($leader, $this->province, '2026-02-27', '2026-03-01', 'leader');
    $judge = mandatePlayer($this->map['cityB'], ['pseudo' => 'Juge']);
    $judgeMandate = approvedCouncil($judge, $this->province, '2026-02-27', '2026-03-01', 'juge');
    atGameTime('2026-06-10 12:00');
    pastReport();

    Passport::actingAs($leader->user);
    readMandate($this, $leader, $leaderMandate)->assertOk()->assertJsonPath('read_only', true)->assertJsonCount(1, 'reports');

    Passport::actingAs($judge->user);
    readMandate($this, $judge, $judgeMandate)->assertForbidden();
});

test('un mandat RÉVOQUÉ est refusé : une révocation retire l\'accès à ce que le mandat a fait', function () {
    $revoked = mandatePlayer($this->map['cityB'], ['pseudo' => 'Revoque']);
    atGameTime('2026-03-05 12:00');
    $mandate = approvedCouncil($revoked, $this->province, '2026-02-27', '2026-03-01', 'commissaire_mines');
    atGameTime('2026-03-25 12:00');
    mandateWorkflow()->revoke($mandate, 'demission', '2026-03-20', 'Parti.', 'fr');
    atGameTime('2026-06-10 12:00');
    pastReport();
    Passport::actingAs($revoked->user);

    readMandate($this, $revoked, $mandate)->assertForbidden();
    $this->getJson("/api/v1/characters/{$revoked->id}/mine-registry")->assertOk()->assertJsonPath('past_mandates', []);
});

test('le mandat d\'un AUTRE personnage est refusé, comme un mandat qui n\'existe pas (403 dans les deux cas, jamais 404)', function () {
    $other = mandatePlayer($this->map['cityA'], ['pseudo' => 'Autre']);
    Passport::actingAs($other->user);

    readMandate($this, $other, $this->mandate)->assertForbidden()->assertJsonPath('messages.fr', fn ($m) => is_string($m));
    $this->getJson("/api/v1/characters/{$other->id}/mine-registry/mandates/999999")->assertForbidden();
});

test('401 sans jeton, 404 pour le personnage d\'un autre compte', function () {
    $this->getJson("/api/v1/characters/{$this->holder->id}/mine-registry/mandates/{$this->mandate->id}")->assertUnauthorized();

    $stranger = mandatePlayer($this->map['cityA']);
    Passport::actingAs($stranger->user);
    $this->getJson("/api/v1/characters/{$this->holder->id}/mine-registry/mandates/{$this->mandate->id}")->assertNotFound();
});

test('un mandat approuvé mais jamais entré en fonction n\'est pas un mandat passé', function () {
    $elected = mandatePlayer($this->map['cityB'], ['pseudo' => 'Elu']);
    $mandate = approvedCouncil($elected, $this->province, '2026-04-25', null, 'commissaire_mines'); // élu, pas encore en fonction
    Passport::actingAs($elected->user);

    expect(app(MandateAuthority::class)->pastMineReaderMandates($elected))->toHaveCount(0);
    readMandate($this, $elected, $mandate)->assertForbidden();
});

test('un mandat EN COURS : même comportement qu\'avant (aucune borne haute, lecture non seule) et readFor() passe par readForMandate()', function () {
    $current = mandatePlayer($this->map['cityB'], ['pseudo' => 'Actuel']);
    $running = approvedCouncil($current, $this->province, '2026-05-01', '2026-05-03', 'commissaire_mines');
    pastReport(['reported_at' => '2026-02-01']);
    pastReport(['reported_at' => '2026-05-09']);
    pastReport(['reported_at' => '2026-05-10']);
    Passport::actingAs($current->user);

    $viaMandate = readMandate($this, $current, $running)->assertOk();
    $viaReports = $this->getJson("/api/v1/characters/{$current->id}/mine-registry/reports")->assertOk();

    expect($viaMandate->json('reports'))->toHaveCount(3)           // TOUS les relevés : le mandat en cours n'a pas de borne haute
        ->and($viaMandate->json('read_only'))->toBeFalse()
        ->and($viaMandate->json('window'))->toBeNull()
        ->and($viaMandate->json('mandate.ended_at'))->toBeNull()
        ->and($viaReports->json('reports'))->toBe($viaMandate->json('reports'))
        ->and(app(MineRegistryReader::class)->readFor($current))->toBe(app(MineRegistryReader::class)->readForMandate($current, $running));
});

test('`access` : past_mandates liste les mandats terminés (poste, province, période) ; vide sans mandat passé éligible', function () {
    Passport::actingAs($this->holder->user);
    $access = $this->getJson("/api/v1/characters/{$this->holder->id}/mine-registry")->assertOk();

    expect($access->json('write'))->toBeNull()->and($access->json('read'))->toBeNull() // plus rien « maintenant »
        ->and($access->json('past_mandates'))->toHaveCount(1)
        ->and($access->json('past_mandates.0'))->toMatchArray([
            'id' => $this->mandate->id,
            'office_key' => 'commissaire_mines',
            'in_office_from' => '2026-03-01',
        ])
        ->and($access->json('past_mandates.0.province'))->toBe(['id' => $this->province->id, 'name' => 'Artois'])
        ->and($access->json('past_mandates.0.office_label'))->toBe(['fr' => 'Commissaire aux mines', 'en' => 'Mines Superintendent'])
        ->and($access->json('past_mandates.0.holds_until'))->toBe($this->mandate->holds_until->copy()->setTimezone('Europe/Paris')->toDateString());

    $nobody = mandatePlayer($this->map['cityA'], ['pseudo' => 'Personne']);
    Passport::actingAs($nobody->user);
    $this->getJson("/api/v1/characters/{$nobody->id}/mine-registry")->assertOk()->assertJsonPath('past_mandates', []);
});

test('`access` : un mandat passé par personnage, le plus récent d\'abord ; office_key = le DERNIER poste éligible tenu', function () {
    // Même personnage, deux mandats successifs : le premier avec réattribution commissaire_mines → bailli → juge (non éligible).
    $twice = mandatePlayer($this->map['cityB'], ['pseudo' => 'Deux']);
    atGameTime('2025-12-05 12:00');
    $first = approvedCouncil($twice, $this->province, '2025-11-27', '2025-12-01', 'commissaire_mines');
    atGameTime('2025-12-15 12:00');
    mandateWorkflow()->setOffice($first, 'bailli');
    atGameTime('2026-01-01 12:00');
    mandateWorkflow()->setOffice($first, 'juge');
    atGameTime('2026-03-05 12:00');
    $second = approvedCouncil($twice, $this->province, '2026-02-27', '2026-03-05', 'leader');
    atGameTime('2026-06-10 12:00');
    Passport::actingAs($twice->user);

    $past = $this->getJson("/api/v1/characters/{$twice->id}/mine-registry")->assertOk()->json('past_mandates');

    expect(collect($past)->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and($past[0]['office_key'])->toBe('leader')
        ->and($past[1]['office_key'])->toBe('bailli'); // le dernier poste ÉLIGIBLE, pas « juge »
});

test('la fenêtre ne dépend que du mandat : un autre mandat passé du même personnage a la sienne', function () {
    $twice = mandatePlayer($this->map['cityB'], ['pseudo' => 'Deux']);
    atGameTime('2025-12-05 12:00');
    $first = approvedCouncil($twice, $this->province, '2025-11-27', '2025-12-01', 'commissaire_mines');
    atGameTime('2026-03-05 12:00');
    approvedCouncil($twice, $this->province, '2026-02-27', '2026-03-05', 'commissaire_mines');
    atGameTime('2026-06-10 12:00');
    pastReport(['reported_at' => '2026-01-20']);  // dans la fenêtre du 1er mandat (01/01), hors de celle du 2e
    pastReport(['reported_at' => '2026-04-10']);  // dans la fenêtre du 2e, après la fin du 1er
    Passport::actingAs($twice->user);

    $served = collect(readMandate($this, $twice, $first)->assertOk()->json('reports'))->pluck('reported_at')->all();

    expect($served)->toBe(['2026-01-20']); // jamais le relevé du 10/04, postérieur à la fin du premier mandat
});
