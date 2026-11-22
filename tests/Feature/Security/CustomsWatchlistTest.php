<?php

use App\Exceptions\MandateRefusal;
use App\Models\Character;
use App\Models\CustomsWatchlistEntry;
use App\Services\AccountDeletion;
use App\Services\CustomsOfficers;
use App\Services\CustomsWatchlist;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

/*
| Listes rouge et verte d'une province (module Sécurité, PR 3-1 ; décisions de Greg du 21 et 22/11/2026) : le trio écrit, maire et douanier
| seulement sans trio connu, le juge n'ajoute qu'au rouge, l'auteur retire sa propre entrée, un retrait SUPPRIME la ligne, « ajouté par »
| visible du trio seulement, pseudo et raison chiffrés.
*/

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    $this->province = $this->map['provinceA'];
    $this->watchlist = app(CustomsWatchlist::class);
    $this->seat = function (string $office, ?string $pseudo = null): Character {
        $character = mandatePlayer($this->map['cityB'], ['pseudo' => $pseudo ?? ucfirst($office)]);
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
    atGameTime('2026-05-10 12:00');
});

afterEach(fn () => Carbon::setTestNow());

test('le trio ajoute, modifie et retire ; l\'ajout date du jour du jeu ; pseudo et raison sont CHIFFRÉS en base', function () {
    $prevot = ($this->seat)('prevot_des_marechaux');

    $entry = $this->watchlist->add($prevot, 'Brigand_secret_unique', 'red', 'trouble à l\'ordre');
    $stored = (string) DB::table('customs_watchlist_entries')->value('payload');

    expect($entry->colour)->toBe('red')->and($entry->added_on->toDateString())->toBe('2026-05-10')
        ->and($entry->pseudo())->toBe('Brigand_secret_unique')->and($entry->reason())->toBe('trouble à l\'ordre')
        ->and($stored)->not->toContain('Brigand_secret_unique')->and($stored)->not->toContain('trouble');

    $this->watchlist->update($prevot, $entry->id, ['reason' => null]);
    expect($entry->refresh()->reason())->toBeNull();

    $this->watchlist->remove($prevot, $entry->id);
    expect(CustomsWatchlistEntry::count())->toBe(0); // suppression réelle : aucune trace (choix B)
});

test('changer de couleur repart d\'une NOUVELLE date d\'ajout', function () {
    $prevot = ($this->seat)('prevot_des_marechaux');
    $entry = $this->watchlist->add($prevot, 'Pseudo_a', 'green', null);

    atGameTime('2026-05-12 10:00');
    $this->watchlist->update($prevot, $entry->id, ['colour' => 'red']);

    expect($entry->refresh())->colour->toBe('red')->and($entry->added_on->toDateString())->toBe('2026-05-12');
});

test('un pseudo n\'entre qu\'une fois dans les listes d\'une province, casse ignorée, quelle que soit la couleur', function () {
    $prevot = ($this->seat)('prevot_des_marechaux');
    $this->watchlist->add($prevot, 'Gregor.', 'red', null);

    expect(fn () => $this->watchlist->add($prevot, 'GREGOR.', 'green', null))->toThrow(MandateRefusal::class)
        ->and(fn () => $this->watchlist->add($prevot, 'Gregor', 'green', null))->not->toThrow(MandateRefusal::class); // le point fait partie du pseudo
});

test('les listes sont par province : un titulaire d\'une autre province ne les lit pas', function () {
    $prevot = ($this->seat)('prevot_des_marechaux');
    $this->watchlist->add($prevot, 'Gregor.', 'red', null);

    $foreign = mandatePlayer($this->map['cityB']);
    atGameTime('2026-05-03 12:00');
    approvedCouncil($foreign, $this->map['provinceB'], '2026-05-01', '2026-05-03', 'prevot_des_marechaux');
    atGameTime('2026-05-10 12:00');

    expect($this->watchlist->list($foreign))->toBe([])
        ->and(fn () => $this->watchlist->remove($foreign, CustomsWatchlistEntry::first()->id))->toThrow(MandateRefusal::class);
});

test('le juge ajoute au rouge seulement, ne modifie rien, retire sa propre entrée et pas celles des autres', function () {
    $prevot = ($this->seat)('prevot_des_marechaux');
    $juge = ($this->seat)('juge');
    $others = $this->watchlist->add($prevot, 'Du_prevot', 'red', null);

    $mine = $this->watchlist->add($juge, 'Du_juge', 'red', 'condamné');

    expect(fn () => $this->watchlist->add($juge, 'Ami', 'green', null))->toThrow(MandateRefusal::class)
        ->and(fn () => $this->watchlist->update($juge, $mine->id, ['reason' => 'x']))->toThrow(MandateRefusal::class)
        ->and(fn () => $this->watchlist->remove($juge, $others->id))->toThrow(MandateRefusal::class);

    $this->watchlist->remove($juge, $mine->id); // l'auteur retire la sienne
    expect(CustomsWatchlistEntry::pluck('id')->all())->toBe([$others->id]);
});

