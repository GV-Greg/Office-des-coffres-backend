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
│   │   ├── Auth/               # Authentification Breeze (Blade)
│   │   ├── Web/
│   │   │   └── DashboardController.php  # Dashboard admin + liste utilisateurs
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

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run dev
php artisan serve
```

---

## Variables d'environnement (`.env`)

```
APP_KEY=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=465
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=

SEEDER_SUPER_ADMIN_EMAIL=
SEEDER_SUPER_ADMIN_PASSWORD=
```

---

## Routes API

Préfixe : `/api/v1/`

| Méthode | Route | Auth | Description |
|---|---|---|---|
| POST | `/auth/register` | Non | Inscription |
| POST | `/auth/login` | Non | Connexion |
| POST | `/auth/logout` | Oui | Déconnexion |
| GET | `/auth/user` | Oui | Profil utilisateur |

---

## Routes admin (Blade)

| Méthode | Route | Description |
|---|---|---|
| GET | `/dashboard` | Tableau de bord (personnages) |
| GET | `/users` | Utilisateurs sans personnage |
| DELETE | `/users/{user}` | Supprimer un utilisateur |
| GET/PATCH | `/profile` | Profil de l'admin connecté |

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

## Conventions

- Ne jamais committer les credentials — utiliser `.env`
- Backend en français uniquement — ajouter les traductions dans `lang/fr.json` au fil du développement
- Branches : `feat/<nom>`, `fix/<nom>`, `chore/<nom>` — jamais directement sur `master`
- Commits et push uniquement à la demande explicite
