<?php

use App\Exceptions\MandateRefusal;
use App\Models\Character;
use App\Models\CustomsOfficer;
use App\Models\CustomsReport;
use App\Services\AccountExport;
use App\Services\CustomsAccess;
use App\Services\CustomsOfficers;
use App\Services\CustomsReports;
use App\Services\CustomsWatchlist;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

/*
| Bilans de défense (module Sécurité, PR 3-1, du 22/11/2026) : UN bilan par ville et par
| jour du jeu, brut + analyse du frontend dans un payload chiffré, jamais re-parsé, auteur jamais affiché, coloration à la lecture.
*/

function customsGuet(array $pseudos = ['Gregor.', 'Artifice', 'Emmie....'], ?int $declared = null): array
{
    return ['raw' => 'Liste des villageois ('.count($pseudos).")\n".implode("\n", array_map(fn ($p) => "$p\t255PR", $pseudos)), 'parser_version' => 1, 'report' => [
        'villagers' => array_map(fn ($p) => ['pseudo' => $p, 'noblesse' => '', 'reputation' => 255], $pseudos),
        'declared_total' => $declared ?? count($pseudos),
    ]];
}

function customsVisions(array $singles = ['Elyas'], array $groups = [['Qao', 'Zorah']]): array
{
    return ['raw' => "Le 22 Mai 1474\nAujourd'hui, en chemin, vous avez croisé Elyas.", 'parser_version' => 1, 'report' => [
        'entries' => [['singles' => $singles, 'groups' => $groups]], 'other_days' => 2,
    ]];
}

function customsArmies(): array
{
    return ['raw' => "Armées présentes ici :\nArmée \"Legio\" commandée par Lucas_", 'parser_version' => 1, 'report' => [
        'armies' => [['name' => 'Legio', 'commander' => 'Lucas_', 'status' => "aux portes\nne recrute pas"]],
        'groups' => [
            ['type' => 'groupe', 'members' => ['Sefrian'], 'status' => 'Admission libre'],
            ['type' => 'corps_armes', 'members' => ['Damonkael', 'Thymotee_bilal'], 'status' => 'Fermé'],
            ['type' => 'milice', 'members' => [], 'status' => 'embauches'],
        ],
        'zone' => 'La mairie est maîtresse du statu quo sur ce noeud.',
    ]];
}

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    $this->province = $this->map['provinceA'];
    $this->city = $this->map['cityA'];
    $this->reports = app(CustomsReports::class);
    $this->access = app(CustomsAccess::class);
    $this->seat = function (string $office, ?string $pseudo = null): Character {
        $character = mandatePlayer($this->map['cityB'], ['pseudo' => $pseudo ?? ucfirst($office)]);
        atGameTime('2026-05-03 12:00');
        approvedCouncil($character, $this->province, '2026-05-01', '2026-05-03', $office);
        atGameTime('2026-05-10 12:00');

        return $character;
    };
    $this->officer = function (?string $pseudo = null): Character {
        $character = mandatePlayer($this->map['cityA'], ['pseudo' => $pseudo ?? 'Douanier']);
        $officers = app(CustomsOfficers::class);
        $officers->decideAsAdmin($officers->request($character, $this->map['cityA']), true);

        return $character;
    };
    atGameTime('2026-05-10 12:00');
});

afterEach(fn () => Carbon::setTestNow());

// ─── Écriture ──────────────────────────────────────────────────────────────────────────────

test('un douanier saisit le bilan du jour : brut ET analyse dans un payload chiffré, le jour du jeu est décidé par le serveur', function () {
    $officer = ($this->officer)();

    $result = $this->reports->record($officer, $this->city, ['guet' => customsGuet(), 'armies_groups' => customsArmies()]);
    $report = $result['report'];

    expect($result['mode'])->toBe('created')
        ->and($report->reported_on->toDateString())->toBe('2026-05-10')
        ->and($report->author_role)->toBe('customs')
        ->and($report->payload['sections']['guet']['raw'])->toContain('Gregor.')
        ->and($report->payload['sections']['guet']['report']['villagers'])->toHaveCount(3)
        ->and($report->payload['sections']['guet']['parser_version'])->toBe(1)
        ->and($report->payload['sections']['guet']['report']['declared_total'])->toBe(3);
});

