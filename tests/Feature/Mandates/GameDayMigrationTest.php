<?php

use App\Models\CouncilOfficePeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| Le jour du jeu commence à 04:00 (validé le 20/11/2026) : la migration décale de +4 h
| les instants que l'ANCIENNE règle avait posés à 00:00 heure de Paris — et seulement ceux-là.
*/

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    $this->migration = require database_path('migrations/2026_11_20_000001_shift_mandate_instants_to_game_day_04h.php');
});

afterEach(fn () => Carbon::setTestNow());

test('un mandat posé à minuit Paris passe à 04:00 le même jour, heure d\'hiver comme heure d\'été', function () {
    $player = mandatePlayer($this->map['cityA']);
    atGameTime('2026-10-21 12:00');
    $mandate = approvedMayor($player, $this->map['cityA'], '2026-10-21');

    // L'ancien état de la base : début le 21/10 à 00:00 (CEST = 22:00 UTC la veille), fin le 20/11 à 00:00 (CET = 23:00 UTC la veille).
    DB::table('mayor_mandates')->where('id', $mandate->id)->update([
        'valid_until' => '2026-11-19 23:00:00',
        'in_office_from' => '2026-10-20 22:00:00',
        'holds_until' => '2026-11-19 23:00:00',
    ]);

    $this->migration->up();

    $row = DB::table('mayor_mandates')->where('id', $mandate->id)->first();
    expect($row->in_office_from)->toBe('2026-10-21 02:00:00')  // 21/10 04:00 CEST
        ->and($row->valid_until)->toBe('2026-11-20 03:00:00')   // 20/11 04:00 CET
        ->and($row->holds_until)->toBe('2026-11-20 03:00:00');
});

test('une valeur posée à la main à une autre heure ne bouge pas, et un second passage ne touche rien', function () {
    $player = mandatePlayer($this->map['cityA']);
    atGameTime('2026-10-21 12:00');
    $mandate = approvedMayor($player, $this->map['cityA'], '2026-10-21');
    DB::table('mayor_mandates')->where('id', $mandate->id)->update([
        'valid_until' => '2026-11-19 23:00:00',
        'in_office_from' => '2026-10-20 22:00:00',
        'holds_until' => '2026-12-01 17:12:26', // fin corrigée à la main par l'admin
    ]);

    $this->migration->up();
    $once = DB::table('mayor_mandates')->where('id', $mandate->id)->first();
    expect($once->holds_until)->toBe('2026-12-01 17:12:26')
        ->and($once->valid_until)->toBe('2026-11-20 03:00:00');

    $this->migration->up(); // rejouable : 04:00 n'est plus minuit
    $twice = DB::table('mayor_mandates')->where('id', $mandate->id)->first();
    expect($twice)->toEqual($once);
});

test('les périodes de poste suivent : bornes à minuit Paris → 04:00', function () {
    $player = mandatePlayer($this->map['cityA']);
    atGameTime('2026-10-21 12:00');
    $mandate = approvedCouncil($player, $this->map['provinceA'], '2026-10-21', '2026-10-21', 'commissaire_commerce');
    $period = CouncilOfficePeriod::where('council_mandate_id', $mandate->id)->firstOrFail();
    DB::table('council_office_periods')->where('id', $period->id)->update([
        'started_at' => '2026-10-20 22:00:00',
        'ended_at' => '2026-12-19 23:00:00',
    ]);

    $this->migration->up();

    $row = DB::table('council_office_periods')->where('id', $period->id)->first();
    expect($row->started_at)->toBe('2026-10-21 02:00:00')
        ->and($row->ended_at)->toBe('2026-12-20 03:00:00');
});

test('down() est sans effet : on ne défait pas ce qu\'on ne peut pas distinguer', function () {
    $player = mandatePlayer($this->map['cityA']);
    atGameTime('2026-10-21 12:00');
    $mandate = approvedMayor($player, $this->map['cityA'], '2026-10-21');
    $before = DB::table('mayor_mandates')->where('id', $mandate->id)->first();

    $this->migration->down();

    expect(DB::table('mayor_mandates')->where('id', $mandate->id)->first())->toEqual($before);
});
