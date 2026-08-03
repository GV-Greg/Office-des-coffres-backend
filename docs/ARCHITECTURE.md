# Architecture technique — Backend (Laravel 12)

> Référence structurelle chargée automatiquement (voir `CLAUDE.md` racine). Mise à jour :
> 03/08/2026. Vérifier le code avant de citer un détail précis si ce fichier date de plus de
> quelques semaines.

Deux usages distincts cohabitent dans ce repo :
1. **API REST** (`/api/v1/*`, Sanctum) — consommée par le frontend Vue, utilisateurs publics du jeu.
2. **Admin Blade** (`web.php`) — panneau d'administration de Greg, session-based (`web` guard).

Ces deux mondes ont chacun leur propre flux de vérification d'email, indépendants l'un de
l'autre : Breeze/Blade (session, `verification.verify`) pour les comptes admin, et un second
flux API (`verification.verify.api`, voir plus bas) pour les comptes joueurs, ajouté le
03/08/2026. Les comptes Blade admin n'ont pas de `Character`.

## Modèles & relations

| Modèle | Table | Champs notables | Relations |
|---|---|---|---|
| `User` | `users` | `email`, `password` (pas de `name`) | `hasMany(Character)` — **un compte peut avoir plusieurs personnages** depuis le 03/08/2026 (la relation existait déjà en base, seul le code supposait "un seul") |
| `Character` | `characters` | `user_id`, `pseudo` (unique via validation app, pas contrainte DB), `city_id` (obligatoire à la création depuis le 03/08/2026), `is_validated` (bool, défaut `false`) | `belongsTo(User)`, `belongsTo(City)` |
| `Kingdom` | `rk_kingdoms` | `kingdom_name` | `hasMany(Province)` |
| `Province` | `rk_provinces` | `province_name` | `belongsTo(Kingdom)`, `hasMany(City)` |
| `City` | `rk_cities` | `city_name`, `is_capital` | `belongsTo(Province)`, `hasMany(Character)` ⚠️ bug : FK déclarée `'user_id'` au lieu de `'city_id'` — relation cassée, non utilisée actuellement |
| `Role`/`Permission` | Spatie | — | extensions vides de Spatie Permission |
| `Team` | — | — | **mort** : pas de migration, teams désactivé (`config/permission.php`) |

**Cookies/consentement : aucun stockage backend.** Pas de modèle, migration ou colonne liée aux
cookies — tout le consentement RGPD vit côté frontend (localStorage). Voir mémoire
`project_cookie_tiers` côté session Claude pour la réflexion en cours sur un futur palier
"données de jeu liées au compte" qui nécessiterait une vraie table.

## Schéma DB (migrations, dans l'ordre)

1. `users`, `password_reset_tokens`, `failed_jobs`, `personal_access_tokens` (Sanctum) — scaffolding standard
2. `rk_kingdoms` → `rk_provinces` (FK cascade) → `rk_cities` (FK cascade, `is_capital` bool)
3. `characters` (`user_id` FK cascade, `city_id` FK cascade nullable, `is_validated` bool)
4. Tables Spatie Permission v6 (`permissions`, `roles`, `model_has_*`, `role_has_permissions`) — mode non-teams, migration depuis un ancien Laratrust (drop des tables `permission_user`/`permission_role`/`role_user` en préambule)

Pas de table `sessions`/`cache` (drivers `file`).

## Routes

### `routes/api.php` (préfixe `/api/v1`)

