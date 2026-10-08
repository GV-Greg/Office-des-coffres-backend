<?php

use App\Models\MineReport;
use App\Models\User;
use App\Services\MineRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

/*
| Registre des mines — écriture (PR 1b ; brief admin/content/brief-registre-mines.md §3, §6 ; fil
| admin/echanges/registre-mines R3 et R3 bis). Trois cas imposés par le fil : identique → refus,
| tronqué → refus, un jour de plus ou une valeur corrigée → accepté et remplacement visible.
*/

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    atGameTime('2026-05-10 12:00');
    // Réside en Berry, commissaire aux mines en Artois : la province écrite est celle du POSTE.
    $this->commissaire = mandatePlayer($this->map['cityB']);
    approvedCouncil($this->commissaire, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'commissaire_mines');
    Passport::actingAs($this->commissaire->user);
});

afterEach(fn () => Carbon::setTestNow());

function mineDay(array $days, array $states = []): array
{
    return ['mines' => [['number' => 1, 'noeud' => '236', 'label' => "Mine d'or", 'resource' => 'OR', 'days' => $days]], 'states' => $states];
}

function postReport($test, $character, array $report, array $extra = [])
{
    return $test->postJson("/api/v1/characters/{$character->id}/mine-reports", $extra + [
        'raw' => "Mine 1 : Mine d'or - Noeud 236\n…",
        'report' => $report,
        'prices' => ['OR' => 1, 'PIERRE' => 20],
        'rate' => 0.7,
    ]);
}

$twoDays = ['2026-05-08' => ['heures' => 53, 'production' => 139.97], '2026-05-09' => ['heures' => 58, 'production' => 272.39]];

test('un commissaire aux mines enregistre un relevé dans la province de son POSTE, daté du jour (Paris), estampillé', function () use ($twoDays) {
    postReport($this, $this->commissaire, mineDay($twoDays))
        ->assertCreated()
        ->assertJsonPath('report.province_id', $this->map['provinceA']->id)
        ->assertJsonPath('report.reported_at', '2026-05-10')
        ->assertJsonPath('report.office_key', 'commissaire_mines')
        ->assertJsonPath('replaced', null);

    $report = MineReport::sole();
    expect($report->character_id)->toBe($this->commissaire->id)
        ->and($report->active)->toBeTrue()
        ->and($report->payload['raw'])->toStartWith("Mine 1 : Mine d'or")
        ->and($report->payload['rate'])->toBe(0.7);
});

test('le bailli écrit aussi, estampillé bailli', function () use ($twoDays) {
    $bailli = mandatePlayer($this->map['cityA']);
    approvedCouncil($bailli, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'bailli');
    Passport::actingAs($bailli->user);

    postReport($this, $bailli, mineDay($twoDays))->assertCreated()->assertJsonPath('report.office_key', 'bailli');
});

test('le dirigeant consulte mais n\'écrit pas ; un autre poste non plus — refus bilingue', function () use ($twoDays) {
    foreach (['leader', 'juge'] as $office) {
        $other = mandatePlayer($this->map['cityA']);
        approvedCouncil($other, $this->map['provinceA'], '2026-05-01', '2026-05-03', $office);
        Passport::actingAs($other->user);

        postReport($this, $other, mineDay($twoDays))
            ->assertUnprocessable()
            ->assertJsonPath('codes.character', 'mine_not_authorized')
            ->assertJsonStructure(['messages' => ['character' => ['fr', 'en']]]);
    }
    expect(MineReport::count())->toBe(0);
});

test('le personnage d\'un autre compte : 404, rien n\'est écrit', function () use ($twoDays) {
    Passport::actingAs(User::factory()->create());

    postReport($this, $this->commissaire, mineDay($twoDays))->assertNotFound();
    expect(MineReport::count())->toBe(0);
});

test('R3 bis — un relevé IDENTIQUE est refusé, même si le texte collé diffère', function () use ($twoDays) {
    postReport($this, $this->commissaire, mineDay($twoDays))->assertCreated();

    // Même relevé analysé, autre texte collé et nombres écrits autrement (58 / 58.0) : identique.
    $same = $twoDays;
    $same['2026-05-09']['heures'] = 58.0;
    postReport($this, $this->commissaire, mineDay($same), ['raw' => "  Mine 1 : Mine d'or - Noeud 236\n\n…", 'confirm_replace' => true])
        ->assertUnprocessable()
        ->assertJsonPath('codes.report', 'mine_report_identical');

    expect(MineReport::count())->toBe(1)->and(MineReport::sole()->active)->toBeTrue();
});

