# Tests — Office des Coffres (backend)

Référence structurelle chargée à la demande (voir `_IA/ODC/ODC-strategie-tests.md` pour le
raisonnement complet). 72 tests verts au 08/08/2026 (`docker exec odc-backend php artisan test`)
— décompte à jour dans `README.md`, ne pas dupliquer ici.

## Principe : adapter la portée du test au périmètre du changement

Pendant le développement, ne lancer que les tests du domaine concerné. La suite complète est
réservée à la fin d'une tâche cohérente ou juste avant un push.

## Scripts (`composer <script>`, à exécuter via `docker exec odc-backend composer <script>`)

| Script | Ce qu'il fait | Périmètre |
|---|---|---|
| `pest` | Suite complète | `tests/` |
| `test:api` | Contrôleurs API REST | `tests/Feature/Api` |
| `test:auth` | Auth API + scaffolding Breeze | `tests/Feature/Auth` + `AuthTest.php` |
| `test:web` | Dashboard admin Blade | `DashboardTest.php` + `ProfileTest.php` |
| `test:unit` | Tests unitaires purs | `tests/Unit` (actuellement vide, voir plus bas) |
| `test:filter -- <motif>` | Filtre par nom (`--filter` Pest natif) | selon le motif |
| `test:parallel` | Suite complète en parallèle | `tests/` |

`test:filter` transmet l'argument à `pest --filter` — Composer relaie tout ce qui suit `--` à la
commande sous-jacente : `composer test:filter -- AuthTest`.

### Deux options envisagées par la stratégie, non retenues après vérification

- **`--parallel`** : la stratégie envisageait d'installer `pestphp/pest-plugin-parallel`. **Déjà
  natif dans Pest 3** (`vendor/bin/pest --help` le liste directement) — le plugin standalone sur
  Packagist cible une version antérieure de Pest, où le parallélisme n'était pas encore dans le
  noyau. Vérifié : `composer test:parallel` tourne sur 16 processus, 73 tests verts. Aucune
  dépendance supplémentaire ajoutée.
- **`--dirty`** (équivalent de `test:changed` côté Vitest) : la stratégie évoquait
  `pestphp/pest-plugin-git`. **Ce paquet n'existe pas sur Packagist** sous ce nom (vérifié via
  `composer show -a`), et Pest 3 n'a pas d'équivalent natif à `--dirty`. Pas d'item `test:dirty`
  dans les scripts — à rouvrir si un vrai besoin se présente, avec une recherche du nom exact du
  paquet (ou une implémentation maison via `git diff --name-only` + `--filter`).

## `tests/Unit/` vide depuis le 08/08/2026

`ExampleTest.php` (reste du template Laravel, un seul test trivial `expect(true)->toBeTrue()`)
supprimé. `tests/Unit/` est donc vide et `test:unit`/`--testsuite=Unit` renvoient `INFO No tests
found.` — **comportement normal, exit 0**, vérifié explicitement (ni Pest ni `phpunit.xml` ne
lèvent d'erreur sur une testsuite vide). Le dossier reste dans l'arborescence (`autoload-dev`
psr-4 `Tests\` le couvre) pour accueillir un futur vrai test unitaire sans reconfiguration.

## CI

`tests.yml` : `on: pull_request` + `on: workflow_call` (appelé par `deploy.yml`, qui bloque le
déploiement si la suite échoue). Cache Composer (répertoire de téléchargement, pas `vendor/`)
restauré **avant** `composer install` — contrairement à `npm ci` côté frontend, `composer
install` ne vide pas ce cache, donc pas de contrainte d'ordre particulière au-delà de la
disponibilité de la commande `composer` (après `setup-php`).
