<?php

use App\Support\AnimSlug;

/*
| Règles du slug des activités du module Animation (D14, D26) : lettres et tirets, aucun chiffre, 3 à 20 caractères, aucun
| mot réservé. Source unique des règles — le site demande l'aperçu au serveur.
*/

it('propose un slug en minuscules, sans accent, avec des tirets', function () {
    expect(AnimSlug::propose('Les Trois Coffres'))->toBe('les-trois-coffres')
        ->and(AnimSlug::propose('Été d\'Or'))->toBe('ete-d-or')
        ->and(AnimSlug::propose('Œuvre à Çà et là'))->toBe('oeuvre-a-ca-et-la');
});

it('retire les chiffres de la proposition (D26)', function () {
    expect(AnimSlug::propose('Fête 2026'))->toBe('fete')
        ->and(AnimSlug::propose('Coffre 3 des Marches'))->toBe('coffre-des-marches')
        ->and(AnimSlug::propose('2026'))->toBe('');
});

it('ne laisse ni tiret au début, ni à la fin, ni doublé', function () {
    expect(AnimSlug::propose('  --Les   ::   Coffres--  '))->toBe('les-coffres');
});

it('coupe à 20 caractères, de préférence à un tiret', function () {
    $slug = AnimSlug::propose('Le grand tournoi des coffres scellés');

    expect($slug)->toBe('le-grand-tournoi-des')->and(strlen($slug))->toBe(20);
});

it('coupe net à 20 quand un seul mot dépasse', function () {
    expect(AnimSlug::propose('abcdefghijklmnopqrstuvwxyz'))->toBe('abcdefghijklmnopqrst');
});

it('accepte 3 à 20 lettres et tirets au milieu', function () {
    expect(AnimSlug::isValid('abc'))->toBeTrue()
        ->and(AnimSlug::isValid('les-trois-coffres'))->toBeTrue()
        ->and(AnimSlug::isValid(str_repeat('a', 20)))->toBeTrue();
});

it('refuse trop court, trop long, chiffre, majuscule, tiret mal placé ou doublé', function (string $slug) {
    expect(AnimSlug::isValid($slug))->toBeFalse();
})->with(['ab', 'a', '', 'a1b', 'Abc', '-abc', 'abc-', 'a--bc', 'a b c', 'é-é-é']);

it('refuse 21 lettres', function () {
    expect(AnimSlug::isValid(str_repeat('a', 21)))->toBeFalse();
});

it('n\'a aucun mot réservé (D14) : « nouvelle » ou « access » sont des slugs comme les autres', function () {
    expect(AnimSlug::isValid('nouvelle'))->toBeTrue()->and(AnimSlug::isValid('access'))->toBeTrue()->and(AnimSlug::isValid('mes-activites'))->toBeTrue();
});
