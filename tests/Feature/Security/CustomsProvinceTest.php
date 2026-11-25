<?php

use App\Models\Character;
use App\Models\CustomsOfficer;
use App\Models\CustomsReport;
use App\Services\CustomsOfficers;
use App\Services\CustomsReports;
use App\Services\CustomsWatchlist;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

/*
| Vue d'ensemble de la province (PR 5, maquette validée le 23/11/2026) : couverture par ville
| et, FUSIONNÉS, les pseudos marqués, les groupes à surveiller et les armées — chaque ligne datée de son jour de bilan, jamais de différentiel entre
| villes. LECTURE SEULE, aucun droit nouveau : seules les villes que le rôle lit déjà.
*/

function provGuet(array $pseudos): array
{
    return ['raw' => 'Liste des villageois ('.count($pseudos).")\n".implode("\n", array_map(fn ($p) => "$p\t255PR", $pseudos)), 'parser_version' => 1, 'report' => [
        'villagers' => array_map(fn ($p) => ['pseudo' => $p, 'noblesse' => '', 'reputation' => 255], $pseudos),
        'declared_total' => count($pseudos),
    ]];
}

function provVisions(array $singles, array $groups = []): array
{
    return ['raw' => "Le 10 Mai 1474\nAujourd'hui, en chemin, vous avez croisé Elyas.", 'parser_version' => 1, 'report' => [
        'entries' => [['singles' => $singles, 'groups' => $groups]], 'other_days' => 0,
    ]];
}

function provArmies(): array
{
    return ['raw' => "Armées présentes ici :\nArmée \"Legio\" commandée par Lucas_", 'parser_version' => 1, 'report' => [
        'armies' => [['name' => 'Legio', 'commander' => 'Lucas_', 'status' => 'aux portes']],
        'groups' => [
            ['type' => 'groupe', 'members' => ['Sefrian', 'Gregor.'], 'status' => 'Admission libre'],
            ['type' => 'corps_armes', 'members' => ['Damonkael'], 'status' => 'Fermé'],
        ],
        'zone' => 'La mairie est maîtresse du statu quo.',
    ]];
}

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    $this->province = $this->map['provinceA'];
    $this->arras = $this->map['cityA'];
    $this->bethune = $this->map['cityA2'];
    $this->reports = app(CustomsReports::class);
    $this->watchlist = app(CustomsWatchlist::class);
    $this->seat = function (string $office, string $pseudo): Character {
        $character = mandatePlayer($this->map['cityB'], ['pseudo' => $pseudo]);
        atGameTime('2026-05-03 12:00');
        approvedCouncil($character, $this->province, '2026-05-01', '2026-05-03', $office);
        atGameTime('2026-05-10 12:00');

        return $character;
    };
    $this->officer = function (string $pseudo = 'Douanier'): Character {
        $character = mandatePlayer($this->map['cityA'], ['pseudo' => $pseudo]);
        $officers = app(CustomsOfficers::class);
        $officers->decideAsAdmin($officers->request($character, $this->map['cityA']), true);

        return $character;
    };
    // Un jeu de bilans pour Arras : le guet du 07, du 08 et du 10 (un premier bilan du jour exige le guet) ; les armées du 08 SEULEMENT, les visions du 10.
    $this->seed = function (): Character {
        $prevot = ($this->seat)('prevot_des_marechaux', 'Prevot');
        $officer = ($this->officer)('Douanier');
        atGameTime('2026-05-07 12:00');
        $this->watchlist->add($prevot, 'Gregor.', 'red', 'brigandage');
        $this->watchlist->add($prevot, 'Artifice', 'green', null);
        $this->watchlist->add($prevot, 'Sefrian', 'red', null);
        $this->reports->record($officer, $this->arras, ['guet' => provGuet(['Ancien', 'Artifice'])]);
        atGameTime('2026-05-08 12:00');
        $this->reports->record($officer, $this->arras, ['guet' => provGuet(['Gregor.', 'Artifice', 'Nouveau']), 'armies_groups' => provArmies()]);
        atGameTime('2026-05-10 12:00');
        $this->reports->record($officer, $this->arras, ['guet' => provGuet(['Gregor.', 'Artifice', 'Nouveau', 'Autre']), 'visions' => provVisions(['Gregor.', 'Inconnu'])]);

        return $prevot;
    };
    atGameTime('2026-05-10 12:00');
});

afterEach(fn () => Carbon::setTestNow());

// ─── Droits : aucun droit nouveau ───────────────────────────────────────