test('le payload est CHIFFRÉ en base : ni pseudo ni texte collé lisibles', function () {
    $officer = ($this->officer)();
    $this->reports->record($officer, $this->city, ['guet' => customsGuet(['Pseudo_secret_unique']), 'armies_groups' => customsArmies()]);

    $stored = (string) DB::table('customs_reports')->value('payload');

    expect($stored)->not->toBe('')->and($stored)->not->toContain('Pseudo_secret_unique')->and($stored)->not->toContain('Legio')->and($stored)->not->toContain('villagers');
});

test('un premier bilan exige le guet', function () {
    $officer = ($this->officer)();

    expect(fn () => $this->reports->record($officer, $this->city, ['visions' => customsVisions()]))->toThrow(MandateRefusal::class);
});

test('une seule saisie par ville et par jour : un autre douanier voit le bilan et n\'en saisit pas un second', function () {
    $first = ($this->officer)('Premier');
    $second = ($this->officer)('Second');
    $this->reports->record($first, $this->city, ['guet' => customsGuet()]);

    expect(fn () => $this->reports->record($second, $this->city, ['guet' => customsGuet(['Autre'])]))->toThrow(MandateRefusal::class)
        ->and(CustomsReport::where('active', true)->count())->toBe(1);
});

test('le MÊME auteur complète les sections manquantes le même jour, sans toucher à celles déjà saisies', function () {
    $officer = ($this->officer)();
    $first = $this->reports->record($officer, $this->city, ['guet' => customsGuet()])['report'];

    $done = $this->reports->record($officer, $this->city, ['visions' => customsVisions(), 'armies_groups' => customsArmies()]);

    expect($done['mode'])->toBe('completed')
        ->and(array_keys($done['report']->payload['sections']))->toBe(['guet', 'visions', 'armies_groups'])
        ->and($done['report']->payload['sections']['guet'])->toBe($first->payload['sections']['guet'])
        ->and($first->refresh()->active)->toBeNull()
        ->and($first->replaced_by_id)->toBe($done['report']->id)
        ->and(CustomsReport::where('active', true)->count())->toBe(1);
});

test('le MÊME auteur CORRIGE le sien le jour du jeu même (Greg, 24/11/2026) : la section remplacée, les autres gardées, l\'ancienne version en archive', function () {
    $officer = ($this->officer)();
    $first = $this->reports->record($officer, $this->city, ['guet' => customsGuet(['Ancien']), 'armies_groups' => customsArmies()])['report'];

    $result = $this->reports->record($officer, $this->city, ['armies_groups' => customsArmies()]); // ses groupes ont changé après la première saisie

    expect($result['mode'])->toBe('corrected')
        ->and($result['report']->payload['sections']['guet'])->toBe($first->payload['sections']['guet'])
        ->and(array_keys($result['report']->payload['sections']))->toBe(['guet', 'armies_groups'])
        ->and($result['report']->character_id)->toBe($officer->id)
        ->and($first->refresh()->active)->toBeNull()
        ->and($first->replaced_by_id)->toBe($result['report']->id)
        ->and(CustomsReport::where('active', true)->count())->toBe(1)
        ->and(CustomsReport::count())->toBe(2); // rien n'est effacé

    // Même le guet : l'auteur peut remplacer sa propre liste du jour.
    $again = $this->reports->record($officer, $this->city, ['guet' => customsGuet(['Nouveau'])]);
    expect($again['mode'])->toBe('corrected')
        ->and($again['report']->payload['sections']['guet']['report']['villagers'][0]['pseudo'])->toBe('Nouveau');
});

test('l\'auteur ne corrige PAS le bilan d\'un jour passé, ni celui d\'un autre : seuls le prévôt et le connétable', function () {
    $officer = ($this->officer)();
    $other = ($this->officer)('Autre');
    $this->reports->record($officer, $this->city, ['guet' => customsGuet()]);

    expect(fn () => $this->reports->record($officer, $this->city, ['guet' => customsGuet(['Corrige'])], '2026-05-09')) // hier, le « jour du jeu » étant le 10->toThrow(MandateRefusal::class)
        ->and(fn () => $this->reports->record($other, $this->city, ['armies_groups' => customsArmies()]))->toThrow(MandateRefusal::class)
        ->and(CustomsReport::where('active', true)->count())->toBe(1);
});

