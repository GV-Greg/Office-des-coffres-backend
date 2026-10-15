<?php

use App\Models\CouncilOfficePeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

// Recalage des postes déjà inscrits en production (Greg, 16/10/2026) : la validation du mandat ne compte pas dans la date
// à laquelle le personnage occupe le poste. Voir App\Support\OfficeStart et la commande mandates:realign-office-starts.

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    atGameTime('2026-05-10 12:00');
});

afterEach(fn () => Carbon::setTestNow());

/** Reproduit la donnée de prod : la période est née à la validation du mandat, après son entrée en fonction. */
function legacyPeriod($mandate): CouncilOfficePeriod
{
    $period = $mandate->officePeriods()->sole();
    $period->forceFill(['started_at' => $mandate->processed_at])->save();

    return $period->fresh();
}

test('simulation par défaut : rien ne s\'écrit', function () {
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'commissaire_mines');
    $legacy = legacyPeriod($mandate);

    Artisan::call('mandates:realign-office-starts');

    expect(Artisan::output())->toContain('Simulation')
        ->and($legacy->fresh()->started_at->toIso8601String())->toBe($legacy->started_at->toIso8601String());
});

test('--force recale le poste sur l\'entrée en fonction du mandat, une seule fois', function () {
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'commissaire_mines');
    legacyPeriod($mandate);

    Artisan::call('mandates:realign-office-starts', ['--force' => true]);
    expect($mandate->officePeriods()->sole()->started_at->toIso8601String())->toBe($mandate->fresh()->in_office_from->toIso8601String());

    Artisan::call('mandates:realign-office-starts', ['--force' => true]);
    expect(Artisan::output())->toContain('Aucun poste à recaler');
});

test('une nomination faite après coup n\'est jamais recalée : elle commence bien à sa date', function () {
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03', null);
    atGameTime('2026-05-08 12:00');
    mandateWorkflow()->setOffice($mandate, 'commissaire_mines');
    $before = $mandate->officePeriods()->sole()->started_at->toIso8601String();

    Artisan::call('mandates:realign-office-starts', ['--force' => true]);

    expect($mandate->officePeriods()->sole()->started_at->toIso8601String())->toBe($before);
});

test('jamais si un autre mandat a tenu ce poste dans l\'intervalle (exclusivité)', function () {
    $first = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'commissaire_mines');
    $second = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'commissaire_mines');
    // Le second est né à la validation (exclusivité respectée) : le recaler chevaucherait le premier.
    legacyPeriod($second);
    $startBefore = $second->officePeriods()->sole()->started_at->toIso8601String();

    Artisan::call('mandates:realign-office-starts', ['--force' => true]);

    expect($second->officePeriods()->sole()->started_at->toIso8601String())->toBe($startBefore)
        ->and(Artisan::output())->toContain('ignoré');
});
