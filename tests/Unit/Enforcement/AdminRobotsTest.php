<?php

// Garde-fou — le panneau d'administration et l'API ne s'indexent pas (29/09/2026).
//
// public/robots.txt était celui de Laravel : `Disallow:` vide, qui autorise tout. Le site public
// (frontend) a son propre robots.txt ; celui-ci ne couvre que odc-admin.creacube.be.

test('robots.txt interdit toute indexation', function () {
    // Pas de public_path() : tests/Unit n'a pas le TestCase Laravel (voir tests/Pest.php).
    $robots = file_get_contents(dirname(__DIR__, 3).'/public/robots.txt');

    expect($robots)->toMatch('/^User-agent: \*$/m')
        ->and($robots)->toMatch('/^Disallow: \/$/m');
});

test('favicon.ico n\'est pas vide', function () {
    expect(filesize(dirname(__DIR__, 3).'/public/favicon.ico'))->toBeGreaterThan(0);
});
