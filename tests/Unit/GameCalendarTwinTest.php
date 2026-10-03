<?php

use App\Support\GameCalendar;

/*
 * ⚠️ TEST JUMEAU. Le même test existe dans le dépôt frontend
 * (`tests/common/gameCalendarTwin.unit.test.js`), sur `src/modules/gameCalendar.js`.
 * Il fige la table d'ancrages ET des conversions calculées : une divergence de donnée OU de
 * logique entre les deux copies échoue dans le dépôt où elle a été introduite
 * (fil admin/echanges/mandats-historique, 10).
 *
 * Si ce test échoue parce que vous avez VOLONTAIREMENT changé le calendrier : faites le même
 * changement dans le jumeau frontend, et mettez à jour les deux tests ensemble.
 */
const TWIN = 'Calendrier du jeu modifié : modifiez AUSSI le jumeau frontend src/modules/gameCalendar.js et son test gameCalendarTwin.unit.test.js.';

test('la table d\'ancrages est celle du jumeau frontend', function () {
    expect(GameCalendar::YEAR_ANCHORS)->toBe([
        ['real' => 2025, 'game' => 1473],
        ['real' => 2026, 'game' => 1474],
    ], TWIN)->and(GameCalendar::REAL_YEAR_FLOOR)->toBe(1900, TWIN);
});

test('les conversions calculées sont celles du jumeau frontend', function () {
    // Mêmes valeurs, dans le même ordre, que le test jumeau.
    $gameYears = array_map(fn (int $y) => GameCalendar::gameYear($y), [2020, 2025, 2026, 2027, 2040]);
    $realYears = array_map(fn (int $y) => GameCalendar::realYear($y), [1468, 1473, 1474, 1475, 2026]);

    expect($gameYears)->toBe([1468, 1473, 1474, 1475, 1488], TWIN)
        ->and($realYears)->toBe([2020, 2025, 2026, 2027, 2026], TWIN);
});
