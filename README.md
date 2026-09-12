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
| Laravel Passport | 13.x | Authentification API (OAuth2 — access + refresh token, « Rester connecté ») |
| Spatie Permission | 6.x | Gestion des rôles et permissions |
| MySQL | 5.7 | Base de données |
| Vite | 6.x | Build des assets Blade |

---

## Structure du projet

Voir `docs/ARCHITECTURE.md` (source unique — modèles, routes, contrôleurs, middleware, flux
d'inscription/vérification). `resources/views/{auth,layouts,components,profile}/` : scaffolding
Breeze standard, non modifié.

---

## Déploiement

### Production

Déploiement automatique via **GitHub Actions** sur push `main` :
1. Build des assets Vite (`npm run build`)
2. Transfert FTP vers `odc-admin.creacube.be` (O2Switch) — `vendor/` exclu

Après chaque déploiement, lancer manuellement en SSH :
```bash
composer install --no-dev --optimize-autoloader
php artisan migrate
php artisan config:clear
php artisan view:clear
```

**Une seule fois** (première mise en place de Passport, ou si les clés/le client OAuth doivent
être régénérés) — génère les clés + le client OAuth et les écrit dans `.env` sans jamais afficher
la clé privée en clair dans le terminal :
```bash
cd /chemin/vers/le/déploiement/back
openssl genrsa -out /tmp/oauth-private.key 4096
openssl rsa -in /tmp/oauth-private.key -pubout -out /tmp/oauth-public.key
PRIV=$(awk 'BEGIN{ORS="\\n"}1' /tmp/oauth-private.key)
PUB=$(awk 'BEGIN{ORS="\\n"}1' /tmp/oauth-public.key)
printf 'PASSPORT_PRIVATE_KEY="%s"\n' "$PRIV" >> .env
printf 'PASSPORT_PUBLIC_KEY="%s"\n' "$PUB" >> .env
rm /tmp/oauth-private.key /tmp/oauth-public.key

echo "PASSPORT_PASSWORD_CLIENT_ID=$(uuidgen)" >> .env
echo "PASSPORT_PASSWORD_CLIENT_SECRET=$(openssl rand -base64 30)" >> .env

php artisan migrate:fresh --seed   # recrée aussi le client OAuth (PassportClientSeeder)
```
**Format attendu** : le PEM tient sur **une seule ligne**, sauts de ligne réels remplacés par des
`\n` littéraux (`config/passport.php` fait `str_replace('\\n', "\n", ...)` à la lecture) — **pas**
de base64. Si `.env` ne se termine pas par un retour à la ligne avant ce bloc, vérifier après coup
qu'aucune ligne ne s'est retrouvée collée à la précédente (`grep -c '^PASSPORT_' .env` doit
retourner 4).

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

FRONTEND_URL=

SEEDER_SUPER_ADMIN_EMAIL=
SEEDER_SUPER_ADMIN_PASSWORD=

PASSPORT_PASSWORD_CLIENT_ID=
PASSPORT_PASSWORD_CLIENT_SECRET=
PASSPORT_PRIVATE_KEY=
PASSPORT_PUBLIC_KEY=

SWEET_ALERT_ALWAYS_LOAD_JS=true
```

`PASSPORT_PASSWORD_CLIENT_ID`/`_SECRET` : identifiants du client OAuth interne utilisé par
`AuthController` pour émettre les tokens de connexion (`PassportClientSeeder`, rejoué à chaque
`migrate:fresh --seed`). `PASSPORT_PRIVATE_KEY`/`PASSPORT_PUBLIC_KEY` : clés de chiffrement
Passport en variables d'environnement plutôt qu'en fichiers `storage/oauth-*.key` — évite un
problème de permissions sous WSL/DrvFs en dev, rien à déposer en plus de `.env` en prod. Voir
`docs/DECISIONS.md` pour le détail, et la commande de génération ci-dessous.

`SWEET_ALERT_ALWAYS_LOAD_JS` : le package `realrashid/sweet-alert` ne charge son JS que si
un flash de session (`alert.config`/`alert.delete`) est présent — à `true`, il est chargé sur
toute page, nécessaire pour les popups de confirmation Valider/Invalider du dashboard.

`FRONTEND_URL` : URL du frontend Vue, utilisée par `AuthController::verifyEmail()` pour
rediriger après validation du lien de vérification signé (`?token=...` ou `?error=invalid`).
En local : `http://localhost:5001`.

En local (`.env` de dev), `MAIL_MAILER=log` : les emails (dont celui de vérification) ne
sont pas réellement envoyés, ils sont écrits dans `storage/logs/laravel.log` — récupérer le
lien signé `verify-email` depuis ce fichier pour tester le flux de bout en bout sans SMTP.

---

## Routes API

Préfixe : `/api/v1/`

| Méthode | Route | Auth | Description |
|---|---|---|---|
| POST | `/auth/register` | Non | Inscription (email + mot de passe uniquement, envoie l'email de vérification) |
| POST | `/auth/login` | Non | Connexion par email — bloque (403) si l'email n'est pas vérifié, sinon émet un couple access/refresh token (`remember_me` optionnel) |
| POST | `/auth/refresh` | Non (le `refresh_token` fait foi) | Renouvelle le couple access/refresh token |
| POST | `/auth/resend-verification` | Non | Renvoie l'email de vérification (`throttle:6,1`) |
| GET | `/auth/verify-email/{id}/{hash}` | Signé | Valide le lien reçu par email, émet un access token (sans refresh), redirige vers `FRONTEND_URL` |
| POST | `/auth/logout` | Oui | Déconnexion — révoque l'access token courant et son refresh token |
| GET | `/auth/me` | Oui | Profil utilisateur (avec la liste de ses personnages) |
| GET | `/characters` | Oui | Liste des personnages du compte connecté (avec ville/province/royaume) |
| POST | `/characters` | Oui | Crée un personnage (pseudo + ville obligatoires) |
| PATCH | `/characters/{id}` | Oui | Change la ville d'un personnage (le sien uniquement, 404 sinon) — repasse `is_validated` à `false` et `pending_residence_change` à `true` |
| GET | `/map` | Non | Arbre royaumes → provinces → villes (sélecteur de ville) |

Un compte peut avoir plusieurs personnages, chacun validé individuellement via le dashboard
admin (`characters.validate`) — la connexion n'est plus bloquée par un personnage non validé,
seul l'email doit être vérifié.

---

## Routes admin (Blade)

| Méthode | Route | Auth | Description |
|---|---|---|---|
| GET | `/dashboard` | rôle `admin` | Tableau de bord (personnages, valider/invalider) |
| GET | `/users` | rôle `admin` | Tous les comptes, recherche par email ou pseudo de personnage, colonnes email vérifié + personnage(s) |
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
| `characters` | Personnages liés aux utilisateurs (`city_id` obligatoire à la création, `pending_residence_change` distingue "en attente : nouveau" de "en attente : changement de résidence") |
| `rk_kingdoms` / `rk_provinces` / `rk_cities` | Géographie du jeu (seedées via `MapSeeder`) |
| `roles` / `permissions` | RBAC Spatie |
| `model_has_roles` / `model_has_permissions` / `role_has_permissions` | Pivots Spatie |

---

## Tests

```bash
docker exec odc-backend composer pest              # Tous les tests, one-shot
docker exec odc-backend composer test:auth         # Un domaine ciblé — voir docs/TESTS.md
docker exec odc-backend ./vendor/bin/pint --test    # Vérifier le style (sans corriger)
docker exec odc-backend ./vendor/bin/pint           # Corriger le style
```

Base SQLite in-memory configurée dans `phpunit.xml`. Attention : `CharacterFactory` utilise `RAND()` (MySQL) — passer `'city_id' => null` explicitement dans les factories de test.

81 tests verts au 13/09/2026 (`Feature/Api/{AuthTest,CharacterControllerTest,MapTest}`,
`Feature/Auth/*`, `Feature/{DashboardTest,ProfileTest}`, `Unit/Enforcement/CookieUsageTest`) —
scripts et découpage détaillés dans `docs/TESTS.md`.

---

## Conventions

Voir `CLAUDE.md` à la racine du workspace (source unique) et `admin/strategies/git.md` pour le
nommage de branche.