test('le prévôt et le connétable CORRIGENT : la section remplacée, les autres gardées, l\'ancien bilan reste en historique', function (string $office) {
    $officer = ($this->officer)();
    $original = $this->reports->record($officer, $this->city, ['guet' => customsGuet(['Ancien']), 'visions' => customsVisions()])['report'];
    $holder = ($this->seat)($office);

    $result = $this->reports->record($holder, $this->city, ['guet' => customsGuet(['Nouveau'])]);

    expect($result['mode'])->toBe('corrected')
        ->and($result['report']->payload['sections']['guet']['report']['villagers'][0]['pseudo'])->toBe('Nouveau')
        ->and($result['report']->payload['sections'])->toHaveKey('visions')
        ->and($result['report']->author_role)->toBe($office)
        ->and($original->refresh()->active)->toBeNull()
        ->and($original->payload['sections']['guet']['report']['villagers'][0]['pseudo'])->toBe('Ancien')
        ->and(CustomsReport::count())->toBe(2);
})->with(['prevot_des_marechaux', 'connetable']);

test('le capitaine saisit mais ne corrige pas le bilan d\'un autre', function () {
    $officer = ($this->officer)();
    $this->reports->record($officer, $this->city, ['guet' => customsGuet()]);

    expect(fn () => $this->reports->record(($this->seat)('capitaine'), $this->city, ['guet' => customsGuet(['Autre'])]))->toThrow(MandateRefusal::class);
});

test('un jour passé : prévôt et connétable seulement, jusqu\'à 7 jours du jeu, jamais le futur ; le douanier n\'a que le jour en cours', function () {
    $prevot = ($this->seat)('prevot_des_marechaux');
    $officer = ($this->officer)();

    $past = $this->reports->record($prevot, $this->city, ['guet' => customsGuet()], '2026-05-03');
    expect($past['report']->reported_on->toDateString())->toBe('2026-05-03');

    expect(fn () => $this->reports->record($prevot, $this->city, ['guet' => customsGuet()], '2026-05-02'))->toThrow(MandateRefusal::class) // 8 jours
        ->and(fn () => $this->reports->record($prevot, $this->city, ['guet' => customsGuet()], '2026-05-11'))->toThrow(MandateRefusal::class) // demain
        ->and(fn () => $this->reports->record($officer, $this->city, ['guet' => customsGuet()], '2026-05-09'))->toThrow(MandateRefusal::class);
});

test('le jour du jeu bascule à 04:00 : avant, on saisit encore la veille', function () {
    $officer = ($this->officer)();
    atGameTime('2026-05-10 03:30');

    expect($this->reports->record($officer, $this->city, ['guet' => customsGuet()])['report']->reported_on->toDateString())->toBe('2026-05-09');
});

test('le maire saisit pour sa ville, pas pour une autre ville de sa province ; un joueur sans rôle ne saisit rien', function () {
    $mayor = mandatePlayer($this->map['cityA'], ['pseudo' => 'Maire']);
    atGameTime('2026-05-03 12:00');
    approvedMayor($mayor, $this->map['cityA'], '2026-05-01');
    atGameTime('2026-05-10 12:00');

    expect($this->reports->record($mayor, $this->city, ['guet' => customsGuet()])['mode'])->toBe('created')
        ->and(fn () => $this->reports->record($mayor, $this->map['cityA2'], ['guet' => customsGuet()]))->toThrow(MandateRefusal::class)
        ->and(fn () => $this->reports->record(mandatePlayer($this->map['cityA']), $this->city, ['guet' => customsGuet()]))->toThrow(MandateRefusal::class);
});

test('un client dont le parseur est trop ancien est invité à recharger', function () {
    $officer = ($this->officer)();
    $old = customsGuet();
    $old['parser_version'] = 0;

    expect(fn () => $this->reports->record($officer, $this->city, ['guet' => $old]))->toThrow(MandateRefusal::class);
});

