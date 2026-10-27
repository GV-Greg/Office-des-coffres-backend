<?php

use App\Services\DecodeScoring;

// Décode le code — l'algorithme tient seul, avant tout le reste (brief, lot 1).

it('donne 10 à la combinaison trouvée, enregistrée 20', function () {
    $score = DecodeScoring::score([1, 2, 3, 4, 5], [1, 2, 3, 4, 5]);

    expect($score)->toBe(10)
        ->and(DecodeScoring::isFound($score))->toBeTrue()
        ->and(DecodeScoring::recorded($score))->toBe(20);
});

it('ne donne rien quand aucun chiffre ne correspond', function () {
    expect(DecodeScoring::score([1, 2, 3, 4, 5], [6, 7, 8, 9, 0]))->toBe(0);
});

it('vaut 1 par chiffre présent mais mal placé', function () {
    // Les cinq chiffres existent, aucun n'est à sa place.
    expect(DecodeScoring::score([1, 2, 3, 4, 5], [2, 3, 4, 5, 1]))->toBe(5);
});

it('mélange positions exactes (2) et chiffres mal placés (1)', function () {
    // 1 et 2 exacts (4), 4 et 3 présents ailleurs (2), 9 absent.
    expect(DecodeScoring::score([1, 2, 3, 4, 5], [1, 2, 4, 3, 9]))->toBe(6);
});

it('respecte les occurrences quand le secret répète un chiffre', function () {
    // Secret avec deux 1 : une proposition à trois 1 n'en peut compter que deux.
    expect(DecodeScoring::score([1, 1, 2, 3, 4], [5, 5, 1, 1, 1]))->toBe(2);
});

it('respecte les occurrences quand la proposition répète un chiffre', function () {
    // Un seul 1 dans le secret : les quatre autres 1 ne rapportent rien.
    expect(DecodeScoring::score([1, 2, 3, 4, 5], [6, 1, 1, 1, 1]))->toBe(1);
});

it('ne compte pas deux fois un chiffre déjà consommé par une position exacte', function () {
    // Le 1 du secret est pris par la position exacte (2) : le second 1 de la proposition vaut 0.
    expect(DecodeScoring::score([1, 2, 3, 4, 5], [1, 6, 7, 8, 1]))->toBe(2);
});

it('plafonne à 10', function () {
    expect(DecodeScoring::score([7, 7, 7, 7, 7], [7, 7, 7, 7, 7]))->toBe(10);
});

it('ne déclare trouvée que la combinaison exacte', function () {
    expect(DecodeScoring::isFound(DecodeScoring::score([1, 2, 3, 4, 5], [2, 1, 3, 4, 5])))->toBeFalse();
    expect(DecodeScoring::recorded(8))->toBe(8);
});

it('lit exactement cinq chiffres', function () {
    expect(DecodeScoring::digits('01234'))->toBe([0, 1, 2, 3, 4])
        ->and(DecodeScoring::digits('1234'))->toBeNull()
        ->and(DecodeScoring::digits('123456'))->toBeNull()
        ->and(DecodeScoring::digits('12a45'))->toBeNull();
});
