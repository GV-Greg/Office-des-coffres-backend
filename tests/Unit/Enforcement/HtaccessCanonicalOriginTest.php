<?php

// Garde-fou — origine canonique du panneau admin et de l'API (29/09/2026).
//
// Les quatre variantes (http/https × avec/sans www) servaient toutes l'application, dont la page
// de connexion admin en clair. Ce test ne remplace pas la vérification en prod (étape « origine
// canonique » de .github/workflows/deploy.yml, seule à voir le vrai serveur) ; il attrape avant
// le merge les erreurs de rédaction de la règle.

function htaccessDirectives(): array
{
    // Pas de public_path() : tests/Unit n'a pas le TestCase Laravel (voir tests/Pest.php).
    $lines = file(dirname(__DIR__, 3).'/public/.htaccess', FILE_IGNORE_NEW_LINES);

    return array_values(array_filter(
        array_map('trim', $lines),
        fn (string $line) => $line !== '' && ! str_starts_with($line, '#'),
    ));
}

function canonicalRedirectIndex(array $directives): int|false
{
    foreach ($directives as $index => $line) {
        if (preg_match('/^RewriteRule .*\[R=30[12],L\]$/', $line)) {
            return $index;
        }
    }

    return false;
}

test('redirige vers https://odc-admin.creacube.be en gardant le chemin', function () {
    $directives = htaccessDirectives();
    $index = canonicalRedirectIndex($directives);

    expect($index)->not->toBeFalse()
        ->and($directives[$index])->toMatch('/^RewriteRule \^\(\.\*\)\$ https:\/\/odc-admin\.creacube\.be\/\$1 \[R=30[12],L\]$/');
});

// Les conditions qui précèdent la règle, sans le [OR] mal placé qui redirigerait tout ou rien.
test('redirige sur le schéma http OU un autre hôte, jamais /.well-known/', function () {
    $directives = htaccessDirectives();
    $index = canonicalRedirectIndex($directives);

    expect($index)->not->toBeFalse();
    expect(array_slice($directives, $index - 3, 3))->toBe([
        'RewriteCond %{REQUEST_URI} !^/\.well-known/',
        'RewriteCond %{HTTPS} !=on [OR]',
        'RewriteCond %{HTTP_HOST} !^odc-admin\.creacube\.be$ [NC]',
    ]);
});

// Après la redirection des slashs finaux (une 301), celle-ci poserait en cache une redirection
// permanente restée en http:// ; après le front controller (qui s'arrête sur [L]), la règle ne
// s'appliquerait plus à aucune route.
test('passe avant la redirection des slashs finaux et le front controller', function () {
    $directives = htaccessDirectives();
    $index = canonicalRedirectIndex($directives);

    $trailingSlash = array_search('RewriteRule ^ %1 [L,R=301]', $directives, true);
    $frontController = array_search('RewriteRule ^ index.php [L]', $directives, true);

    expect($index)->not->toBeFalse()
        ->and($trailingSlash)->toBeGreaterThan($index)
        ->and($frontController)->toBeGreaterThan($index);
});

// Irrévocable chez les clients : décision séparée, jamais « tant qu'on y est ».
test('ne pose pas de HSTS', function () {
    $hsts = array_filter(htaccessDirectives(), fn (string $line) => stripos($line, 'Strict-Transport-Security') !== false);

    expect($hsts)->toBe([]);
});