test('sans trio connu, maire et douanier écrivent les listes ; avec un trio ils les lisent seulement, mais retirent leurs propres entrées', function () {
    $officer = ($this->officer)();
    $mine = $this->watchlist->add($officer, 'Repli', 'red', null);
    expect($this->watchlist->list($officer))->toHaveCount(1);

    $capitaine = ($this->seat)('capitaine');
    $theirs = $this->watchlist->add($capitaine, 'Du_trio', 'red', null);

    expect($this->watchlist->list($officer))->toHaveCount(2)
        ->and(fn () => $this->watchlist->add($officer, 'Nouveau', 'red', null))->toThrow(MandateRefusal::class)
        ->and(fn () => $this->watchlist->update($officer, $mine->id, ['reason' => 'x']))->toThrow(MandateRefusal::class)
        ->and(fn () => $this->watchlist->remove($officer, $theirs->id))->toThrow(MandateRefusal::class);

    $this->watchlist->remove($officer, $mine->id); // l'auteur retire la sienne, même avec un trio actif (P4)
    expect(CustomsWatchlistEntry::count())->toBe(1);
});

test('« ajouté par » n\'est visible que du trio ; les autres voient `by_me` sur leurs entrées et rien sur celles d\'autrui', function () {
    $prevot = ($this->seat)('prevot_des_marechaux', 'Le_prevot');
    $capitaine = ($this->seat)('capitaine', 'Le_capitaine');
    $procureur = ($this->seat)('procureur');
    $this->watchlist->add($prevot, 'Un', 'red', null);
    $this->watchlist->add($capitaine, 'Deux', 'green', null);

    $asTrio = collect($this->watchlist->list($prevot))->keyBy('pseudo');
    $asProcureur = collect($this->watchlist->list($procureur))->keyBy('pseudo');

    expect($asTrio['Un'])->toMatchArray(['added_by' => 'Le_prevot', 'by_me' => true, 'can_remove' => true])
        ->and($asTrio['Deux'])->toMatchArray(['added_by' => 'Le_capitaine', 'by_me' => false])
        ->and($asProcureur['Un']['added_by'])->toBeNull()->and($asProcureur['Un']['by_me'])->toBeFalse()
        ->and($asProcureur['Un']['can_remove'])->toBeFalse()->and($asProcureur['Un']['can_edit'])->toBeFalse()
        ->and(json_encode($asProcureur->all()))->not->toContain('Le_prevot');
});

test('à la suppression du personnage auteur, l\'entrée reste et le lien est coupé par la base', function () {
    $prevot = ($this->seat)('prevot_des_marechaux');
    $reader = ($this->seat)('procureur');
    $this->watchlist->add($prevot, 'Reste', 'red', null);

    app(AccountDeletion::class)->deleteUser($prevot->user);

    expect(CustomsWatchlistEntry::count())->toBe(1)->and(CustomsWatchlistEntry::first()->added_by_character_id)->toBeNull()
        ->and($this->watchlist->list($reader))->toHaveCount(1);
});

test('l\'API : lire, ajouter, modifier, retirer ; forme validée ; 401 sans jeton, 404 pour le personnage d\'un autre compte, deux langues au refus', function () {
    $prevot = ($this->seat)('prevot_des_marechaux');
    $procureur = ($this->seat)('procureur');

    $this->getJson("/api/v1/characters/{$prevot->id}/customs-lists")->assertUnauthorized();
    Passport::actingAs($prevot->user);

    $id = $this->postJson("/api/v1/characters/{$prevot->id}/customs-lists", ['pseudo' => 'Gregor.', 'colour' => 'red', 'reason' => 'x'])->assertCreated()->json('id');
    $this->postJson("/api/v1/characters/{$prevot->id}/customs-lists", ['pseudo' => 'Deux mots', 'colour' => 'red'])->assertUnprocessable();
    $this->postJson("/api/v1/characters/{$prevot->id}/customs-lists", ['pseudo' => 'Ok', 'colour' => 'bleu'])->assertUnprocessable();
    $this->postJson("/api/v1/characters/{$prevot->id}/customs-lists", ['pseudo' => 'gregor.', 'colour' => 'green'])->assertUnprocessable()->assertJsonPath('codes.pseudo', 'watchlist_entry_exists');

    $this->putJson("/api/v1/characters/{$prevot->id}/customs-lists/{$id}", ['colour' => 'green', 'reason' => 'pardonné'])->assertOk();
    expect($this->getJson("/api/v1/characters/{$prevot->id}/customs-lists")->json('entries.0'))->toMatchArray(['colour' => 'green', 'reason' => 'pardonné', 'pseudo' => 'Gregor.']);

    Passport::actingAs($procureur->user);
    $refused = $this->deleteJson("/api/v1/characters/{$procureur->id}/customs-lists/{$id}")->assertUnprocessable();
    expect($refused->json('codes.entry'))->toBe('lists_not_authorized')->and($refused->json('messages.entry'))->toHaveKeys(['fr', 'en']);
    $this->getJson("/api/v1/characters/{$prevot->id}/customs-lists")->assertNotFound();

    Passport::actingAs($prevot->user);
    $this->deleteJson("/api/v1/characters/{$prevot->id}/customs-lists/{$id}")->assertOk();
    expect(CustomsWatchlistEntry::count())->toBe(0);
});
