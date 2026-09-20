# Changelog — Office des Coffres (backend)

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/). Une entrée par PR
mergée sur `main` (`master` avant le 09/08/2026, voir `admin/strategies/git.md` §10 — ou merge
direct pour les deux entrées antérieures aux PR GitHub). Pas de versionnage sémantique — chaque
merge sur `main` déclenche un déploiement, la date de merge fait foi. L'historique détaillé
(raisonnement, incidents, décisions) vit dans `admin/suivi/*.md` et `admin/archives/` à la racine
du workspace ; ce fichier n'en retient que le résumé daté.

## [2026-09-20] — PR #26

### Ajouté
- **Chiffrement des données de module, avec une clé dédiée `MODULE_DATA_KEY`** — cast
  `App\Casts\EncryptedModuleData` bâti sur `Illuminate\Encryption\Encrypter`, alimenté par sa
  propre clé et **jamais par `APP_KEY`** : `php artisan key:generate` fait tourner `APP_KEY` en
  routine, ce qui ne coûte qu'une reconnexion, alors que la même rotation sur des données de
  module les rendrait définitivement illisibles. Config `config/module_data.php`, avec un
  équivalent d'`APP_PREVIOUS_KEYS` (`MODULE_DATA_PREVIOUS_KEYS`) : les clés antérieures sont
  essayées au déchiffrement, jamais au chiffrement, ce qui transforme une rotation accidentelle
  en incident réparable plutôt qu'en perte définitive.
- **`key:generate` désactivée en production** (`AppServiceProvider`) — ceinture-bretelles
  assumée : `--force` contournait la protection native de `ConfirmableTrait`. La vraie défense
  reste la clé dédiée ci-dessus, parce qu'elle supprime la conséquence au lieu de bloquer
  l'action.
- 7 tests Pest (`Feature/ModuleDataEncryptionTest`) : payload relu identique, clair absent de la
  base, axes laissés filtrables en SQL, déchiffrement d'une donnée écrite avec une clé
  antérieure, écriture toujours sur la clé courante, refus de démarrer sans `MODULE_DATA_KEY`,
  équivalence clé brute / `base64:`.

### Notes
- Aucune table de module n'existe encore : le mécanisme se livre et se teste seul. C'est
  délibéré — chiffrer après coup imposerait une migration de données sur des lignes de prod.
- **Le patron impose de chiffrer le contenu, jamais les axes** (`city_id`, `province_id`,
  `character_id`, `reported_at` restent en clair) : une colonne chiffrée n'est ni indexable, ni
  triable, ni filtrable, l'IV aléatoire faisant échouer jusqu'à l'égalité.
- ⚠️ **`MODULE_DATA_KEY` doit être reportée à la main sur le `.env` de prod** (rien ne le fait
  automatiquement) et **sauvegardée ailleurs que la base** : si le dump SQL et le `.env` voyagent
  ensemble, le chiffrement n'a rien acheté.
- Décision et raisonnement complets : `admin/strategies/donnees-utilisateur.md` §5.

## [2026-09-19] — PR #22

### Fixed
- **`password_reset_tokens` survivait à la suppression d'un compte** : la table est clé par
  `email`, sans FK vers `users`, donc ni la cascade base de données ni `destroyAccount()` ne la
  touchaient. Une adresse email persistait après un effacement art. 17, contre ce que promet
  `/legal/privacy` §5. Écart constaté par la vérification en prod du 19/09/2026, pas par un test.

### Changed
- `oauth_auth_codes` et `oauth_device_codes` sont désormais nettoyées elles aussi dans la
  transaction. Le projet n'utilise ni le code d'autorisation ni le device flow, mais
  `php artisan route:list` montre que Passport expose leurs routes par défaut et que leur
  `user_id` n'est qu'une colonne indexée : rien ne garantissait que ces tables restent vides.
- `model_has_permissions` : vérifiée, aucune ligne à ajouter — mais pas pour la raison supposée.
  Le hook `deleting` de `bootHasRoles` ne détache que les rôles ; ce qui couvre la table est
  `bootHasPermissions`, un second trait que `HasRoles` utilise en interne. Un test fige le
  comportement plutôt que de faire confiance à la dépendance.

## [2026-09-19] — PR #23