test('SEULE la saisie d\'un bilan remet le compteur d\'inactivité du douanier à zéro', function () {
    $officer = ($this->officer)();
    $row = CustomsOfficer::where('character_id', $officer->id)->first();
    expect($row->last_report_at)->toBeNull();

    atGameTime('2026-05-20 10:00');
    $this->reports->record($officer, $this->city, ['guet' => customsGuet()]);

    expect($row->refresh()->last_report_at->toDateString())->toBe('2026-05-20');
});

// ─── Lecture ───────────────────────────────────────────────────────────────────────────────

test('la lecture ne sert jamais le brut ni l\'auteur ; `by_me` seulement, et le total annoncé est confronté aux lignes lues', function () {
    $officer = ($this->officer)('Auteur_secret');
    $report = $this->reports->record($officer, $this->city, ['guet' => customsGuet(['A', 'B'], 5), 'armies_groups' => customsArmies()])['report'];
    $viewer = ($this->seat)('prevot_des_marechaux');

    $json = json_encode($this->reports->present($report, $viewer, $this->access->for($viewer)));
    $own = $this->reports->present($report, $officer, $this->access->for($officer));

    expect($json)->not->toContain('Auteur_secret')->and($json)->not->toContain('"raw"')->and($json)->not->toContain('Liste des villageois')
        ->and($own['by_me'])->toBeTrue()
        ->and($this->reports->present($report, $viewer, $this->access->for($viewer))['by_me'])->toBeFalse()
        ->and($own['sections']['guet'])->toMatchArray(['declared_total' => 5, 'read_total' => 2, 'total_matches' => false])
        ->and($own['sections']['armies_groups']['zone'])->toBe('La mairie est maîtresse du statu quo sur ce noeud.');
});

test('les groupes : tous les membres sont lus, seul « Groupe de » alerte, la milice n\'a aucun pseudo', function () {
    $officer = ($this->officer)();
    $report = $this->reports->record($officer, $this->city, ['guet' => customsGuet(), 'armies_groups' => customsArmies()])['report'];

    $groups = collect($this->reports->present($report, $officer, $this->access->for($officer))['sections']['armies_groups']['groups']);

    expect($groups->pluck('alert')->all())->toBe([true, false, false])
        ->and($groups[1]['members'])->toHaveCount(2)
        ->and($groups[2]['members'])->toBe([]);
});

test('la coloration se calcule à la lecture, EN MINUSCULES, à partir de la date d\'ajout : jamais sur un bilan plus ancien', function () {
    $prevot = ($this->seat)('prevot_des_marechaux');
    $watchlist = app(CustomsWatchlist::class);

    // Bilan du 07/05, puis entrée ajoutée le 10/05, puis bilan du 10/05.
    $old = $this->reports->record($prevot, $this->city, ['guet' => customsGuet(['GREGOR.', 'Artifice'])], '2026-05-07')['report'];
    $watchlist->add($prevot, 'gregor.', 'red', 'brigandage');
    $watchlist->add($prevot, 'ARTIFICE', 'green', null);
    $today = $this->reports->record($prevot, $this->city, ['guet' => customsGuet(['Gregor.', 'artifice', 'Inconnu'])])['report'];
    $access = $this->access->for($prevot);

    $coloursOf = fn ($report) => collect($this->reports->present($report, $prevot, $access)['sections']['guet']['villagers'])->mapWithKeys(fn ($v) => [$v['pseudo'] => $v['colour']])->all();

    expect($coloursOf($old))->toBe(['GREGOR.' => null, 'Artifice' => null])           // ajoutés APRÈS le jour du bilan
        ->and($coloursOf($today))->toBe(['Gregor.' => 'red', 'artifice' => 'green', 'Inconnu' => null]); // casse d'origine gardée à l'affichage
});

test('un pseudo retiré d\'une liste ne colore plus aucun bilan (retrait = suppression)', function () {
    $prevot = ($this->seat)('prevot_des_marechaux');
    $watchlist = app(CustomsWatchlist::class);
    $entry = $watchlist->add($prevot, 'Gregor.', 'red', null);
    $report = $this->reports->record($prevot, $this->city, ['guet' => customsGuet(['Gregor.'])])['report'];
    $access = $this->access->for($prevot);

    expect($this->reports->present($report, $prevot, $access)['sections']['guet']['villagers'][0]['colour'])->toBe('red');

    $watchlist->remove($prevot, $entry->id);

    expect($this->reports->present($report, $prevot, $access)['sections']['guet']['villagers'][0]['colour'])->toBeNull();
});

