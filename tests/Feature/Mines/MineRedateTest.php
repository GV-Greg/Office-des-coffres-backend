<?php

use App\Models\MineReport;
use App\Models\Province;
use Illuminate\Support\Carbon;

/*
| mines:redate — redate les relevés inscrits avant le 10/10/2026, tous datés du jour du collage (Greg,
| 10/10 : trois anciens collages inscrits le même jour se remplaçaient, l'historique des niveaux se lisait
| à l'envers). Nouvelle date = dernier jour des données ; par province et date, le plus récent est en vigueur.
*/

afterEach(fn () => Carbon::setTestNow());

/** Un relevé inscrit « aujourd'hui » à l'ancienne, dont les données s'arrêtent au jour $lastDay. */
function legacyReport(Province $province, string $lastDay, ?MineReport $replaces = null, string $at = '2026-10-10 10:00'): MineReport
{
    Carbon::setTestNow(Carbon::parse($at, 'Europe/Paris'));
    $replaces?->update(['active' => null]); // comme MineRegistry : l'ancien sort de la vigueur d'abord
    $report = MineReport::create([
        'province_id' => $province->id, 'reported_at' => '2026-10-10', 'character_id' => null, 'office_key' => 'commissaire_mines',
        'payload' => ['raw' => 'x', 'report' => ['mines' => [['number' => 1, 'noeud' => '236', 'days' => [$lastDay => ['heures' => 5]]]], 'states' => []]],
        'active' => true,
    ]);
    $replaces?->update(['replaced_by_id' => $report->id, 'replaced_at' => now()]);

    return $report;
}

test('le cas de la prod : trois anciens collages datés du 10/10 retrouvent leurs dates et sont tous en vigueur', function () {
    $province = Province::factory()->create();
    $a = legacyReport($province, '2026-10-03');
    $b = legacyReport($province, '2026-10-06', $a, '2026-10-10 10:05');
    $c = legacyReport($province, '2026-10-10', $b, '2026-10-10 10:10');
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00', 'Europe/Paris'));

    $this->artisan('mines:redate')->expectsOutputToContain('Simulation : 2 relevé(s) changeraient')->assertSuccessful();
    expect($a->fresh()->reported_at->toDateString())->toBe('2026-10-10'); // simulation : rien d'écrit

    $this->artisan('mines:redate', ['--force' => true])->assertSuccessful();

    expect([$a->fresh(), $b->fresh(), $c->fresh()])->sequence(
        fn ($r) => $r->reported_at->toDateString()->toBe('2026-10-03'),
        fn ($r) => $r->reported_at->toDateString()->toBe('2026-10-06'),
        fn ($r) => $r->reported_at->toDateString()->toBe('2026-10-10'),
    );
    expect(MineReport::where('active', true)->count())->toBe(3)
        ->and(MineReport::whereNotNull('replaced_by_id')->count())->toBe(0);

    $this->artisan('mines:redate')->expectsOutputToContain('Aucun relevé à redater')->assertSuccessful();
});

test('deux collages qui finissent le même jour : le plus récemment inscrit reste en vigueur, l\'autre remplacé par lui', function () {
    $province = Province::factory()->create();
    $first = legacyReport($province, '2026-10-05');
    $second = legacyReport($province, '2026-10-05', $first, '2026-10-10 11:00');
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00', 'Europe/Paris'));

    $this->artisan('mines:redate', ['--force' => true])->assertSuccessful();

    expect($first->fresh())->active->toBeNull()->replaced_by_id->toBe($second->id)
        ->and($second->fresh())->active->toBeTrue()->reported_at->toDateString()->toBe('2026-10-05');
});
