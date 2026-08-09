<?php

use Symfony\Component\Finder\Finder;

// Garde-fou de admin/strategies/cookies.md §9 : le backend ne stocke rien côté cookie HTTP ou
// session applicative (voir backend/docs/ARCHITECTURE.md, "Cookies/consentement : aucun
// stockage backend"). Ce test échoue si un futur ajout introduit un usage direct sans passer
// par une revue explicite de cette liste.

// `Cookie::`/`setcookie(` : aucun usage légitime actuellement, zéro exception.
$forbiddenAnywhere = ['Cookie::', 'setcookie('];

// `Session::`/`session(` : légitime dans les contrôleurs Auth/Profile Breeze du panneau admin
// (guard `web`, session-based par nature — voir backend/docs/ARCHITECTURE.md). Un usage
// ailleurs (ex. un contrôleur API, censé rester stateless par token Passport) serait inattendu.
$forbiddenElsewhere = ['Session::', 'session('];
$sessionAllowedFiles = [
    'Http/Controllers/Auth/AuthenticatedSessionController.php',
    'Http/Controllers/Auth/ConfirmablePasswordController.php',
    'Http/Controllers/ProfileController.php',
];

test('aucun usage direct de cookie/session hors des contrôleurs Auth/Profile prévus', function () use ($forbiddenAnywhere, $forbiddenElsewhere, $sessionAllowedFiles) {
    // Pas de app_path()/app() : tests/Unit n'a pas le TestCase Laravel (voir tests/Pest.php,
    // uses(TestCase::class)->in('Feature') seulement) — le conteneur n'est pas démarré ici,
    // volontairement, pour un test de pur parcours de fichiers sans dépendance au framework.
    $appDir = dirname(__DIR__, 3).'/app';

    $finder = new Finder;
    $finder->files()->in($appDir)->name('*.php');

    $violations = [];

    foreach ($finder as $file) {
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
        $content = $file->getContents();

        foreach ($forbiddenAnywhere as $pattern) {
            if (str_contains($content, $pattern)) {
                $violations[] = "{$relativePath} — {$pattern}";
            }
        }

        if (in_array($relativePath, $sessionAllowedFiles, true)) {
            continue;
        }

        foreach ($forbiddenElsewhere as $pattern) {
            if (str_contains($content, $pattern)) {
                $violations[] = "{$relativePath} — {$pattern}";
            }
        }
    }

    expect($violations)->toBe(
        [],
        "Usage direct de cookie/session détecté hors des zones prévues :\n".implode("\n", $violations)
    );
});