test('le différentiel du guet compare au DERNIER bilan antérieur de la ville et dit lequel', function () {
    $prevot = ($this->seat)('prevot_des_marechaux');
    $this->reports->record($prevot, $this->city, ['guet' => customsGuet(['Reste', 'Part', 'Aussi'])], '2026-05-05');
    // Un jour sans guet (visions seules, saisies par correction) ne sert pas de point de comparaison.
    $today = $this->reports->record($prevot, $this->city, ['guet' => customsGuet(['reste', 'Arrive'])])['report'];

    $diff = $this->reports->present($today, $prevot, $this->access->for($prevot))['sections']['guet']['diff'];

    expect($diff['compared_to'])->toBe('2026-05-05')
        ->and(collect($diff['arrivals'])->pluck('pseudo')->all())->toBe(['Arrive'])
        ->and(collect($diff['departures'])->pluck('pseudo')->all())->toBe(['Part', 'Aussi']);

    $first = $this->reports->present($this->reports->current($this->city, '2026-05-05'), $prevot, $this->access->for($prevot));
    expect($first['sections']['guet']['diff'])->toBeNull(); // rien d'antérieur : jamais de différentiel inventé
});

test('les visions : singles et groupes colorés, jours ignorés comptés', function () {
    $prevot = ($this->seat)('prevot_des_marechaux');
    app(CustomsWatchlist::class)->add($prevot, 'Zorah', 'red', null);
    $report = $this->reports->record($prevot, $this->city, ['guet' => customsGuet(), 'visions' => customsVisions()])['report'];

    $visions = $this->reports->present($report, $prevot, $this->access->for($prevot))['sections']['visions'];

    expect($visions['other_days'])->toBe(2)
        ->and($visions['entries'][0]['singles'][0])->toBe(['pseudo' => 'Elyas', 'colour' => null])
        ->and($visions['entries'][0]['groups'][0][1])->toBe(['pseudo' => 'Zorah', 'colour' => 'red']);
});

test('les jours qui ont un bilan sont listés du plus récent au plus ancien', function () {
    $prevot = ($this->seat)('prevot_des_marechaux');
    foreach (['2026-05-05', '2026-05-10', '2026-05-08'] as $day) {
        $this->reports->record($prevot, $this->city, ['guet' => customsGuet()], $day);
    }

    expect($this->reports->days($this->city))->toBe(['2026-05-10', '2026-05-08', '2026-05-05']);
});

// ─── API ───────────────────────────────────────────────────────────────────────────────────

test('l\'API : droits dits avant l\'envoi, saisie, lecture sans brut ni auteur, 401 sans jeton, 404 pour le personnage d\'un autre compte', function () {
    $officer = ($this->officer)('Douanier');

    $this->getJson("/api/v1/characters/{$officer->id}/customs-access")->assertUnauthorized();
    Passport::actingAs($officer->user);

    $access = $this->getJson("/api/v1/characters/{$officer->id}/customs-access")->assertOk()->json('access');
    expect($access)->toMatchArray(['role' => 'customs', 'write_reports' => true, 'correct_reports' => false, 'has_security_trio' => false, 'today' => '2026-05-10', 'min_parser_version' => 1])
        ->and($access['writable_cities'])->toHaveCount(2);

    $store = $this->postJson("/api/v1/characters/{$officer->id}/customs-reports", ['city_id' => $this->city->id, 'sections' => ['guet' => customsGuet(), 'armies_groups' => customsArmies()]])
        ->assertCreated();
    expect($store->json('mode'))->toBe('created');

    $read = $this->getJson("/api/v1/characters/{$officer->id}/customs-reports?city_id={$this->city->id}&date=2026-05-10")->assertOk();
    expect($read->json('days'))->toBe(['2026-05-10'])->and($read->json('report.by_me'))->toBeTrue()
        ->and(json_encode($read->json()))->not->toContain('"raw"');

    $other = mandatePlayer($this->map['cityA']);
    $this->getJson("/api/v1/characters/{$other->id}/customs-access")->assertNotFound();
});