| Méthode | URI | Contrôleur | Middleware |
|---|---|---|---|
| POST | `auth/register` | `Api\AuthController@register` | public |
| POST | `auth/login` | `Api\AuthController@login` | public |
| POST | `auth/resend-verification` | `Api\AuthController@resendVerification` | public, `throttle:6,1` |
| GET | `auth/verify-email/{id}/{hash}` | `Api\AuthController@verifyEmail` | `signed` (nommée `verification.verify.api`) |
| POST | `auth/logout` | `Api\AuthController@logout` | `auth:sanctum` |
| GET | `auth/me` | `Api\AuthController@me` | `auth:sanctum` |
| GET | `characters` | `Api\CharacterController@index` | `auth:sanctum` |
| POST | `characters` | `Api\CharacterController@store` | `auth:sanctum` |
| GET | `map` | `Api\MapController@index` | public — arbre royaumes→provinces→villes, pour les sélecteurs de ville |

Tout ajouté le 03/08/2026 sauf `register`/`login`/`logout`/`me` (Phase 1).

### `routes/web.php` (admin Blade)

| Méthode | URI | Nom | Contrôleur | Middleware |
|---|---|---|---|---|
| GET | `dashboard` | `dashboard` | `Web\DashboardController@index` | `auth,verified,role:admin` |
| GET | `users` | `users` | `Web\DashboardController@users` | `auth,verified,role:admin` |
| DELETE | `users/{user}` | `users.destroy` | `Web\DashboardController@destroyUser` | `auth,verified,role:admin` |
| PATCH | `characters/{character}/validate` | `characters.validate` | `Web\DashboardController@toggleValidation` | `auth,verified,role:admin` |
| GET/PATCH/DELETE | `/profile` | `profile.*` | `ProfileController` | `auth,verified` (self-service admin, pas un outil de gestion d'autres users) |

`routes/auth.php` : scaffolding Breeze standard (register/login/logout Blade, reset password,
vérification email) — **non modifié**, guard `web`, sans lien avec l'API.

## Contrôleurs

- `Api\AuthController` — `register` (crée uniquement le `User`, envoie l'email de vérification),
  `verifyEmail` (valide le lien signé, marque l'email vérifié, émet un token, **redirige vers
  `FRONTEND_URL`**), `resendVerification`, `login` (par **email**, pas pseudo — changé le
  03/08/2026, bloque avec 403 "Email non vérifié." si `! hasVerifiedEmail()`), `logout`, `me`.
  `login`/`me` renvoient `user.characters` (liste, pas un pseudo/statut unique).
- `Api\CharacterController` — `store` (crée un personnage pour l'utilisateur connecté, pseudo +
  ville obligatoires), `index` (liste les personnages du compte connecté).
- `Api\MapController` — `index`, arbre `Kingdom::with(['provinces.cities'])`, public, pas de
  pagination (~300 villes, volume géré en un seul payload pour un sélecteur cascade côté front).
- `Web\DashboardController` — `index` (liste personnages paginée, eager-load
  `user`/`city.province.kingdom`), `toggleValidation`, `users` (liste **tous** les users, avec
  recherche), `destroyUser`.
- `Web\MapController` — **mort** : vue `map.list` référencée mais inexistante (roadmap Phase 6).
  Sans lien avec `Api\MapController` (nouveau, actif, JSON).
- `ProfileController` + `Auth/*` — scaffolding Breeze, non modifié.

## Middleware & rôles

- Aucun middleware custom — uniquement les défauts Laravel.
- `bootstrap/app.php` enregistre l'alias `role` → `Spatie\Permission\Middleware\RoleMiddleware`.
- **Un seul rôle Spatie : `admin`**, seedé via `UserSeeder` sur `SEEDER_SUPER_ADMIN_EMAIL`. Aucune
  permission Spatie créée (uniquement le rôle, vérifié via `role:admin` ou `hasRole('admin')` côté
  Blade).

## Gestion des utilisateurs (admin)

Tout vit dans `Web\DashboardController` + `users.blade.php` :
- **Depuis le 03/08/2026** : liste **tous** les users (avant : uniquement ceux sans personnage),
  paginée (14), avec recherche (`?search=`) par email OU pseudo du personnage
  (`orWhereHas('characters', ...)`), `withQueryString()` pour garder le filtre à travers la
  pagination.
- Colonnes affichées : `id`, `email`, **personnage** (pseudo + badge validé/en attente, ou
  "Aucun personnage"), date d'inscription, badge email vérifié.
- Suppression = hard delete (pas de soft delete), cascade sur `characters` via FK.
- Pas d'édition d'un autre user, pas de reset de mot de passe admin, pas de gestion de rôles
  (un seul rôle existe).
- Tests : `tests/Feature/DashboardTest.php` (13 tests au total, dont 7 dédiés à `/users` — la
  route n'avait auparavant **aucune couverture** malgré la roadmap l'indiquant testée, même
  écart doc/réalité que celui trouvé sur `ProfilView.vue` côté frontend).

## Inscription / vérification d'email (comptes joueurs) — refonte du 03/08/2026

Le flux a changé en profondeur : avant, `register()` créait `User`+`Character` en un seul appel,
sans jamais vérifier l'email (le `Registered` event n'était pas déclenché côté API). Nouveau
flux en 3 étapes, décidé avec Greg :

1. **`POST /api/v1/auth/register`** — email + password + confirmation uniquement. Crée le
   `User`, envoie `App\Notifications\VerifyApiEmail` (sous-classe de
   `Illuminate\Auth\Notifications\VerifyEmail`, texte 100% français, lien signé vers
   `verification.verify.api` au lieu de la route Blade). Pas de token émis à ce stade.
2. **Clic sur le lien** → `GET auth/verify-email/{id}/{hash}` (signé, sans session) → contrôleur
   vérifie `hash_equals(sha1($user->getEmailForVerification()), $hash)`, marque
   `markEmailAsVerified()`, émet un token Sanctum, **redirige (302)** vers
   `config('app.frontend_url') . '/verify-email?token=...'` (ou `?error=invalid`). Connexion
   automatique côté frontend à ce stade (décision Greg : pas de renvoi vers un formulaire de
   login après confirmation).
3. Une fois connecté, le joueur est invité à créer un ou plusieurs personnages via
   `POST /api/v1/characters` (pseudo + `city_id` obligatoires, `is_validated=false`).

**`login()` bloque désormais sur l'email non vérifié** (403, "Email non vérifié.") — remplace
l'ancien blocage par personnage non validé (n'avait plus de sens dès qu'un compte peut avoir
plusieurs personnages). La validation **par personnage** (`characters.validate`, dashboard admin)
reste inchangée et continue d'exister indépendamment — elle ne bloque plus la connexion, juste
l'accès aux fonctionnalités liées à ce personnage précis côté frontend (à affiner au fil du
développement des modules).

