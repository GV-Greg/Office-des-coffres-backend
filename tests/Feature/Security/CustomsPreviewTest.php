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
| Relevé unique (PR 3-2 ; fil admin/echanges/module-securite 12 à 14) : `customs-preview` donne la couleur des pseudos collés (droit de LIRE les
| listes, Q4) et le guet du dernier bilan de la ville (droit de lire les bilans, Q3) — SANS rien enregistrer. Seuls les pseudos envoyés sont
| servis, jamais la liste elle-même.
*/

function previewGuet(array $pseudos): array
{
    return ['raw' => 'Liste des villageois ('.count($pseudos).")\n".implode("\n", array_map(fn ($p) => "$p\t255PR", $pseudos)), 'parser_version' => 1, 'report' => [
        'villagers' => array_map(fn ($p) => ['pseudo' => $p, 'noblesse' => '', 'reputation' => 255], $pseudos),
        'declared_total' => count($pseudos),
    ]];
}

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    $this->province = $this->map['provinceA'];
    $this->city = $this->map['cityA'];
    $this->reports = app(CustomsReports::class);
    $this->watchlist = app(CustomsWatchlist::class);
    $this->seat = function (string $office, string $pseudo): Character {
        $character = mandatePlayer($this->map['cityB'], ['pseudo' => $pseudo]);
        atGameTime('2026-05-03 12:00');
        approvedCouncil($character, $this->province, '2026-05-01', '2026-05-03', $office);
        atGameTime('2026-05-10 12:00');

        return $character;
    };
    $this->officer = function (string $pseudo): Character {
        $character = mandatePlayer($this->map['cityA'], ['pseudo' => $pseudo]);
        $officers = app(CustomsOfficers::class);
        $officers->decideAsAdmin($officers->request($character, $this->map['cityA']), true);

        return $character;
    };
    atGameTime('2026-05-10 12:00');
});

afterEach(fn () => Carbon::setTestNow());

test('les couleurs : seuls les pseudos ENVOYÉS reviennent, comparés sans tenir compte de la casse, avec la date d\'ajout', function () {
    $prevot = ($this->seat)('prevot_des_marechaux', 'Prevot');
    $this->watchlist->add($prevot, 'gregor.', 'red', 'brigandage');
    $this->watchlist->add($prevot, 'ARTIFICE', 'green', null);
    $this->watchlist->add($prevot, 'Jamais_envoye', 'red', null);
    Passport::actingAs($prevot->user);

    $response = $this->postJson("/api/v1/characters/{$prevot->id}/customs-preview", ['pseudos' => ['Gregor.', 'Artifice', 'Inconnu']])->assertOk();

    expect($response->json('today'))->toBe('2026-05-10')
        ->and($response->json('colours'))->toBe([['pseudo' => 'Gregor.', 'colour' => 'red'], ['pseudo' => 'Artifice', 'colour' => 'green']])
        ->and($response->json('previous'))->toBeNull()
        ->and(json_encode($response->json()))->not->toContain('Jamais_envoye')->not->toContain('brigandage');
});

test('une entrée ajoutée DEMAIN ne colore rien aujourd\'hui (couleur à partir de la date d\'ajout)', function () {
    $prevot = ($this->seat)('prevot_des_marechaux', 'Prevot');
    $this->watchlist->add($prevot, 'Gregor.', 'red', null);
    atGameTime('2026-05-09 12:00');
    Passport::actingAs($prevot->user);

    $this->postJson("/api/v1/characters/{$prevot->id}/customs-preview", ['pseudos' => ['Gregor.']])->assertOk()
        ->assertJsonPath('colours', []);
});

test('le guet précédent : le dernier bilan de la ville ANTÉRIEUR à aujourd\'hui, avec sa date ; jamais celui d\'aujourd\'hui', function () {
    $officer = ($this->officer)('Douanier');
    atGameTime('2026-05-08 12:00');
    $this->reports->record($officer, $this->city, ['guet' => previewGuet(['Ancien'])]);
    atGameTime('2026-05-09 12:00');
    $this->reports->record($officer, $this->city, ['guet' => previewGuet(['Hier_a', 'Hier_b'])]);
    atGameTime('2026-05-10 12:00');
    $this->reports->record($officer, $this->city, ['guet' => previewGuet(['Aujourdhui'])]);
    Passport::actingAs($officer->user);

    $response = $this->postJson("/api/v1/characters/{$officer->id}/customs-preview", ['city_id' => $this->city->id, 'pseudos' => ['Hier_a']])->assertOk();

    expect($response->json('previous'))->toBe(['compared_to' => '2026-05-09', 'pseudos' => ['Hier_a', 'Hier_b']]);
});

test('RIEN n\'est écrit : ni bilan, ni entrée de liste, aucune ligne en base', function () {
    $prevot = ($this->seat)('prevot_des_marechaux', 'Prevot');
    Passport::actingAs($prevot->user);
    $before = [CustomsReport::count(), DB::table('customs_watchlist_entries')->count(), CustomsOfficer::count()];

    $this->postJson("/api/v1/characters/{$prevot->id}/customs-preview", ['city_id' => $this->city->id, 'pseudos' => ['Gregor.', 'Artifice']])->assertOk();

    expect([CustomsReport::count(), DB::table('customs_watchlist_entries')->count(), CustomsOfficer::count()])->toBe($before);
});

test('une ville que ce rôle ne lit pas est refusée dans les deux langues ; un joueur sans rôle ne reçoit rien', function () {
    $mayor = mandatePlayer($this->map['cityA'], ['pseudo' => 'Maire']);
    approvedMayor($mayor, $this->map['cityA'], '2026-05-01');
    Passport::actingAs($mayor->user);

    // Le maire lit sa ville seulement : la ville voisine est refusée.
    $refused = $this->postJson("/api/v1/characters/{$mayor->id}/customs-preview", ['city_id' => $this->map['cityB']->id, 'pseudos' => ['X']])->assertUnprocessable();
    expect($refused->json('messages.city_id'))->toHaveKeys(['fr', 'en'])->and($refused->json('codes.city_id'))->toBe('reports_not_readable');
    $this->postJson("/api/v1/characters/{$mayor->id}/customs-preview", ['city_id' => $this->city->id, 'pseudos' => ['X']])->assertOk();

    $nobody = mandatePlayer($this->map['cityA'], ['pseudo' => 'Simple']);
    Passport::actingAs($nobody->user);
    $this->postJson("/api/v1/characters/{$nobody->id}/customs-preview", ['pseudos' => ['X']])->assertUnprocessable()
        ->assertJsonStructure(['messages' => ['character' => ['fr', 'en']]]);
});

test('401 sans jeton, 404 pour le personnage d\'un autre compte, forme validée (pseudo sans espace, plafond)', function () {
    $prevot = ($this->seat)('prevot_des_marechaux', 'Prevot');
    $this->postJson("/api/v1/characters/{$prevot->id}/customs-preview", ['pseudos' => []])->assertUnauthorized();

    Passport::actingAs($prevot->user);
    $other = mandatePlayer($this->map['cityA']);
    $this->postJson("/api/v1/characters/{$other->id}/customs-preview", ['pseudos' => []])->assertNotFound();

    $this->postJson("/api/v1/characters/{$prevot->id}/customs-preview", ['pseudos' => ['deux mots']])->assertUnprocessable();
    $this->postJson("/api/v1/characters/{$prevot->id}/customs-preview", ['pseudos' => array_map(fn ($i) => "P$i", range(1, 2001))])->assertUnprocessable();
    $this->postJson("/api/v1/characters/{$prevot->id}/customs-preview", [])->assertUnprocessable();
});