### Added
- Garde-fou structurel `tests/Feature/Enforcement/UserDataLifecycleTest.php` : toute table portant
  `user_id`, `email` ou `model_id` (pivots polymorphes de Spatie) doit figurer dans une liste
  déclarée avec sa catégorie de cycle de vie A/B/C/D, sinon la suite échoue. Il attrape **l'oubli**
  d'une future table — l'angle mort qui a laissé passer `password_reset_tokens` le 19/09 — là où un
  test comportemental ne couvre que les tables auxquelles l'auteur a pensé. Le message d'échec
  renvoie vers `admin/strategies/donnees-utilisateur.md` et rappelle les trois questions qui mènent
  à la catégorie.
- ADR « Toute donnée rattachée à un compte porte une catégorie de cycle de vie » dans
  `docs/DECISIONS.md`.

## [2026-09-13] — PR #21

### Added
- `DELETE /api/v1/auth/account` — suppression self-service du compte (art. 17 RGPD, promesse
  écrite en §7 de la politique de confidentialité). Le mot de passe courant est exigé en plus du
  jeton : une action irréversible ne doit pas reposer sur une session laissée ouverte. Réponses
  `204 No Content` en cas de succès, `403` sur mot de passe incorrect, `422` si le mot de passe
  est absent.
- La suppression porte **toujours** sur le compte du porteur du jeton : aucun identifiant n'est
  accepté, ni en corps de requête ni dans l'URL. Un test le vérifie explicitement.
- Effacement complet et immédiat, dans une transaction : personnages en cascade (FK), jetons
  d'accès Passport et refresh tokens associés supprimés — les tables OAuth n'ayant pas de
  contrainte vers `users`, ils survivraient sans ce nettoyage explicite. Trace d'audit
  non réidentifiante en log (`Account deleted (id=X)`).

## [2026-08-10] — PR #16