test('qui lit plusieurs villes voit la vue : le trio, un douanier validé et le dirigeant ; un jeton est exigé', function () {
    foreach (['prevot_des_marechaux' => 'Prevot', 'connetable' => 'Connetable', 'capitaine' => 'Capitaine', 'leader' => 'Comte'] as $office => $pseudo) {
        $character = ($this->seat)($office, $pseudo);
        Passport::actingAs($character->user);
        $this->getJson("/api/v1/characters/{$character->id}/customs-province")->assertOk()->assertJsonPath('success', true);
    }

    $officer = ($this->officer)('Douanier');
    Passport::actingAs($officer->user);
    $this->getJson("/api/v1/characters/{$officer->id}/customs-province")->assertOk();

    $this->app['auth']->forgetGuards();
    $this->getJson("/api/v1/characters/{$officer->id}/customs-province")->assertUnauthorized();
});

test('un maire simple (sa seule ville), un juge, un procureur et un joueur sans rôle : refusés, dans les deux langues', function () {
    $mayor = mandatePlayer($this->arras, ['pseudo' => 'Maire']);
    approvedMayor($mayor, $this->arras, '2026-05-01');
    $judge = ($this->seat)('juge', 'Juge');
    $prosecutor = ($this->seat)('procureur', 'Procureur');
    $nobody = mandatePlayer($this->arras, ['pseudo' => 'Personne']);

    foreach ([$mayor, $judge, $prosecutor, $nobody] as $character) {
        Passport::actingAs($character->user);
        $response = $this->getJson("/api/v1/characters/{$character->id}/customs-province")->assertForbidden();
        expect($response->json('code'))->toBe('province_not_readable')
            ->and($response->json('messages'))->toHaveKeys(['fr', 'en']);
    }
});

test('le refus est bilingue : l\'Office ne connaît aucun rôle qui lise plusieurs villes', function () {
    $nobody = mandatePlayer($this->arras, ['pseudo' => 'Personne']);
    Passport::actingAs($nobody->user);

    $response = $this->getJson("/api/v1/characters/{$nobody->id}/customs-province")->assertForbidden();

    expect($response->json('messages.fr'))->toContain('plusieurs villes')
        ->and($response->json('messages.en'))->toContain('several towns');
});

test('le personnage d\'un autre compte répond 404, jamais 403', function () {
    $prevot = ($this->seat)('prevot_des_marechaux', 'Prevot');
    $other = mandatePlayer($this->arras, ['pseudo' => 'Autre']);
    Passport::actingAs($other->user);

    $this->getJson("/api/v1/characters/{$prevot->id}/customs-province")->assertNotFound();
});

// ─── La fusion ──────────────────────────────────────────────────────────

test('la couverture : une ligne par ville LISIBLE, avec le jour de son dernier bilan ; une ville sans bilan est listée, pas cachée', function () {
    $prevot = ($this->seed)();
    Passport::actingAs($prevot->user);

    $cities = collect($this->getJson("/api/v1/characters/{$prevot->id}/customs-province")->assertOk()->json('cities'))->keyBy('name');

    expect($cities->keys()->all())->toEqualCanonicalizing(['Arras', 'Béthune']) // jamais Bourges (autre province)
        ->and($cities['Arras']['last_report_day'])->toBe('2026-05-10')
        ->and($cities['Arras']['guet_day'])->toBe('2026-05-10')
        ->and($cities['Arras']['inhabitants'])->toBe(4)
        ->and($cities['Arras']['groups'])->toBe(2) // le NOMBRE de groupes du bilan du 08 (armées et groupes) : un bilan se voit même sans alerte
        ->and($cities['Béthune']['groups'])->toBeNull()
        ->and($cities['Béthune'])->toMatchArray(['last_report_day' => null, 'guet_day' => null, 'inhabitants' => null, 'arrivals' => null, 'departures' => null]);
});

test('arrivées et départs : les PSEUDOS de chaque ville, comparés au bilan antérieur de la MÊME ville — jamais entre villes', function () {
    $prevot = ($this->seed)();
    Passport::actingAs($prevot->user);

    $response = $this->getJson("/api/v1/characters/{$prevot->id}/customs-province")->assertOk();
    $cities = collect($response->json('cities'))->keyBy('name');

    expect($cities['Arras']['arrivals'])->toBe([['pseudo' => 'Autre', 'colour' => null]])
        ->and($cities['Arras']['departures'])->toBe([])
        ->and($cities['Arras']['compared_to'])->toBe('2026-05-08') // le bilan antérieur de la MÊME ville
        ->and($cities['Béthune']['arrivals'])->toBeNull() // sans bilan : rien à comparer
        ->and(array_column($cities['Arras']['arrivals'], 'pseudo'))->not->toContain('Nouveau'); // « Nouveau » était déjà là au bilan du 08 : une arrivée de CE bilan, c'est « Autre » seulement
});

