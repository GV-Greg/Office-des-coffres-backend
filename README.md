# Office des Coffres — Backend

> Outil communautaire non officiel pour le jeu [Renaissance Kingdoms](https://www.renaissancekingdoms.com/).
> Ce site n'est pas affilié à Celsius Online, l'éditeur du jeu.

Deux interfaces exposées :
- **Interface d'administration** (Blade) : `odc-admin.creacube.be` — gestion des utilisateurs et personnages
- **API REST** consommée par le frontend Vue.js : `odc-admin.creacube.be/api/v1/`

Dépôt frontend : [Office-des-coffres-vuejs](https://github.com/GV-Greg/Office-des-coffres-vuejs)

---

## Stack technique

| Outil | Version | Rôle |
|---|---|---|
| PHP | 8.2 | Langage |
| Laravel | 12.x | Framework |
| Laravel Sanctum | 4.x | Authentification API (tokens) |
| Spatie Permission | 6.x | Gestion des rôles et permissions |
| MySQL | 5.7 | Base de données |
| Vite | 6.x | Build des assets Blade |

---

## Structure du projet

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Api/
│   │   │   └── AuthController.php       # API REST : register, login, logout, me
│   │   ├── Auth/               # Authentification Breeze (Blade)
│   │   ├── Web/
│   │   │   └── DashboardController.php  # Dashboard admin (personnages) + liste utilisateurs + validation
│   │   └── ProfileController.php
│   └── Middleware/
├── Models/
│   ├── User.php                # HasRoles (Spatie) + HasApiTokens (Sanctum)
│   ├── Character.php
│   └── ...

resources/views/
├── auth/                       # Login, register, reset password
├── layouts/                    # app.blade.php, navigation, sidebar
├── components/                 # Composants Blade réutilisables
├── dashboard.blade.php         # Tableau de bord (personnages)
├── users.blade.php             # Utilisateurs sans personnage
└── profile/                   # Édition du profil

lang/
└── fr.json                     # Traductions françaises (locale par défaut : fr)

routes/
├── web.php                     # Routes admin Blade (auth + dashboard)
├── api.php                     # Routes API REST
└── auth.php                    # Routes Breeze (login, register, etc.)
```

---

## Déploiement

### Production

Déploiement automatique via **GitHub Actions** sur push `master` :
1. Build des assets Vite (`npm run build`)
2. Transfert FTP vers `odc-admin.creacube.be` (O2Switch) — `vendor/` exclu

Après chaque déploiement, lancer manuellement en SSH :
```bash
composer install --no-dev --optimize-autoloader
php artisan migrate
php artisan config:clear
php artisan view:clear
```

### Développement local

Via Docker (`docker-compose.dev.yml` à la racine du workspace, conteneurs `odc-backend` +
`odc-db`) — PHP/Composer ne sont généralement pas installés sur l'hôte :

```bash
docker compose -f ../docker-compose.dev.yml up -d db backend
docker exec odc-backend composer install
docker exec odc-backend cp .env.example .env
docker exec odc-backend php artisan key:generate
docker exec odc-backend php artisan migrate
```

Les assets Vite (`resources/js`, `resources/sass`) doivent être compilés **depuis l'hôte**
(Node n'est pas installé dans le conteneur `odc-backend`) :

```bash
npm install
npm run build   # ou npm run dev pour le live-reload — nécessite Node sur l'hôte
```

---

## Variables d'environnement (`.env`)

Copier `.env.example` et renseigner les valeurs :

```
APP_KEY=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

MAIL_MAILER=
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=

SEEDER_SUPER_ADMIN_EMAIL=
SEEDER_SUPER_ADMIN_PASSWORD=

SWEET_ALERT_ALWAYS_LOAD_JS=true
```

`SWEET_ALERT_ALWAYS_LOAD_JS` : le package `realrashid/sweet-alert` ne charge son JS que si
un flash de session (`alert.config`/`alert.delete`) est présent — à `true`, il est chargé sur
toute page, nécessaire pour les popups de confirmation Valider/Invalider du dashboard.

---

## Routes API

Préfixe : `/api/v1/`

| Méthode | Route | Auth | Description |
|---|---|---|---|
| POST | `/auth/register` | Non | Inscription |
| POST | `/auth/login` | Non | Connexion |
| POST | `/auth/logout` | Oui | Déconnexion |
| GET | `/auth/me` | Oui | Profil utilisateur |

---

## Routes admin (Blade)

| Méthode | Route | Auth | Description |
|---|---|---|---|
| GET | `/dashboard` | rôle `admin` | Tableau de bord (personnages, valider/invalider) |
| GET | `/users` | rôle `admin` | Utilisateurs sans personnage |
| DELETE | `/users/{user}` | rôle `admin` | Supprimer un utilisateur |
| PATCH | `/characters/{character}/validate` | rôle `admin` | Bascule `is_validated` |
| GET/PATCH | `/profile` | connecté | Profil de l'admin connecté |

`dashboard`, `users`, `users.destroy` et `characters.validate` nécessitent le rôle Spatie
`admin` (middleware `role:admin`) en plus de `auth`+`verified`. Pour attribuer ce rôle à un
compte :
```bash
docker exec odc-backend php artisan tinker --execute="
\App\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
\App\Models\User::where('email', 'ton@email.com')->first()->assignRole('admin');
"
```
`UserSeeder` l'attribue automatiquement au compte `SEEDER_SUPER_ADMIN_EMAIL`.

---

## Base de données

**MySQL 5.7** — base `office-des-coffres`

| Table | Description |
|---|---|
| `users` | Comptes utilisateurs |
| `characters` | Personnages liés aux utilisateurs |
| `kingdoms` / `provinces` / `cities` | Géographie du jeu |
| `roles` / `permissions` | RBAC Spatie |
| `model_has_roles` / `model_has_permissions` / `role_has_permissions` | Pivots Spatie |

---

## Tests

```bash
docker exec odc-backend php artisan test                         # Tous les tests (Pest)
docker exec odc-backend php artisan test --filter AuthTest       # Tests API auth uniquement
docker exec odc-backend ./vendor/bin/pint --test                 # Vérifier le style (sans corriger)
docker exec odc-backend ./vendor/bin/pint                        # Corriger le style
```

Base SQLite in-memory configurée dans `phpunit.xml`. Attention : `CharacterFactory` utilise `RAND()` (MySQL) — passer `'city_id' => null` explicitement dans les factories de test.

41 tests verts au 03/08/2026 (`Api/AuthTest`, `Auth/*`, `DashboardTest`, `ProfileTest`, `Unit/ExampleTest`).

---

## Conventions

- Ne jamais committer les credentials — utiliser `.env`
- Backend en français uniquement — ajouter les traductions dans `lang/fr.json` au fil du développement
- Tests Pest obligatoires pour chaque nouvelle fonctionnalité, avant commit
- Branches : `feat/<nom>`, `fix/<nom>`, `chore/<nom>` — jamais directement sur `master`
- Commits et push uniquement à la demande explicite
