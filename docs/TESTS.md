# Tests — Office des Coffres (backend)

Référence structurelle chargée à la demande (voir `_IA/ODC/ODC-strategie-tests.md` pour le
raisonnement complet). 73 tests verts au 09/08/2026 (`docker exec odc-backend php artisan test`)
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
| `test:unit` | Tests unitaires purs | `tests/Unit` (voir plus bas) |
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

## Temps de référence (mesurés le 09/08/2026, machine de dev)

| Suite | Fichiers | Tests | Durée |
|---|---|---|---|
| `test:unit` | 1 | 1 | ~0,3 s |
| `test:api` | — | 33 | ~3,6 s |
| `test:web` | — | 21 | ~3,7 s |
| `test:auth` (le plus gros domaine) | — | 37 | ~6,2 s |
| Suite complète (`composer pest`) | — | 73 | ~11,2 s |
| Suite complète en parallèle (`composer test:parallel`, 16 processus) | — | 73 | ~3,7 s |

Si un chiffre dérape de plus de 50 % lors d'une prochaine mesure, investiguer (connexion SQLite
qui traîne, factory `RAND()` mal maîtrisée, etc.). Contrairement au frontend (coût dominé par le
démarrage jsdom/Vite), le poste de coût backend est plus uniforme par test — d'où l'écart net
avec `test:parallel`, qui répartit vraiment la charge entre 16 processus au lieu de la payer en
série.

## `tests/Unit/`

`ExampleTest.php` (reste du template Laravel, un seul test trivial `expect(true)->toBeTrue()`)
supprimé le 08/08/2026. `tests/Unit/` était alors vide et `test:unit`/`--testsuite=Unit`
renvoyaient `INFO No tests found.` — comportement normal, exit 0, sur un dossier qui existe mais
est vide.

⚠️ **Piège découvert en CI à cette occasion, pas en local** : git ne suit pas les dossiers vides.
Sur un checkout frais (CI, nouveau clone), `tests/Unit/` n'existait **pas du tout** — `php
artisan test` (qui résout la testsuite `Unit` déclarée dans `phpunit.xml` en exigeant que le
dossier existe physiquement) échouait en dur : `Test directory "tests/Unit" not found`, exit 2.
La vérification locale initiale ne l'avait pas révélé car le dossier restait présent sur le
disque après un simple `rm` du fichier (jamais un vrai checkout propre). **Fix** :
`tests/Unit/.gitkeep` ajouté pour que git suive le dossier vide, revérifié via `git worktree add`
(checkout propre isolé) avant de repousser.

**Premier test réel depuis le 09/08/2026** : `tests/Unit/Enforcement/CookieUsageTest.php` —
garde-fou de `admin/strategies/cookies.md` §9 (item #8), échoue si un `Cookie::`, `setcookie(`
apparaît n'importe où dans `app/`, ou un `Session::`/`session(` ailleurs que dans les
contrôleurs Auth/Profile Breeze (`web` guard, session-based par nature — seule zone où c'est
attendu). N'utilise volontairement pas `app_path()`/`app()` : `tests/Unit` n'a pas le `TestCase`
Laravel (voir `tests/Pest.php`, `uses(TestCase::class)->in('Feature')` seulement), le chemin vers
`app/` est calculé via `dirname(__DIR__, 3)`.

## CI

`tests.yml` : `on: pull_request` + `on: workflow_call` (appelé par `deploy.yml`, qui bloque le
déploiement si la suite échoue). Cache Composer (répertoire de téléchargement, pas `vendor/`)
restauré **avant** `composer install` — contrairement à `npm ci` côté frontend, `composer
install` ne vide pas ce cache, donc pas de contrainte d'ordre particulière au-delà de la
disponibilité de la commande `composer` (après `setup-php`).

Une étape `scripts/docs-sync-check.sh` (Docs #10 de `admin/strategies/docs.md`) tourne juste
après le checkout (`fetch-depth: 0`, nécessaire au calcul de fraîcheur) : échoue si plusieurs
chiffres de tests différents apparaissent dans les `.md` du repo, ou si `docs/ARCHITECTURE.md`
annonce une date de mise à jour de plus de 30 jours antérieure au dernier commit dans
`app/`/`routes/`/`database/`/`config/`.
