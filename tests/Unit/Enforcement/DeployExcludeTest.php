<?php

// Garde-fou — liste d'exclusion du déploiement FTP (.github/workflows/deploy.yml, 29/09/2026).
//
// Le danger d'une liste d'exclusion n'est pas l'oubli d'un fichier inutile (de l'encombrement,
// hors racine web), c'est l'exclusion d'un fichier dont le serveur a besoin : le déploiement
// passerait au vert et la panne n'apparaîtrait qu'au prochain `composer install` ou `artisan`
// lancé en SSH. Une vérification post-déploiement par URL ne verrait rien : tout ce qui est
// hors de public/ répond 404, présent ou non.

function deployExcludePatterns(): array
{
    // Pas de base_path() : tests/Unit n'a pas le TestCase Laravel (voir tests/Pest.php).
    $workflow = file(dirname(__DIR__, 3).'/.github/workflows/deploy.yml', FILE_IGNORE_NEW_LINES);

    $patterns = [];
    $inExclude = false;

    foreach ($workflow as $line) {
        if (preg_match('/^\s*exclude:\s*\|\s*$/', $line)) {
            $inExclude = true;

            continue;
        }

        if (! $inExclude) {
            continue;
        }

        $trimmed = trim($line);

        // Le bloc littéral s'arrête à la première ligne vide.
        if ($trimmed === '') {
            break;
        }

        if (! str_starts_with($trimmed, '#')) {
            $patterns[] = $trimmed;
        }
    }

    return $patterns;
}

// Motif glob du déploiement → expression régulière (** traverse les dossiers, * non).
function deployPatternMatches(string $pattern, string $path): bool
{
    $regex = strtr(preg_quote($pattern, '#'), ['\*\*/' => '(.*/)?', '\*\*' => '.*', '\*' => '[^/]*']);

    return (bool) preg_match('#^'.$regex.'$#', $path);
}

test('la liste d\'exclusion est lue', function () {
    expect(deployExcludePatterns())->toContain('.env', 'tests/**');
});

test('n\'exclut jamais ce dont le serveur a besoin', function (string $path) {
    $matching = array_filter(deployExcludePatterns(), fn (string $pattern) => deployPatternMatches($pattern, $path));

    expect($matching)->toBe([]);
})->with([
    'composer.json',   // composer install --no-dev sur le serveur
    'composer.lock',   // idem : versions exactes
    'artisan',         // migrate, config:cache en SSH
    'public/index.php',
    'public/.htaccess',
    'public/build/manifest.json',
    'public/vendor/sweetalert/sweetalert.all.js', // ressources publiées, pas le vendor Composer
    'resources/views/dashboard.blade.php',
    'bootstrap/app.php',
    'config/app.php',
    'routes/api.php',
    'lang/fr.json',
    'database/migrations/x.php',
]);

test('exclut l\'outillage et la documentation', function (string $path) {
    $matching = array_filter(deployExcludePatterns(), fn (string $pattern) => deployPatternMatches($pattern, $path));

    expect($matching)->not->toBe([]);
})->with([
    '.env',
    '.env.example',
    '.env.testing',
    'CHANGELOG.md',
    'README.md',
    'docs/ARCHITECTURE.md',
    'phpunit.xml',
    'package.json',
    'vite.config.js',
    'tests/Pest.php',
    'vendor/autoload.php', // construit sur le serveur par composer install --no-dev
]);
