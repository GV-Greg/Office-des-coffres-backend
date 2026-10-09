<?php

use App\Models\MineReport;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

/*
| Registre des mines — lecture (PR 4 ; brief §7 ; fil registre-mines, R4). Le serveur sert les faits :
| relevés déchiffrés (sans le texte brut), estampilles, remplacements visibles, dates du mandat DU
| LECTEUR, et le prédécesseur comme des estampilles déjà écrites — jamais « qui occupait le poste ».
*/

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    atGameTime('2026-05-10 12:00');
    $this->reader = mandatePlayer($this->map['cityB']);
    approvedCouncil($this->reader, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'commissaire_mines');
    Passport::actingAs($this->reader->user);
});

afterEach(fn () => Carbon::setTestNow());

function registryEntry(array $overrides = []): MineReport
{
    return MineReport::create($overrides + [
        'province_id' => test()->map['provinceA']->id,
        'reported_at' => '2026-05-09',
        'character_id' => null,
        'office_key' => 'commissaire_mines',
        'payload' => ['raw' => 'TEXTE BRUT', 'report' => ['mines' => [['number' => 1, 'noeud' => '236', 'days' => []]], 'states' => []], 'prices' => ['OR' => 1], 'rate' => 0.7],
    ]);
}

function readRegistry($test, $character)
{
    return $test->getJson("/api/v1/characters/{$character->id}/mine-registry/reports");
}

test('le commissaire lit le registre de la province de son POSTE : relevés déchiffrés, sans le texte brut', function () {
    registryEntry(['character_id' => $this->reader->id]);
    registryEntry(['province_id' => $this->map['provinceB']->id]); // autre province : jamais servie

    $response = readRegistry($this, $this->reader)->assertOk()
        ->assertJsonPath('province.name', 'Artois')
        ->assertJsonPath('today', '2026-05-10')
        ->assertJsonCount(1, 'reports')
        ->assertJsonPath('reports.0.author.pseudo', $this->reader->pseudo)
        ->assertJsonPath('reports.0.author.office_label.en', 'Mines Superintendent')
        ->assertJsonPath('reports.0.report.mines.0.noeud', '236')
        ->assertJsonPath('reports.0.rate', 0.7);

    expect($response->getContent())->not->toContain('TEXTE BRUT');
});

test('les dates du mandat sont celles du LECTEUR : entrée en fonction, mi-mandat +30 j, fin +60 j (Paris)', function () {
    readRegistry($this, $this->reader)->assertOk()
        ->assertJsonPath('mandate.in_office_from', '2026-05-03')
        ->assertJsonPath('mandate.mid_at', '2026-06-02')
        ->assertJsonPath('mandate.end_at', '2026-07-02');
});

test('un remplacement reste visible : l\'ancien relevé est servi, marqué remplacé', function () {
    $new = registryEntry();
    registryEntry(['active' => null, 'replaced_by_id' => $new->id, 'replaced_at' => now()]);

    $reports = readRegistry($this, $this->reader)->assertOk()->json('reports');
    expect(collect($reports)->where('active', false)->first()['replaced_by_id'])->toBe($new->id);
});

test('prédécesseur : relevés d\'AUTRES personnages dans les 30 jours avant l\'entrée en fonction, en vigueur seulement', function () {
    $before = mandatePlayer($this->map['cityA']);
    registryEntry(['character_id' => $before->id, 'reported_at' => '2026-04-05']);
    registryEntry(['character_id' => $before->id, 'reported_at' => '2026-04-28']);
    registryEntry(['character_id' => $before->id, 'reported_at' => '2026-04-01']); // hors fenêtre (avant le 03/04)
    registryEntry(['character_id' => $before->id, 'reported_at' => '2026-05-04']); // après l'entrée en fonction
    registryEntry(['character_id' => $before->id, 'reported_at' => '2026-04-20', 'active' => null]); // remplacé
    registryEntry(['character_id' => $this->reader->id, 'reported_at' => '2026-04-30']); // le lecteur lui-même

    readRegistry($this, $this->reader)->assertOk()
        ->assertJsonCount(1, 'predecessor')
        ->assertJsonPath('predecessor.0.pseudo', $before->pseudo)
        ->assertJsonPath('predecessor.0.first', '2026-04-05')
        ->assertJsonPath('predecessor.0.last', '2026-04-28')
        ->assertJsonPath('predecessor.0.count', 2);
});

test('le dirigeant lit ; un autre poste, non — refus bilingue ; un autre compte : 404', function () {
    $leader = mandatePlayer($this->map['cityA']);
    approvedCouncil($leader, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'leader');
    Passport::actingAs($leader->user);
    readRegistry($this, $leader)->assertOk()->assertJsonPath('province.name', 'Artois');

    $judge = mandatePlayer($this->map['cityA']);
    approvedCouncil($judge, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    Passport::actingAs($judge->user);
    readRegistry($this, $judge)->assertForbidden()
        ->assertJsonPath('code', 'mine_not_reader')
        ->assertJsonStructure(['messages' => ['fr', 'en']]);

    Passport::actingAs(User::factory()->create());
    readRegistry($this, $this->reader)->assertNotFound();
});