test('les marques : seulement les pseudos des listes, rouge d\'abord, chacun avec sa ville, comment il a été vu et le jour du bilan', function () {
    $prevot = ($this->seed)();
    Passport::actingAs($prevot->user);

    $marks = $this->getJson("/api/v1/characters/{$prevot->id}/customs-province")->json('marks');
    $summary = array_map(fn ($m) => "{$m['pseudo']}|{$m['colour']}|{$m['as']}|{$m['city']['name']}|{$m['day']}", $marks);

    expect($summary)->toBe([
        'Gregor.|red|villager|Arras|2026-05-10', // rouge d'abord, le plus récent d'abord
        'Gregor.|red|vision|Arras|2026-05-10',
        'Gregor.|red|group_member|Arras|2026-05-08', // les armées et groupes sont du 08 : leur ligne dit son jour
        'Sefrian|red|group_member|Arras|2026-05-08',
        'Artifice|green|villager|Arras|2026-05-10',
    ])
        ->and(json_encode($marks))->not->toContain('Inconnu')->not->toContain('Nouveau')->not->toContain('Autre'); // jamais un pseudo hors des listes
});

test('un pseudo vu à deux jours de bilan apparaît sur chacun, chaque ligne datée : on déduit le mouvement sans le dire', function () {
    $prevot = ($this->seed)();
    Passport::actingAs($prevot->user);

    $gregor = array_values(array_filter($this->getJson("/api/v1/characters/{$prevot->id}/customs-province")->json('marks'), fn ($m) => $m['pseudo'] === 'Gregor.'));

    expect(array_column($gregor, 'day'))->toBe(['2026-05-10', '2026-05-10', '2026-05-08'])
        ->and(array_column($gregor, 'as'))->toBe(['villager', 'vision', 'group_member']);
});

test('les groupes : TOUS (Greg, 24/11/2026), chacun avec son indicateur ; seul « Groupe de … » alerte ; le détail d\'un groupe n\'est pas servi', function () {
    $prevot = ($this->seed)();
    Passport::actingAs($prevot->user);

    $response = $this->getJson("/api/v1/characters/{$prevot->id}/customs-province")->assertOk();
    $groups = collect($response->json('groups'));

    expect($groups)->toHaveCount(2)
        ->and($groups->firstWhere('type', 'groupe'))->toMatchArray(['alert' => true, 'day' => '2026-05-08', 'city' => ['id' => $this->arras->id, 'name' => 'Arras']])
        ->and($groups->firstWhere('type', 'groupe')['members'])->toBe([['pseudo' => 'Sefrian', 'colour' => 'red'], ['pseudo' => 'Gregor.', 'colour' => 'red']])
        ->and($groups->firstWhere('type', 'corps_armes'))->toMatchArray(['alert' => false])
        ->and($groups->firstWhere('type', 'corps_armes')['members'])->toBe([['pseudo' => 'Damonkael', 'colour' => null]])
        ->and($response->getContent())->not->toContain('Admission libre')->not->toContain('Fermé'); // le détail d'un groupe
});

test('la liste des habitants de chaque ville (pseudo, noblesse, couleur) : celle du dernier guet, jamais le brut ni la réputation', function () {
    $prevot = ($this->seed)();
    Passport::actingAs($prevot->user);

    $response = $this->getJson("/api/v1/characters/{$prevot->id}/customs-province")->assertOk();
    $cities = collect($response->json('cities'))->keyBy('name');

    expect(array_column($cities['Arras']['villagers'], 'pseudo'))->toBe(['Gregor.', 'Artifice', 'Nouveau', 'Autre'])
        ->and($cities['Arras']['villagers'][0])->toBe(['pseudo' => 'Gregor.', 'noblesse' => '', 'colour' => 'red'])
        ->and($cities['Arras']['villagers'][1]['colour'])->toBe('green')
        ->and($cities['Arras']['villagers'][2]['colour'])->toBeNull()
        ->and($cities['Béthune']['villagers'])->toBeNull() // sans guet
        ->and($response->getContent())->not->toContain('Liste des villageois')->not->toContain('reputation');
});

test('les armées : nom, commandant, ville et jour du bilan', function () {
    $prevot = ($this->seed)();
    Passport::actingAs($prevot->user);

    $armies = $this->getJson("/api/v1/characters/{$prevot->id}/customs-province")->json('armies');

    expect($armies)->toBe([['name' => 'Legio', 'commander' => ['pseudo' => 'Lucas_', 'colour' => null], 'city' => ['id' => $this->arras->id, 'name' => 'Arras'], 'day' => '2026-05-08']]);
});

