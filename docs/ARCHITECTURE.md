# Architecture technique — Backend (Laravel 12)

> Référence structurelle chargée automatiquement (voir `CLAUDE.md` racine). Mise à jour :
> 03/08/2026. Vérifier le code avant de citer un détail précis si ce fichier date de plus de
> quelques semaines.

Deux usages distincts cohabitent dans ce repo :
1. **API REST** (`/api/v1/*`, Sanctum) — consommée par le frontend Vue, utilisateurs publics du jeu.
2. **Admin Blade** (`web.php`) — panneau d'administration de Greg, session-based (`web` guard).

Ces deux mondes ne se croisent jamais : les comptes créés via l'API n'ont pas d'email vérifié et
ne passent jamais par le flow Breeze ; les comptes Blade admin n'ont pas de `Character`.

## Modèles & relations

| Modèle | Table | Champs notables | Relations |
|---|---|---|---|
| `User` | `users` | `email`, `password` (pas de `name`) | `hasMany(Character)` |
| `Character` | `characters` | `user_id`, `pseudo` (unique via validation app, pas contrainte DB), `city_id` (nullable), `is_validated` (bool, défaut `false`) | `belongsTo(User)`, `belongsTo(City)` |
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
| POST | `auth/logout` | `Api\AuthController@logout` | `auth:sanctum` |
| GET | `auth/me` | `Api\AuthController@me` | `auth:sanctum` |

Pas d'autre endpoint API (pas de CRUD personnage, pas d'endpoint carte/royaume).

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

- `Api\AuthController` — cycle de vie complet API : `register` (crée `User` + `Character` en un
  seul appel, `is_validated=false`), `login` (par **pseudo**, pas email), `logout`, `me`.
- `Web\DashboardController` — `index` (liste personnages paginée, eager-load
  `user`/`city.province.kingdom`), `toggleValidation`, `users` (liste des users **sans**
  personnage), `destroyUser`.
- `Web\MapController` — **mort** : vue `map.list` référencée mais inexistante (roadmap Phase 6).
- `ProfileController` + `Auth/*` — scaffolding Breeze, non modifié.

## Middleware & rôles

- Aucun middleware custom — uniquement les défauts Laravel.
- `bootstrap/app.php` enregistre l'alias `role` → `Spatie\Permission\Middleware\RoleMiddleware`.
- **Un seul rôle Spatie : `admin`**, seedé via `UserSeeder` sur `SEEDER_SUPER_ADMIN_EMAIL`. Aucune
  permission Spatie créée (uniquement le rôle, vérifié via `role:admin` ou `hasRole('admin')` côté
  Blade).

## Gestion des utilisateurs (admin)

Tout vit dans `Web\DashboardController` + `users.blade.php` :
- Liste = uniquement les users **sans** personnage (`doesntHave('Characters')`), paginée (14).
- Suppression = hard delete (pas de soft delete), cascade sur `characters` via FK.
- Pas d'édition d'un autre user, pas de reset de mot de passe admin, pas de gestion de rôles
  (un seul rôle existe), pas de recherche/filtre.
- Colonnes affichées : `id`, `email`, date d'inscription, badge email vérifié.

## Gestion des personnages

- Création **uniquement** via `POST /api/v1/auth/register` (bundlée avec la création du `User`).
  Pas d'endpoint pour ajouter un 2e personnage ou changer de ville après coup.
- Login **bloque désormais si `is_validated=false`** (403, message `'Compte non validé.'`) —
  ajouté le 03/08/2026, avant cette date le login était autorisé indépendamment du statut.
  Le token émis à l'inscription reste valide (`register` n'est pas concerné par ce blocage) :
  un joueur qui vient de s'inscrire reste connecté et peut voir son statut "en attente" sur
  `/app/profil`, mais s'il se déconnecte avant validation, il ne peut plus se reconnecter tant
  que Greg ne l'a pas validé depuis le dashboard.
- Validation admin = simple toggle bool (`PATCH characters/{id}/validate`), pas d'historique.
- `city_id` jamais renseigné à l'inscription — assignation hors-flux (DB directe) si besoin.

## Tests

`docker exec odc-backend php artisan test` — **42/42 verts** (Pest). `CharacterFactory` utilise
`RAND()` MySQL pour `city_id` par défaut → passer `city_id: null` explicitement dans les tests
(incompatible SQLite/CI).

## Contraintes projet

- Backend **français uniquement** — `__()` + `lang/fr.json`, jamais de texte en dur dans une vue
  Blade (y compris le code existant qu'on retouche).
- Ne jamais committer `.env`/credentials.