**`FRONTEND_URL`** — nouvelle clé `.env`/`config('app.frontend_url')`, nécessaire pour construire
l'URL de redirection post-vérification (n'existait pas avant, le backend n'avait jamais eu besoin
de connaître l'URL du frontend).

**Pas de migration des comptes déjà inscrits avant ce changement** — décision explicite de Greg
(reprise à 0), aucun code de compatibilité ascendante à prévoir.

## Tests

`docker exec odc-backend php artisan test` — **65/65 verts** (Pest). `CharacterFactory` utilise
`RAND()` MySQL pour `city_id` par défaut → passer `city_id: null` explicitement dans les tests
(incompatible SQLite/CI). Nouvelles factories `KingdomFactory`/`ProvinceFactory`/`CityFactory`
(modèles `Kingdom`/`Province`/`City` n'avaient pas `HasFactory` avant le 03/08/2026). Tester un
lien signé : construire l'URL directement avec `URL::temporarySignedRoute('verification.verify.api', ...)`
dans le test plutôt que parser le contenu de l'email (voir `AuthTest.php`).

## Contraintes projet

- Backend **français uniquement** — `__()` + `lang/fr.json`, jamais de texte en dur dans une vue
  Blade (y compris le code existant qu'on retouche).
- Ne jamais committer `.env`/credentials.
