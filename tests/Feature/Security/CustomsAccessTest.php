<?php

use App\Models\Character;
use App\Services\CustomsAccess;
use App\Services\CustomsOfficers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Matrice d'accès du Registre de douane (brief admin/content/brief-module-securite.md §3, corrigée par Greg les 21 et 22/11/2026) :
| App\Services\CustomsAccess. Le repli « sans trio connu » est le mode COURANT d'une province, jamais étendu à un conseiller sans titre.
*/

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    $this->province = $this->map['provinceA'];
    $this->access = app(CustomsAccess::class);
    $this->seat = function (string $office, ?string $pseudo = null): Character {
        $character = mandatePlayer($this->map['cityB'], ['pseudo' => $pseudo ?? ucfirst($office)]);
        atGameTime('2026-05-03 12:00');
        approvedCouncil($character, $this->province, '2026-05-01', '2026-05-03', $office);
        atGameTime('2026-05-10 12:00');

        return $character;
    };
    atGameTime('2026-05-10 12:00');
});

afterEach(fn () => Carbon::setTestNow());

test('le prévôt et le connétable : tout, correction comprise, auteurs visibles, toute la province', function (string $office) {
    $holder = ($this->seat)($office);
    $access = $this->access->for($holder);

    expect($access)->toMatchArray([
        'role' => $office, 'read_reports' => true, 'write_reports' => true, 'correct_reports' => true,
        'read_lists' => true, 'write_lists' => true, 'add_red_only' => false, 'sees_authors' => true,
    ])->and($this->access->writableCities($access)->pluck('id')->all())->toBe([$this->map['cityA']->id, $this->map['cityA2']->id])
        ->and($this->access->canWriteIn($access, $this->map['cityB']))->toBeFalse(); // une autre province
})->with(['prevot_des_marechaux', 'connetable']);

test('le capitaine lit et écrit, sans corriger', function () {
    $access = $this->access->for(($this->seat)('capitaine'));

    expect($access)->toMatchArray(['write_reports' => true, 'correct_reports' => false, 'write_lists' => true, 'sees_authors' => true]);
});

test('le juge n\'ajoute qu\'au rouge et lit les listes, jamais les bilans ; le procureur lit les listes seulement', function () {
    $juge = $this->access->for(($this->seat)('juge'));
    $procureur = $this->access->for(($this->seat)('procureur'));

    expect($juge)->toMatchArray(['read_reports' => false, 'write_reports' => false, 'read_lists' => true, 'write_lists' => false, 'add_red_only' => true, 'sees_authors' => false])
        ->and($procureur)->toMatchArray(['read_reports' => false, 'read_lists' => true, 'write_lists' => false, 'add_red_only' => false]);
});

test('le dirigeant lit les bilans de toute la province et les listes, sans rien écrire', function () {
    $access = $this->access->for(($this->seat)('leader'));

    expect($access)->toMatchArray(['read_reports' => true, 'write_reports' => false, 'read_lists' => true, 'write_lists' => false])
        ->and($this->access->readableCities($access)->pluck('id')->all())->toBe([$this->map['cityA']->id, $this->map['cityA2']->id])
        ->and($this->access->writableCities($access))->toHaveCount(0);
});

test('un conseiller sans titre, un juge d\'une autre province ou un joueur quelconque n\'ont aucun accès', function () {
    $councillor = mandatePlayer($this->map['cityB']);
    atGameTime('2026-05-03 12:00');
    approvedCouncil($councillor, $this->province, '2026-05-01', '2026-05-03', 'bailli');
    atGameTime('2026-05-10 12:00');

    expect($this->access->for($councillor))->toBeNull()
        ->and($this->access->for(mandatePlayer($this->map['cityA'])))->toBeNull();
});

test('le maire saisit pour SA ville seulement ; sans trio connu il écrit les listes, avec un trio il les lit seulement', function () {
    $mayor = mandatePlayer($this->map['cityA'], ['pseudo' => 'Maire']);
    atGameTime('2026-05-03 12:00');
    approvedMayor($mayor, $this->map['cityA'], '2026-05-01');
    atGameTime('2026-05-10 12:00');

    $alone = $this->access->for($mayor);
    expect($alone)->toMatchArray(['role' => 'mayor', 'write_reports' => true, 'correct_reports' => false, 'read_lists' => true, 'write_lists' => true, 'trio_known' => false])
        ->and($this->access->writableCities($alone)->pluck('id')->all())->toBe([$this->map['cityA']->id]);

    ($this->seat)('prevot_des_marechaux');
    $withTrio = $this->access->for($mayor);
    expect($withTrio)->toMatchArray(['write_lists' => false, 'read_lists' => true, 'trio_known' => true, 'write_reports' => true]);
});

test('le douanier validé saisit pour toute la province ; mêmes règles de repli pour les listes', function () {
    $officer = mandatePlayer($this->map['cityA'], ['pseudo' => 'Douanier']);
    $officers = app(CustomsOfficers::class);
    $officers->decideAsAdmin($officers->request($officer, $this->map['cityA']), true);

    $access = $this->access->for($officer);
    expect($access)->toMatchArray(['role' => 'customs', 'write_reports' => true, 'write_lists' => true, 'trio_known' => false])
        ->and($this->access->writableCities($access))->toHaveCount(2);

    ($this->seat)('capitaine');
    expect($this->access->for($officer))->toMatchArray(['write_lists' => false, 'read_lists' => true, 'write_reports' => true]);
});

test('une demande de douanier non validée ne donne aucun accès', function () {
    $applicant = mandatePlayer($this->map['cityA']);
    app(CustomsOfficers::class)->request($applicant, $this->map['cityA']);

    expect($this->access->for($applicant))->toBeNull();
});

test('seuls le prévôt et le connétable saisissent jusqu\'à 7 jours du jeu en arrière', function () {
    $prevot = $this->access->for(($this->seat)('prevot_des_marechaux'));
    $captain = $this->access->for(($this->seat)('capitaine'));

    expect($this->access->earliestDay($prevot))->toBe('2026-05-03')
        ->and($this->access->earliestDay($captain))->toBe('2026-05-10');
});
