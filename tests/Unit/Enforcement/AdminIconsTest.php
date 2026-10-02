<?php

// Garde-fou — les icônes du panneau d'administration passent par le JS de Font Awesome (02/10/2026).
//
// Les feuilles SCSS de Font Awesome déclaraient des @font-face vers build/webfonts/, que Vite ne
// copie pas : deux 404 par page admin en prod, icônes affichées malgré tout par le JS (SVG en
// ligne). Réimporter le SCSS ramènerait les 404 sans rien montrer de plus ; retirer le JS ferait
// disparaître toutes les icônes.

$root = dirname(__DIR__, 3);

test('le SCSS de l\'admin n\'importe pas les feuilles de Font Awesome', function () use ($root) {
    expect(file_get_contents($root.'/resources/sass/app.scss'))
        ->not->toMatch('/@(import|use)\s+[\'"]@fortawesome\//');
});

test('le JS de l\'admin charge Font Awesome en SVG', function () use ($root) {
    expect(file_get_contents($root.'/resources/js/app.js'))
        ->toContain("import '@fortawesome/fontawesome-free/js/all.min';");
});