test('R3 bis — un relevé qui en dit STRICTEMENT MOINS est refusé', function () use ($twoDays) {
    postReport($this, $this->commissaire, mineDay($twoDays))->assertCreated();

    postReport($this, $this->commissaire, mineDay(['2026-05-08' => $twoDays['2026-05-08']]), ['confirm_replace' => true])
        ->assertUnprocessable()
        ->assertJsonPath('codes.report', 'mine_report_less');

    expect(MineReport::count())->toBe(1);
});

test('R3 — un jour de plus demande une confirmation, qui nomme l\'auteur à remplacer ; rien n\'est écrit sans elle', function () use ($twoDays) {
    postReport($this, $this->commissaire, mineDay($twoDays))->assertCreated();
    $more = $twoDays + ['2026-05-10' => ['heures' => 113]];

    postReport($this, $this->commissaire, mineDay($more))
        ->assertStatus(409)
        ->assertJsonPath('code', 'mine_report_needs_confirmation')
        ->assertJsonPath('existing.pseudo', $this->commissaire->pseudo)
        ->assertJsonPath('existing.office_key', 'commissaire_mines')
        ->assertJsonStructure(['messages' => ['fr', 'en']]);

    expect(MineReport::count())->toBe(1);
});

test('R3 bis — un autre titulaire remplace avec un jour de plus : l\'ancien reste, remplacé et visible', function () use ($twoDays) {
    postReport($this, $this->commissaire, mineDay($twoDays))->assertCreated();
    $old = MineReport::sole();

    $bailli = mandatePlayer($this->map['cityA']);
    approvedCouncil($bailli, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'bailli');
    Passport::actingAs($bailli->user);
    $more = $twoDays + ['2026-05-10' => ['heures' => 113]];

    postReport($this, $bailli, mineDay($more), ['confirm_replace' => true])
        ->assertCreated()
        ->assertJsonPath('replaced.pseudo', $this->commissaire->pseudo);

    $old->refresh();
    $new = MineReport::where('active', true)->sole();
    expect(MineReport::count())->toBe(2)
        ->and($old->active)->toBeNull()
        ->and($old->replaced_by_id)->toBe($new->id)
        ->and($old->replaced_at)->not->toBeNull()
        ->and($new->office_key)->toBe('bailli');
});

test('R3 bis — une valeur CORRIGÉE, même nombre de faits, est acceptée', function () use ($twoDays) {
    postReport($this, $this->commissaire, mineDay($twoDays))->assertCreated();
    $fixed = $twoDays;
    $fixed['2026-05-09']['production'] = 280;

    postReport($this, $this->commissaire, mineDay($fixed), ['confirm_replace' => true])->assertCreated();
    expect(MineReport::count())->toBe(2)->and(MineReport::where('active', true)->count())->toBe(1);
});

test('la comparaison ignore le libellé de langue et l\'ordre des mines, et claveté sur le nœud', function () {
    $fr = ['mines' => [
        ['number' => 1, 'noeud' => '236', 'label' => "Mine d'or", 'days' => ['2026-05-09' => ['heures' => 5]]],
        ['number' => 2, 'noeud' => '228', 'label' => 'Mine de fer', 'days' => ['2026-05-09' => ['heures' => 7]]],
    ]];
    $en = ['mines' => [
        ['number' => 2, 'noeud' => '228', 'label' => 'Iron mine', 'days' => ['2026-05-09' => ['heures' => '7']]],
        ['number' => 1, 'noeud' => '236', 'label' => 'Gold mine', 'days' => ['2026-05-09' => ['heures' => 5.0]]],
    ]];

    expect(MineRegistry::facts($en))->toBe(MineRegistry::facts($fr));
});

test('le lendemain, un nouveau relevé s\'ajoute sans rien remplacer', function () use ($twoDays) {
    postReport($this, $this->commissaire, mineDay($twoDays))->assertCreated();
    atGameTime('2026-05-11 09:00');

    postReport($this, $this->commissaire, mineDay($twoDays))->assertCreated()->assertJsonPath('replaced', null);
    expect(MineReport::where('active', true)->count())->toBe(2);
});

test('la forme est validée : pas de mines, pas de texte collé → 422 bilingue', function () {
    $this->postJson("/api/v1/characters/{$this->commissaire->id}/mine-reports", ['report' => ['mines' => []]])
        ->assertUnprocessable()
        ->assertJsonStructure(['codes' => ['raw', 'report.mines'], 'messages' => ['raw' => ['fr', 'en']]]);
});