test('la couleur est celle de la DATE du bilan : une entrée ajoutée après ne colore pas un bilan plus ancien', function () {
    $prevot = ($this->seat)('prevot_des_marechaux', 'Prevot');
    $officer = ($this->officer)('Douanier');
    atGameTime('2026-05-08 12:00');
    $this->reports->record($officer, $this->arras, ['guet' => provGuet(['Retard'])]);
    atGameTime('2026-05-10 12:00');
    $this->watchlist->add($prevot, 'Retard', 'red', null); // ajouté APRÈS le bilan du 08
    Passport::actingAs($prevot->user);

    $this->getJson("/api/v1/characters/{$prevot->id}/customs-province")->assertOk()->assertJsonPath('marks', []);
});

test('le dirigeant (lecture seule) voit la même vue', function () {
    $this->seed->call($this);
    $leader = ($this->seat)('leader', 'Comte');
    Passport::actingAs($leader->user);

    $this->getJson("/api/v1/characters/{$leader->id}/customs-province")->assertOk()
        ->assertJsonPath('province.name', 'Artois')
        ->assertJsonCount(2, 'cities');
});

// ─── Naviguer dans les jours (Greg, 24/11/2026) ─────────────────────────

test('la situation AU jour D : pour chaque ville, ses bilans jusqu\'à ce jour — le guet du 08 et les armées du 08, pas les visions du 10', function () {
    $prevot = ($this->seed)();
    Passport::actingAs($prevot->user);

    $response = $this->getJson("/api/v1/characters/{$prevot->id}/customs-province?date=2026-05-08")->assertOk();
    $arras = collect($response->json('cities'))->firstWhere('name', 'Arras');

    expect($response->json('as_of'))->toBe('2026-05-08')
        ->and($response->json('today'))->toBe('2026-05-10') // le jour du jeu reste dit
        ->and($arras['last_report_day'])->toBe('2026-05-08')
        ->and($arras['guet_day'])->toBe('2026-05-08')
        ->and($arras['inhabitants'])->toBe(3)
        ->and($arras['compared_to'])->toBe('2026-05-07')
        ->and(array_column($response->json('marks'), 'as'))->not->toContain('vision') // les visions sont du 10 : pas encore
        ->and($response->json('groups'))->toHaveCount(2); // les armées et groupes du 08
});

test('un jour sans aucun bilan avant lui : toutes les villes sans bilan, aucune ligne', function () {
    $prevot = ($this->seed)();
    Passport::actingAs($prevot->user);

    $response = $this->getJson("/api/v1/characters/{$prevot->id}/customs-province?date=2026-05-01")->assertOk();

    expect(array_filter($response->json('cities'), fn ($c) => $c['last_report_day'] !== null))->toBe([])
        ->and($response->json('marks'))->toBe([])
        ->and($response->json('armies'))->toBe([]);
});

test('sans date, ou avec une date FUTURE : aujourd\'hui — jamais le futur', function () {
    $prevot = ($this->seed)();
    Passport::actingAs($prevot->user);

    expect($this->getJson("/api/v1/characters/{$prevot->id}/customs-province")->json('as_of'))->toBe('2026-05-10')
        ->and($this->getJson("/api/v1/characters/{$prevot->id}/customs-province?date=2026-06-30")->json('as_of'))->toBe('2026-05-10');
});

test('une date mal formée est refusée', function () {
    $prevot = ($this->seed)();
    Passport::actingAs($prevot->user);

    $this->getJson("/api/v1/characters/{$prevot->id}/customs-province?date=demain")->assertUnprocessable();
});

test('les jours à naviguer : ceux qui ont un bilan dans une ville lisible, du plus récent au plus ancien, sans doublon', function () {
    $prevot = ($this->seed)();
    Passport::actingAs($prevot->user);

    expect($this->getJson("/api/v1/characters/{$prevot->id}/customs-province")->json('days'))->toBe(['2026-05-10', '2026-05-08', '2026-05-07']);
});

// ─── Ce qui ne sort jamais, et ce qui n'est jamais écrit ────────────────

test('jamais le brut, jamais l\'auteur, jamais la liste complète des habitants', function () {
    $prevot = ($this->seed)();
    Passport::actingAs($prevot->user);

    $body = $this->getJson("/api/v1/characters/{$prevot->id}/customs-province")->assertOk()->getContent();

    expect($body)->not->toContain('Liste des villageois')
        ->not->toContain('Douanier') // l'auteur du bilan
        ->not->toContain('by_me')
        ->not->toContain('"raw"')
        ->not->toContain('brigandage'); // la raison d'une entrée de liste
});

test('LECTURE SEULE : rien n\'est écrit', function () {
    $prevot = ($this->seed)();
    Passport::actingAs($prevot->user);
    $before = [CustomsReport::count(), DB::table('customs_watchlist_entries')->count(), CustomsOfficer::count()];

    $this->getJson("/api/v1/characters/{$prevot->id}/customs-province")->assertOk();

    expect([CustomsReport::count(), DB::table('customs_watchlist_entries')->count(), CustomsOfficer::count()])->toBe($before);
});
