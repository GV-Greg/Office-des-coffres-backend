# Changelog — Office des Coffres (backend)

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/). Une entrée par PR
mergée sur `main` (`master` avant le 09/08/2026, voir `admin/strategies/git.md` §10 — ou merge
direct pour les deux entrées antérieures aux PR GitHub). Pas de versionnage sémantique — chaque
merge sur `main` déclenche un déploiement, la date de merge fait foi. L'historique détaillé
(raisonnement, incidents, décisions) reste dans `roadmap.md` à la racine du workspace ; ce fichier
n'en retient que le résumé daté.

## [2026-08-09] — PR #15

### Changed
- Migration de l'authentification API Sanctum → Passport (OAuth2) : `login()` émet désormais un
  couple access token (15 min) + refresh token (30 jours si « Rester connecté », 12h sinon),
  nouvel endpoint `POST /auth/refresh`. Voir `docs/DECISIONS.md` pour l'ADR complet.

### Removed
- Migration `personal_access_tokens` (table Sanctum, orpheline depuis le retrait du package).

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
