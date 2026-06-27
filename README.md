# Office des Coffres — Backend

> Outil communautaire non officiel pour le jeu [Renaissance Kingdoms](https://www.renaissancekingdoms.com/).
> Ce site n'est pas affilié à Celsius Online, l'éditeur du jeu.

API REST consommée par le frontend Vue.js.
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
| Pest | 3.x | Tests |
| Larastan | 3.x | Analyse statique (PHPStan) |
| Laravel Pint | 1.x | Formatage du code |

---

## Structure du projet

```
app/
├── Http/
│   ├── Controllers/            # Contrôleurs API
│   └── Middleware/             # Middleware personnalisés
├── Models/
│   ├── User.php                # Utilise HasRoles (Spatie)
│   ├── Role.php                # Étend SpatieRole
│   ├── Permission.php          # Étend SpatiePermission
│   └── Team.php                # Modèle de base
└── Providers/
    └── AppServiceProvider.php  # Rate limiter API + events

bootstrap/
├── app.php                     # Configuration Laravel 12 (fluent API)
└── providers.php               # Liste des service providers

config/
├── permission.php              # Configuration Spatie Permission
└── sanctum.php                 # Configuration Sanctum

database/migrations/
├── create_users_table
├── create_permission_tables    # Spatie (roles, permissions, pivots)
├── create_kingdoms_table
├── create_provinces_table
├── create_cities_table
└── create_characters_table
```

---

## Démarrage rapide (Docker)

L'environnement complet (frontend + backend + BDD) se lance depuis la racine du workspace :

```bash
cd /chemin/vers/ODC
docker compose -f docker-compose.dev.yml up
```

L'API est disponible sur **http://localhost:8000/api/v1/**.

Le conteneur backend exécute automatiquement au démarrage :
- `composer install` (si vendor/ absent)
- `php artisan key:generate` (si APP_KEY manquant)
- `php artisan migrate`
- `php artisan config:clear && route:clear`

### Développement standalone (sans Docker)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

### Variables d'environnement requises (`.env`)

```
APP_KEY=                          # Généré automatiquement
DB_HOST=db                        # Nom du service Docker
DB_DATABASE=office-des-coffres
DB_USERNAME=odc
DB_PASSWORD=odc

SEEDER_SUPER_ADMIN_EMAIL=         # Email du premier admin
SEEDER_SUPER_ADMIN_PASSWORD=      # Mot de passe du premier admin
```

---

## Base de données

**MySQL 5.7** — base `office-des-coffres`

En développement Docker, les données sont persistées dans `.docker/mysql-data/` (dossier ignoré par git, sur le système de fichiers Windows pour éviter la perte de données en cas de crash WSL).

### Schéma RBAC (Spatie Permission)

| Table | Rôle |
|---|---|
| `roles` | Définition des rôles |
| `permissions` | Définition des permissions |
| `model_has_roles` | Attribution de rôles aux utilisateurs |
| `model_has_permissions` | Attribution de permissions directes |
| `role_has_permissions` | Association rôle ↔ permissions |

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

## Tests

```bash
php artisan test              # Tests Pest
./vendor/bin/phpstan analyse  # Analyse statique Larastan
./vendor/bin/pint             # Formatage du code
```

---

## Conventions

- Ne jamais committer les credentials — utiliser `.env`
- Branches : `feat/<nom>`, `fix/<nom>`, `chore/<nom>` — jamais directement sur `main`
- L'API ne renvoie jamais de données sensibles (tokens Sanctum, mots de passe)
