<?php

use App\Support\CustomsPseudo;

/*
| Comparaison des pseudos du module Sécurité (Greg, 22/11/2026, C4) : en minuscules pour comparer et colorer, la casse collée est
| conservée à l'affichage. Les parseurs des collages vivent au FRONTEND (décision de Greg du 22/11/2026, comme les mines).
*/

test('la comparaison des pseudos se fait en minuscules, l\'affichage garde la casse collée', function () {
    expect(CustomsPseudo::key('Gregor.'))->toBe('gregor.')
        ->and(CustomsPseudo::inList('GREGOR.', ['Gregor.', 'Artifice']))->toBeTrue()
        ->and(CustomsPseudo::inList('Gregor', ['Gregor.']))->toBeFalse() // le point fait partie du pseudo
        ->and(CustomsPseudo::inList('Élodie', ['élodie']))->toBeTrue();
});
