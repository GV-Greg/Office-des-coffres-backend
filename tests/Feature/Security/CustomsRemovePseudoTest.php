<?php

use App\Models\CustomsReport;
use App\Models\CustomsWatchlistEntry;
use App\Services\CustomsReports;
use App\Services\CustomsWatchlist;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

/*
| `customs:remove-pseudo` (module Sécurité) : OUTIL INTERNE, hors politique — Greg, 22/11/2026 : « si quelqu'un m'écrit, j'utiliserai l'outil ».
| Simulation par défaut ; retire un pseudo des listes (suppression) et le remplace dans l'analyse ET dans le brut chiffré des bilans, comme MOT
| ENTIER : les points font partie des pseudos, retirer `Gregor.` ne touche jamais `Gregor..`.
*/

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    $this->province = $this->map['provinceA'];
    $this->city = $this->map['cityA'];
    $character = mandatePlayer($this->map['cityB'], ['pseudo' => 'Le_prevot']);
    atGameTime('2026-05-03 12:00');
    approvedCouncil($character, $this->province, '2026-05-01', '2026-05-03', 'prevot_des_marechaux');
    atGameTime('2026-05-10 12:00');
    $this->prevot = $character;
});

afterEach(fn () => Carbon::setTestNow());

function removePseudoFixture($test): void
{
    $guet = [
        'raw' => "Liste des villageois (3)\nGregor.\t255PR\nGregor..\t255PR\nAutre_gregor.\t12PR",
        'parser_version' => 1,
        'report' => ['villagers' => [
            ['pseudo' => 'Gregor.', 'noblesse' => '', 'reputation' => 255],
            ['pseudo' => 'Gregor..', 'noblesse' => '', 'reputation' => 255],
            ['pseudo' => 'Autre_gregor.', 'noblesse' => '', 'reputation' => 12],
        ], 'declared_total' => 3],
    ];
    $visions = [
        'raw' => "Le 10 Mai 1474\nAujourd'hui, en chemin, vous avez croisé Gregor., Elyas.\nAujourd'hui, en chemin, vous avez croisé un groupe composé de Qao et de GREGOR..",
        'parser_version' => 1,
        'report' => ['entries' => [
            ['singles' => ['Gregor.', 'Elyas'], 'groups' => []],
            ['singles' => [], 'groups' => [['Qao', 'GREGOR.']]],
        ], 'other_days' => 0],
    ];
    app(CustomsReports::class)->record($test->prevot, $test->city, ['guet' => $guet, 'visions' => $visions]);
    app(CustomsWatchlist::class)->add($test->prevot, 'gregor.', 'red', 'r');
    app(CustomsWatchlist::class)->add($test->prevot, 'Gregor..', 'green', null);
}

test('la SIMULATION par défaut dit ce qui serait touché et n\'écrit rien', function () {
    removePseudoFixture($this);
    $before = CustomsReport::first()->payload;

    $code = Artisan::call('customs:remove-pseudo', ['pseudo' => 'Gregor.']);
    $output = Artisan::output();

    expect($code)->toBe(0)->and($output)->toContain('SIMULATION')->toContain('1 entrée(s) de liste')->toContain('1 bilan(s)')->toContain('--force')
        ->and(CustomsWatchlistEntry::count())->toBe(2)
        ->and(CustomsReport::first()->payload)->toBe($before);
});

test('--force retire le pseudo des listes et le remplace comme MOT ENTIER dans l\'analyse et le brut, casse ignorée', function () {
    removePseudoFixture($this);

    Artisan::call('customs:remove-pseudo', ['pseudo' => 'Gregor.', '--force' => true]);
    $payload = CustomsReport::first()->payload['sections'];
    $guet = $payload['guet'];
    $visions = $payload['visions'];

    // listes : « gregor. » supprimée, « Gregor.. » intacte
    expect(CustomsWatchlistEntry::all()->map->pseudo()->all())->toBe(['Gregor..']);

    // analyse du guet : Gregor. remplacé, Gregor.. et Autre_gregor. intacts
    expect(array_column($guet['report']['villagers'], 'pseudo'))->toBe(['[retiré]', 'Gregor..', 'Autre_gregor.'])
        ->and($guet['raw'])->toBe("Liste des villageois (3)\n[retiré]\t255PR\nGregor..\t255PR\nAutre_gregor.\t12PR");

    // visions : seul `Gregor.` (casse ignorée), jamais `GREGOR..` (deux points = un autre pseudo… ou « GREGOR. » + le point de phrase)
    expect($visions['report']['entries'][0]['singles'])->toBe(['[retiré]', 'Elyas'])
        ->and($visions['report']['entries'][1]['groups'])->toBe([['Qao', '[retiré]']])
        ->and($visions['raw'])->toContain('croisé [retiré], Elyas.')->toContain('Qao et de [retiré].');
});

test('un pseudo qui en contient un autre n\'est jamais touché : « Gregor » ne retire ni « Gregor. » ni « Autre_gregor. »', function () {
    removePseudoFixture($this);

    Artisan::call('customs:remove-pseudo', ['pseudo' => 'Gregor', '--force' => true]);

    expect(Artisan::output())->toContain('0 entrée(s) de liste')->toContain('0 bilan(s)')
        ->and(CustomsWatchlistEntry::count())->toBe(2)
        ->and(array_column(CustomsReport::first()->payload['sections']['guet']['report']['villagers'], 'pseudo'))->toBe(['Gregor.', 'Gregor..', 'Autre_gregor.']);
});

test('les bilans remplacés sont nettoyés aussi (historique compris) et le bilan reste en vigueur', function () {
    removePseudoFixture($this);
    $reports = app(CustomsReports::class);
    $reports->record($this->prevot, $this->city, ['guet' => [
        'raw' => "Gregor.\t1PR", 'parser_version' => 1, 'report' => ['villagers' => [['pseudo' => 'Gregor.', 'noblesse' => '', 'reputation' => 1]], 'declared_total' => null],
    ]]); // correction : l'ancien bilan passe « remplacé »

    expect(CustomsReport::count())->toBe(2);
    Artisan::call('customs:remove-pseudo', ['pseudo' => 'Gregor.', '--force' => true]);

    expect(Artisan::output())->toContain('2 bilan(s)')
        ->and(CustomsReport::where('active', true)->count())->toBe(1)
        ->and(CustomsReport::all()->every(fn ($r) => ! str_contains(json_encode($r->payload), '"Gregor."')))->toBeTrue();
});

test('un pseudo avec une espace est refusé', function () {
    expect(Artisan::call('customs:remove-pseudo', ['pseudo' => 'Deux mots']))->toBe(1);
});
