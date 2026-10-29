<?php

use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Garde-fou — couverture de l'export art. 20 RGPD
|--------------------------------------------------------------------------
|
| Jumeau de UserDataLifecycleTest.php pour l'export : chaque table déclarée
| dans ce dernier doit aussi figurer ici, marquée EXPORTÉE ou EXCLUE.
|
| Nouvelle table dans UserDataLifecycleTest → la déclarer ici aussi.
| Une table absente de cette liste fait passer le test au rouge.
|
| Brief : admin/content/brief-export-donnees.md (§ Construire pour les modules à venir).
| Implémentation : App\Services\AccountExport.
*/

$exportDecisions = [
    // Exportée : section "characters" — données fournies par le joueur.
    'characters' => 'exported',

    // Exportées : section "mandates" — données fournies par le joueur, plus les décisions
    // le concernant. La note interne (texte admin) est exclue, jamais exportée.
    'mayor_mandates'   => 'exported',
    'council_mandates' => 'exported',

    // D — conservation justifiée (historique public). Aucune colonne de lien vers un compte
    // encore actif (pseudo en texte) : non exportée, le compte rattaché n'existe plus.
    'office_history_archive' => 'excluded',

    // C — relevé communautaire de la province. character_id -> NULL à la suppression.
    // Non exporté : données communautaires, pas propriété exclusive de l'auteur.
    'mine_reports' => 'excluded',

    // C — prix communautaires de la province.
    // Non exportés : mêmes raisons que mine_reports.
    'province_mine_prices' => 'excluded',

    // Secrets techniques — exclus volontairement pour la sécurité du joueur
    // (brief §1 et section "not_included.credentials" de l'export).
    'oauth_access_tokens'  => 'excluded',
    'oauth_refresh_tokens' => 'excluded',
    'oauth_auth_codes'     => 'excluded',
    'oauth_device_codes'   => 'excluded',
    'password_reset_tokens' => 'excluded',

    // Exportée : noms des rôles dans "account.roles" (droit d'accès art. 15).
    'model_has_roles' => 'exported',

    // Exclue : aucune permission directe attribuée sur ce projet.
    // À exporter comme les rôles si ce modèle change.
    'model_has_permissions' => 'excluded',
];

// Même logique de détection que UserDataLifecycleTest
$linkColumns    = ['user_id', 'email', 'model_id', 'character_id'];
$notAccountData = ['users', 'migrations'];

test('toute table liée à un compte a une décision d\'export déclarée',
    function () use ($exportDecisions, $linkColumns, $notAccountData) {
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

        $undeclared = array_values(array_diff($linked, array_keys($exportDecisions)));

        expect($undeclared)->toBe([], sprintf(
            "Table(s) liée(s) à un compte sans décision d'export : %s.\n" .
            "Déclarer 'exported' ou 'excluded' (avec la raison) dans AccountExportCoverageTest.\n" .
            "Voir : admin/content/brief-export-donnees.md (§ Construire pour les modules à venir)\n" .
            "et admin/strategies/donnees-utilisateur.md.",
            implode(', ', $undeclared)
        ));
    });

test('la liste des décisions ne contient pas de table disparue du schéma',
    function () use ($exportDecisions) {
        $missing = array_values(array_filter(
            array_keys($exportDecisions),
            fn (string $table) => ! Schema::hasTable($table)
        ));

        expect($missing)->toBe([], sprintf(
            'Table(s) déclarée(s) dans AccountExportCoverageTest mais absente(s) du schéma : %s. ' .
            'Retirer ces entrées de la liste.',
            implode(', ', $missing)
        ));
    });

test('chaque décision est "exported" ou "excluded"',
    function () use ($exportDecisions) {
        $invalid = array_keys(array_filter(
            $exportDecisions,
            fn (string $decision) => ! in_array($decision, ['exported', 'excluded'], true)
        ));

        expect($invalid)->toBe([], sprintf(
            'Valeur inconnue pour : %s. Seules "exported" et "excluded" sont valides.',
            implode(', ', $invalid)
        ));
    });