test('l\'API : un second bilan du jour est refusé avec les deux langues ; un joueur sans rôle ne lit rien', function () {
    $first = ($this->officer)('Premier');
    $second = ($this->officer)('Second');
    $this->reports->record($first, $this->city, ['guet' => customsGuet()]);

    Passport::actingAs($second->user);
    $refused = $this->postJson("/api/v1/characters/{$second->id}/customs-reports", ['city_id' => $this->city->id, 'sections' => ['guet' => customsGuet()]])->assertUnprocessable();
    expect($refused->json('codes.city'))->toBe('report_exists')->and($refused->json('messages.city'))->toHaveKeys(['fr', 'en']);

    $nobody = mandatePlayer($this->map['cityA']);
    Passport::actingAs($nobody->user);
    $this->getJson("/api/v1/characters/{$nobody->id}/customs-reports?city_id={$this->city->id}")->assertForbidden()->assertJsonPath('code', 'reports_not_readable');
    expect($this->getJson("/api/v1/characters/{$nobody->id}/customs-access")->json('access'))->toBeNull();
});

test('l\'API valide strictement la FORME : pseudo avec un espace, plafonds, type de groupe inconnu', function () {
    $officer = ($this->officer)();
    Passport::actingAs($officer->user);
    $url = "/api/v1/characters/{$officer->id}/customs-reports";

    $spaced = customsGuet(['Deux mots']);
    $this->postJson($url, ['city_id' => $this->city->id, 'sections' => ['guet' => $spaced]])->assertUnprocessable()->assertJsonValidationErrors('sections.guet.report.villagers.0.pseudo');

    $badType = customsArmies();
    $badType['report']['groups'][0]['type'] = 'brigands';
    $this->postJson($url, ['city_id' => $this->city->id, 'sections' => ['guet' => customsGuet(), 'armies_groups' => $badType]])->assertUnprocessable()
        ->assertJsonValidationErrors('sections.armies_groups.report.groups.0.type');

    $tooMany = customsGuet(array_map(fn ($i) => "P$i", range(1, 1001)));
    $this->postJson($url, ['city_id' => $this->city->id, 'sections' => ['guet' => $tooMany]])->assertUnprocessable();

    $this->postJson($url, ['city_id' => $this->city->id, 'sections' => []])->assertUnprocessable();
    expect(CustomsReport::count())->toBe(0);
});

// ─── Export art. 20 ────────────────────────────────────────────────────────────────────────

test('l\'export ne porte qu\'une TRACE d\'activité sans contenu : jamais un pseudo, une raison ni un texte de bilan', function () {
    $prevot = ($this->seat)('prevot_des_marechaux');
    app(CustomsWatchlist::class)->add($prevot, 'Pseudo_de_tiers_unique', 'red', 'Raison_unique');
    $this->reports->record($prevot, $this->city, ['guet' => customsGuet(['Villageois_unique']), 'visions' => customsVisions()]);

    $export = app(AccountExport::class)->build($prevot->user);
    $json = json_encode($export);

    expect($export['customs_activity'])->toHaveCount(2)
        ->and(collect($export['customs_activity'])->pluck('type')->sort()->values()->all())->toBe(['list_entry_added', 'report_entered'])
        ->and(collect($export['customs_activity'])->firstWhere('type', 'report_entered'))->toMatchArray(['day' => '2026-05-10', 'city' => 'Arras', 'sections' => ['guet', 'visions']])
        ->and(collect($export['customs_activity'])->firstWhere('type', 'list_entry_added')['colour'])->toBe('red')
        ->and($json)->not->toContain('Pseudo_de_tiers_unique')->and($json)->not->toContain('Raison_unique')->and($json)->not->toContain('Villageois_unique');
});

test('une entrée retirée ne laisse aucune trace dans l\'export', function () {
    $prevot = ($this->seat)('prevot_des_marechaux');
    $entry = app(CustomsWatchlist::class)->add($prevot, 'Retiree', 'red', null);
    app(CustomsWatchlist::class)->remove($prevot, $entry->id);

    expect(app(AccountExport::class)->build($prevot->user)['customs_activity'])->toBe([]);
});