### Changed
- Case « Se souvenir de moi » du formulaire de connexion admin renommée « Rester connecté »
  (cohérence de vocabulaire avec la case équivalente côté frontend, PR #26 frontend). Nouvelle clé
  de traduction `Stay logged in` (`lang/fr.json`) à la place de `Remember me` — le mécanisme reste
  le remember-me Laravel standard (guard `web`, `remember_token`), inchangé.

## [2026-08-10] — PR #17 (mergée avant #16)

### Fixed
- `php artisan db:seed` plantait en production (`Call to undefined function
  Database\Factories\fake()`) : `UserSeeder`/`CharacterFactory` appellent `fake()` même pour les
  comptes déterministes (admin, Artifice, Buldo), car `Factory::definition()` s'exécute
  intégralement avant la fusion des attributs passés à `create()`. `fakerphp/faker` déplacé de
  `require-dev` vers `require` (recommandation standard Laravel dès qu'un seeder utilise des
  factories hors dev/test) — découvert et corrigé pendant le déploiement de la migration Passport
  (PR #15), qui a été la première occasion de lancer `db:seed` en prod avec `composer install
  --no-dev`.

## [2026-08-10] — PR #15

### Changed
- Migration de l'authentification API Sanctum → Passport (OAuth2) : `login()` émet désormais un
  couple access token (15 min) + refresh token (30 jours si « Rester connecté », 12h sinon),
  nouvel endpoint `POST /auth/refresh`. Voir `docs/DECISIONS.md` pour l'ADR complet.

### Removed
- Migration `personal_access_tokens` (table Sanctum, orpheline depuis le retrait du package).

## [2026-08-09] — PR #14

### Added
- `scripts/docs-sync-check.sh` en CI : échoue si le décompte de tests diverge entre fichiers, ou
  si `docs/ARCHITECTURE.md` accuse plus de 30 jours de retard sur le dernier commit touchant
  `app/`/`routes/`/`database/`/`config/`.

## [2026-08-09] — PR #13

### Changed
- Sources uniques : chiffre de tests obsolète retiré de `ARCHITECTURE.md` (README fait foi),
  structure détaillée retirée du README au profit d'un renvoi vers `ARCHITECTURE.md`.

## [2026-08-09] — PR #12

### Changed
- Branche principale renommée `master` → `main` côté GitHub ; `deploy.yml` et références mises à
  jour en conséquence (les entrées déjà datées de ce CHANGELOG gardent `master`, exact au moment
  des faits).

## [2026-08-09] — PR #11

### Added
- `docs/TESTS.md` : temps de référence mesurés par groupe de tests Pest.

## [2026-08-09] — PR #10

### Added
- `tests/Unit/Enforcement/CookieUsageTest.php` : échoue si `Cookie::`/`setcookie(` apparaît dans
  `app/`, ou si `Session::`/`session(` apparaît hors des contrôleurs Auth/Profile Breeze (guard
  `web`, session-based par nature) — équivalent backend du garde-fou frontend (PR frontend #21).

## [2026-08-09] — PR #9

### Changed
- `config/cors.php` lit désormais `CORS_ALLOWED_ORIGINS` (env, liste séparée par virgules) au
  lieu d'un `allowed_origins` hardcodé à `['*']`.

## [2026-08-08] — PR #8

### Added
- Scripts Composer par domaine (`test:api`, `test:auth`, `test:web`, `test:unit`, `test:filter`,
  `test:parallel`), `docs/TESTS.md` (miroir du frontend).

### Removed
- `tests/Unit/ExampleTest.php` (reste de template).

## [2026-08-08] — PR #7

### Added
- `CHANGELOG.md` (ce fichier) et `docs/DECISIONS.md` (ADR pour les choix structurants).

## [2026-08-08] — PR #6

### Changed
- Le déploiement (`deploy.yml`) attend désormais la réussite de `tests.yml` (appelé en
  `workflow_call`) avant de partir en prod — jusque-là les deux workflows tournaient en parallèle
  sans dépendance.

## [2026-08-06] — PR #5

### Added
- `AuthController::userPayload()` expose `is_admin` (`$user->hasRole('admin')`) — jusque-là aucune
  information de rôle n'était accessible depuis l'API, seulement côté Blade.
- Notification Discord sur `#coulisses-admin` (succès et échec) à chaque déploiement.

## [2026-08-05] — PR #4

### Fixed
- Signature des emails de vérification (`VerifyApiEmail`) : virgule manquante dans `lang/fr.json`
  empêchait la traduction Laravel par défaut, l'email restait partiellement en anglais.
- Traduction du nom du royaume dans `table-character.blade.php` (dashboard admin), oubliée lors de
  précédentes traductions.

### Changed
- `UserSeeder` ne génère plus 25 faux comptes/personnages par factory — ne crée que le compte
  admin réel et les personnages réels de Greg. Redevenu sûr à relancer tel quel en prod.

## [2026-08-04] — Merge `feat/account-verification`

### Added
- Flux d'inscription API en 3 étapes : `POST /api/v1/auth/register` (email + mot de passe
  uniquement) → email de vérification signé (`VerifyApiEmail`) → `GET
  /api/v1/auth/verify-email/{id}/{hash}` (connexion automatique, redirection frontend).
- `POST/GET /api/v1/characters` — un compte peut désormais créer et lister plusieurs personnages
  (avant : un personnage était créé directement à l'inscription).
- `GET /api/v1/map` — arbre royaumes → provinces → villes, public, pour le sélecteur de ville.
- Résidence des personnages : modification de ville via `PATCH /api/v1/characters/{id}`, repasse
  le personnage en attente de validation (`pending_residence_change`).
- `/users` (dashboard) liste désormais **tous** les comptes (avant : uniquement ceux sans
  personnage), avec recherche par email ou pseudo.

### Changed
- `login()` bloque désormais sur l'**email non vérifié** (403) — remplace l'ancien blocage par
  personnage non validé introduit en PR #3, devenu inadapté dès qu'un compte peut avoir plusieurs
  personnages.
- Un compte peut avoir plusieurs `Character` (`User::hasMany`) — la relation existait déjà en
  base, seul le code supposait jusque-là un seul personnage par compte.

### Removed
- Pas de migration des comptes inscrits avant ce changement — décision explicite : on repart à 0.

## [2026-08-03] — PR #3

### Added
- Connexion bloquée tant que le personnage n'est pas validé par un admin (403).

> ⚠️ Remplacé le lendemain (voir entrée du 2026-08-04) par un blocage sur l'email non vérifié —
> n'a plus de sens dès qu'un compte peut avoir plusieurs personnages.

## [2026-08-03] — PR #2

### Added
- Validation manuelle des personnages depuis le dashboard admin (`characters.validate`,
  `is_validated`).
- Rôle Spatie `admin` + middleware `role:admin` sur `dashboard`/`users`/`users.destroy`/
  `characters.validate` — avant, ces routes n'étaient protégées que par `auth+verified`,
  accessibles à n'importe quel compte inscrit sur le backend Blade.

## [2026-06-28] — Merge `feat/api-rest-auth`

### Added
- API REST `/api/v1` : `register`, `login` (par pseudo à l'origine), `logout`, `me` — Laravel
  Sanctum (tokens bearer, pas Passport malgré une ancienne commande `passport:install` restée
  dans certains scripts).

## [2026-06-27] — PR #1

### Changed
- Montée en Laravel 12 (PHP 8.2).
- Migration du système de rôles/permissions Laratrust → Spatie Permission.
- Ajout de la configuration Docker (dev local).
