<?php

use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Garde-fou — cycle de vie des données utilisateur
|--------------------------------------------------------------------------
|
| Règle : admin/strategies/donnees-utilisateur.md (§2 et §4).
|
| Le 19/09/2026, `password_reset_tokens` a survécu à une suppression de compte en prod : une
| adresse email restait en base après un effacement art. 17. Ce n'était pas une erreur de code,
| mais un oubli — la table n'avait jamais été regardée.
|
| Ce test est **structurel** : il n'exécute aucune suppression, il énumère les tables qui portent
| un lien vers un compte et exige que chacune soit déclarée avec sa catégorie ci-dessous. Une
| future table apparaît → rouge → il faut la classer. C'est ce qui attrape l'oubli ; la régression
| sur ce qui est déjà déclaré est couverte par les tests de suppression de `Feature/Api/AuthTest`.
|
| ⚠️ Limite assumée (stratégie §4) : ce test vérifie qu'une table est *déclarée*, pas que son
| effacement *fonctionne*. L'introspection des clés étrangères diffère entre SQLite (CI) et
| MariaDB (prod), et une table MyISAM ignorerait ses contraintes sans rien signaler. Seule une
| vérification en prod attrape ça — voir admin/content/verif-prod-suppression-compte.md.
*/

// Chaque table portant un lien vers un compte, avec sa catégorie. Toute entrée ajoutée ici
// engage une décision : relire le §5 de la stratégie avant d'en poser une nouvelle.
$declaredTables = [
    // A — cascade : la base garantit la suppression, aucun code applicatif nécessaire.
    'characters' => 'A',

    // B — nettoyage explicite dans AuthController::destroyAccount() ou par un hook de paquet.
    // Ces tables ne peuvent pas porter de FK vers `users` : colonne indexée sans contrainte
    // (OAuth), clé par email, ou pivot polymorphe.
    'oauth_access_tokens' => 'B',
    'oauth_refresh_tokens' => 'B',
    'oauth_auth_codes' => 'B',
    'oauth_device_codes' => 'B',
    'password_reset_tokens' => 'B',
    'model_has_roles' => 'B',
    'model_has_permissions' => 'B',
];

// Colonnes qui trahissent un lien vers un compte. `model_id` couvre les pivots polymorphes de
// Spatie, qui ne portent ni user_id ni email.
$linkColumns = ['user_id', 'email', 'model_id'];

// `users` est le compte lui-même, pas une donnée qui lui est rattachée : sa suppression est le
// point de départ, pas une conséquence à déclarer.
$notAccountData = ['users', 'migrations'];

$categories = <<<'TXT'
    A — cascade (FK ->cascadeOnDelete, le défaut)
    B — nettoyage explicite dans destroyAccount() (ou par un hook de paquet, à vérifier)
    C — anonymisation (user_id à NULL, contenu conservé) — pour la donnée dont d'autres dépendent
    D — conservation justifiée, à condition d'être non réidentifiante
    TXT;

$howToFix = <<<TXT

    → Règle : admin/strategies/donnees-utilisateur.md
    → Les quatre catégories possibles :
    {$categories}

    Les trois questions du §5, dans l'ordre :
      1. Cette donnée intéresse-t-elle quelqu'un d'autre que son auteur ? Non → A, terminé.
      2. Peut-elle survivre sans son auteur ? Oui → C (et la table doit être conçue pour).
      3. La table peut-elle porter une FK vers `users` ? Non → B, ligne explicite dans
         destroyAccount() ET entrée dans ce test.

    Ajouter la ligne ici sans répondre à ces trois questions ne fait que désactiver le garde-fou.
    TXT;

test('toute table portant un lien vers un compte est déclarée avec sa catégorie',
    function () use ($declaredTables, $linkColumns, $notAccountData, $howToFix) {
        $linked = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if (in_array($name, $notAccountData, true) || str_starts_with($name, 'sqlite_')) {
                continue;
            }

            $columns = Schema::getColumnListing($name);

            if (array_intersect($linkColumns, $columns) !== []) {
                $linked[] = $name;
            }
        }

        $undeclared = array_values(array_diff($linked, array_keys($declaredTables)));

        expect($undeclared)->toBe([], sprintf(
            "Table(s) rattachée(s) à un compte sans catégorie déclarée : %s.\n".
            'Elles seront effacées, anonymisées ou conservées — mais il faut le décider,%s',
            implode(', ', $undeclared),
            $howToFix
        ));
    });

test('la liste déclarée ne contient pas de table disparue du schéma', function () use ($declaredTables) {
    // Le pendant du test ci-dessus : une table supprimée par une migration doit sortir de la
    // liste, sinon elle finit par couvrir un nom qui ne veut plus rien dire.
    $missing = array_values(array_filter(
        array_keys($declaredTables),
        fn (string $table) => ! Schema::hasTable($table)
    ));

    expect($missing)->toBe([], sprintf(
        'Table(s) déclarée(s) mais absente(s) du schéma : %s. Retirer ces entrées de la liste.',
        implode(', ', $missing)
    ));
});

test('chaque table déclarée porte une catégorie connue', function () use ($declaredTables, $categories) {
    $invalid = array_keys(array_filter(
        $declaredTables,
        fn (string $category) => ! in_array($category, ['A', 'B', 'C', 'D'], true)
    ));

    expect($invalid)->toBe([], sprintf(
        "Catégorie inconnue pour : %s.\nLes seules valeurs possibles sont :\n%s",
        implode(', ', $invalid),
        $categories
    ));
});