test('une case vidée RETIRE la section du relevé du jour (Greg, 24/11/2026) : l\'auteur ou le trio ; jamais le guet ; l\'ancienne version reste en archive', function () {
    $officer = ($this->officer)();
    $first = $this->reports->record($officer, $this->city, ['guet' => customsGuet(), 'visions' => customsVisions(), 'armies_groups' => customsArmies()])['report'];

    $result = $this->reports->record($officer, $this->city, [], null, ['visions']); // des visions inscrites par mégarde

    expect($result['mode'])->toBe('corrected')
        ->and(array_keys($result['report']->payload['sections']))->toBe(['guet', 'armies_groups'])
        ->and($first->refresh()->active)->toBeNull()
        ->and(array_keys($first->payload['sections']))->toBe(['guet', 'visions', 'armies_groups']) // l'archive garde tout
        ->and(CustomsReport::where('active', true)->count())->toBe(1);

    // Retirer et envoyer en même temps : l'envoi l'emporte ; le guet ne se retire pas ; rien à changer est refusé.
    $with = $this->reports->record($officer, $this->city, ['visions' => customsVisions()], null, ['visions', 'guet']);
    expect(array_keys($with['report']->payload['sections']))->toBe(['guet', 'armies_groups', 'visions']);
    expect(fn () => $this->reports->record($officer, $this->city, [], null, ['guet']))->toThrow(MandateRefusal::class)
        ->and(fn () => $this->reports->record($officer, $this->city, [], null, ['visions', 'armies_groups']))->not->toThrow(MandateRefusal::class)
        ->and(fn () => $this->reports->record($officer, $this->city, [], null, ['visions']))->toThrow(MandateRefusal::class); // déjà retirée
});

test('retirer une section : pas le bilan d\'un autre, pas un jour passé (sauf le trio), pas sans relevé', function () {
    $officer = ($this->officer)();
    $other = ($this->officer)('Autre');

    expect(fn () => $this->reports->record($officer, $this->city, [], null, ['visions']))->toThrow(MandateRefusal::class); // aucun relevé : rien à retirer, et rien à créer sans guet

    $this->reports->record($officer, $this->city, ['guet' => customsGuet(), 'visions' => customsVisions()]);
    expect(fn () => $this->reports->record($other, $this->city, [], null, ['visions']))->toThrow(MandateRefusal::class)
        ->and(fn () => $this->reports->record($officer, $this->city, [], '2026-05-09', ['visions']))->toThrow(MandateRefusal::class)
        ->and(array_keys(CustomsReport::where('active', true)->first()->payload['sections']))->toBe(['guet', 'visions']);

    $prevot = ($this->seat)('prevot_des_marechaux');
    expect($this->reports->record($prevot, $this->city, [], null, ['visions'])['mode'])->toBe('corrected');
});

test('l\'API : remove_sections retire une section ; rien à envoyer ni à retirer est refusé ; seules visions et armées se retirent ; le lendemain d\'un jour passé, le douanier n\'y touche pas', function () {
    $officer = ($this->officer)('Douanier');
    Passport::actingAs($officer->user);
    $url = "/api/v1/characters/{$officer->id}/customs-reports";
    $this->postJson($url, ['city_id' => $this->city->id, 'sections' => ['guet' => customsGuet(), 'visions' => customsVisions()]])->assertCreated();

    $this->postJson($url, ['city_id' => $this->city->id])->assertUnprocessable(); // ni section ni retrait
    $this->postJson($url, ['city_id' => $this->city->id, 'remove_sections' => ['guet']])->assertUnprocessable(); // le guet ne se retire pas
    $done = $this->postJson($url, ['city_id' => $this->city->id, 'remove_sections' => ['visions']])->assertCreated();

    expect($done->json('mode'))->toBe('corrected')
        ->and($this->getJson("/api/v1/characters/{$officer->id}/customs-reports?city_id={$this->city->id}&date=2026-05-10")->json('report.sections_present'))->toBe(['guet'])
        ->and($this->getJson("/api/v1/characters/{$officer->id}/customs-reports?city_id={$this->city->id}&date=2026-05-10")->json('report.can_correct'))->toBeTrue(); // l'auteur, le jour même
});
