# Architecture technique — Backend (Laravel 12)

> Référence structurelle chargée automatiquement (voir `CLAUDE.md` racine). Mise à jour :
> 04/10/2026. Vérifier le code avant de citer un détail précis si ce fichier date de plus de
> quelques semaines.

Deux usages distincts cohabitent dans ce repo :
1. **API REST** (`/api/v1/*`, Passport — access token 1 h + refresh token 30 j/12h selon
   « Rester connecté », voir `docs/DECISIONS.md`) — consommée par le frontend Vue, utilisateurs
   publics du jeu.
2. **Admin Blade** (`web.php`) — panneau d'administration de Greg, session-based (`web` guard).

Ces deux mondes ont chacun leur propre flux de vérification d'email, indépendants l'un de
l'autre : Breeze/Blade (session, `verification.verify`) pour les comptes admin, et un second
flux API (`verification.verify.api`, voir plus bas) pour les comptes joueurs. Les comptes Blade
admin n'ont pas de `Character`.

## Modèles & relations

| Modèle | Table | Champs notables | Relations |
|---|---|---|---|
| `User` | `users` | `email`, `password` (pas de `name`), `last_seen_at`, `deletion_notice_sent_at` (voir « Dernier passage ») | `hasMany(Character)` — **un compte peut avoir plusieurs personnages** |
| `Character` | `characters` | `user_id`, `pseudo` (unique via validation app, pas contrainte DB), `city_id` (obligatoire à la création), `is_validated` (bool, défaut `false`), `pending_residence_change` (bool — distingue « nouveau personnage » d'un « changement de résidence » à revalider) | `belongsTo(User)`, `belongsTo(City)` |
| `Kingdom` | `rk_kingdoms` | `kingdom_name` | `hasMany(Province)` |
| `Province` | `rk_provinces` | `province_name` | `belongsTo(Kingdom)`, `hasMany(City)` |
| `City` | `rk_cities` | `city_name`, `is_capital` | `belongsTo(Province)`, `hasMany(Character)` ⚠️ bug : FK déclarée `'user_id'` au lieu de `'city_id'` — relation cassée, non utilisée actuellement |
| `Role`/`Permission` | Spatie | — | extensions vides de Spatie Permission |
| `CouncilOffice` | `council_offices` | `key`, `position` — **aucun libellé en base** | les 10 postes titrés, semés par migration (voir « Mandats ») |
| `MayorMandate` | `mayor_mandates` | `status`, dates nominales et effectives, motif | `belongsTo(Character)`, `belongsTo(City)` |
| `CouncilMandate` | `council_mandates` | idem + `pending_office_id`, `office_requested_at` | `belongsTo(Character)`, `belongsTo(Province)`, `hasMany(CouncilOfficePeriod)` |
| `CouncilOfficePeriod` | `council_office_periods` | `started_at`, `ended_at`, `end_reason` | `belongsTo(CouncilMandate)`, `belongsTo(CouncilOffice)` |
| `Team` | — | — | **mort** : pas de migration, teams désactivé (`config/permission.php`) |

**Cookies/consentement : aucun stockage backend.** Pas de modèle, migration ou colonne liée aux
cookies — tout le consentement RGPD vit côté frontend (localStorage, voir
`admin/strategies/cookies.md`). Un futur palier "données de jeu liées au compte" (ex. future
liste rouge du module Douane) nécessiterait une vraie table, hors scope actuel.

## Schéma DB (migrations, dans l'ordre)

1. `users`, `password_reset_tokens`, `failed_jobs` — scaffolding standard
2. Tables Passport (`oauth_clients`, `oauth_auth_codes`, `oauth_access_tokens`,
   `oauth_refresh_tokens`, `oauth_device_codes`) — stubs par défaut, pas de personnalisation
3. `rk_kingdoms` → `rk_provinces` (FK cascade) → `rk_cities` (FK cascade, `is_capital` bool)
4. `characters` (`user_id` FK cascade, `city_id` FK cascade nullable, `is_validated` bool, puis
   `pending_residence_change` bool ajouté par une migration ultérieure)
5. Tables Spatie Permission v6 (`permissions`, `roles`, `model_has_*`, `role_has_permissions`) — mode non-teams, migration depuis un ancien Laratrust (drop des tables `permission_user`/`permission_role`/`role_user` en préambule)

6. Mandats (03/10/2026) : `council_offices`, puis `mayor_mandates` + `council_mandates` +
   `council_office_periods`, puis une migration qui **appelle le seeder idempotent**
   `CouncilOfficeSeeder` (voir « Mandats »)

7. `users.last_seen_at` + `deletion_notice_sent_at` (04/10/2026, voir « Dernier passage »), puis
   `policy_notifications` (voir « Notification des modifications de la politique »)

Pas de table `sessions`/`cache` (drivers `file`).

## Origine canonique (`public/.htaccess`)

Tout passe par `https://odc-admin.creacube.be` : `www` et `http://` redirigent (301 depuis
le 01/10/2026, après une phase en 302), chemin et paramètres conservés, `/.well-known/` exclu — le panneau
admin et l'API répondaient sous quatre variantes, dont deux en clair (29/09/2026). Règle placée
avant la redirection des slashs finaux (une 301) et le front controller. Un `POST` en `http://`
échoue volontairement (rejoué en `GET` → 405) plutôt que d'être rejoué en https : le mot de passe
serait déjà parti en clair. Pas de HSTS. Garde-fous : `Unit/Enforcement/HtaccessCanonicalOriginTest`
et l'étape « origine canonique » de `deploy.yml`, qui sonde les quatre variantes en prod.

## Routes

### `routes/api.php` (préfixe `/api/v1`)

| Méthode | URI | Contrôleur | Middleware |
|---|---|---|---|
| POST | `auth/register` | `Api\AuthController@register` | public, `throttle:register` (5/min/IP) |
| POST | `auth/login` | `Api\AuthController@login` | public, `throttle:login` (5/min par email+IP, 30/min par IP) |
| POST | `auth/resend-verification` | `Api\AuthController@resendVerification` | public, `throttle:6,1` |
| GET | `auth/verify-email/{id}/{hash}` | `Api\AuthController@verifyEmail` | `signed` (nommée `verification.verify.api`) |
| POST | `auth/refresh` | `Api\AuthController@refresh` | public (le `refresh_token` fait foi), `throttle:refresh` (20/min/IP) |
| POST | `auth/logout` | `Api\AuthController@logout` | `auth:api` |
| GET | `auth/me` | `Api\AuthController@me` | `auth:api` |
| DELETE | `auth/account` | `Api\AuthController@destroyAccount` | `auth:api` — suppression self-service (art. 17 RGPD) |
| GET | `characters` | `Api\CharacterController@index` | `auth:api` |
| POST | `characters` | `Api\CharacterController@store` | `auth:api` |
| PATCH | `characters/{character}` | `Api\CharacterController@update` | `auth:api` — changement de résidence |
| GET | `map` | `Api\MapController@index` | public — arbre royaumes→provinces→villes, pour les sélecteurs de ville |
| GET | `characters/{character}/province` | `Api\ProvinceHistoryController@show` | `auth:api` — « Ma province » : historique des postes de la province de **résidence** |

**Plancher de toute l'API** : `throttleApi()` (`bootstrap/app.php`) applique le limiteur `api`
(60/min par utilisateur Passport, sinon par IP) à tout `/api/*`. Limiteurs déclarés dans
`AppServiceProvider` ; 429 rendu en JSON français (`success: false`) par `bootstrap/app.php`.
⚠️ Le limiteur `api` a existé des mois **sans être branché** — une déclaration ne prouve rien,
c'est `RateLimitTest.php` qui fait foi.

`auth:api` = guard **Passport** (`config/auth.php`), plus Sanctum — voir `docs/DECISIONS.md` pour
l'ADR de bascule.

| GET | `council-offices` | `Api\MandateController@offices` | public — les 10 postes titrés (`key`) |
| GET | `mandates` | `Api\MandateController@index` | `auth:api` — mandats de tous les personnages du compte, statut effectif |
| POST | `characters/{character}/mandates` | `Api\MandateController@store` | `auth:api`, `throttle:6,1` |
| POST | `mandates/{level}/{id}/renew` | `Api\MandateController@renew` | `auth:api`, `throttle:6,1` |
| POST | `mandates/council/{id}/office` | `Api\MandateController@declareOffice` | `auth:api`, `throttle:6,1` — « Déclarer mon poste » |
| DELETE | `mandates/{level}/{id}` | `Api\MandateController@destroy` | `auth:api` — sa propre demande en attente |

### `routes/web.php` (admin Blade)

| Méthode | URI | Nom | Contrôleur | Middleware |
|---|---|---|---|---|
| GET | `dashboard` | `dashboard` | `Web\DashboardController@index` | `auth,verified,role:admin` |
| GET | `users` | `users` | `Web\DashboardController@users` | `auth,verified,role:admin` |
| DELETE | `users/{user}` | `users.destroy` | `Web\DashboardController@destroyUser` | `auth,verified,role:admin` |
| PATCH | `characters/{character}/validate` | `characters.validate` | `Web\DashboardController@toggleValidation` | `auth,verified,role:admin` |
| GET/POST | `mandates/…` | `mandates.*` | `Web\MandateAdminController` (5 pages dont « Historique », 15 gestes) | `auth,verified,role:admin` |
| GET/PATCH/DELETE | `/profile` | `profile.*` | `ProfileController` | `auth,verified` (self-service admin, pas un outil de gestion d'autres users) |

`routes/auth.php` : scaffolding Breeze standard (register/login/logout Blade, reset password,
vérification email) — **non modifié**, guard `web`, sans lien avec l'API.

## Contrôleurs

- `Api\AuthController` — `register` (crée uniquement le `User`, envoie l'email de vérification),
  `verifyEmail` (valide le lien signé, marque l'email vérifié, émet un token d'accès Passport via
  le grant `personal_access` — pas de refresh token à cette étape, pas de mot de passe disponible
  dans ce flux —, **redirige vers `FRONTEND_URL`**), `resendVerification`, `login` (par **email**,
  pas pseudo — bloque avec 403 "Email non vérifié." si
  `! hasVerifiedEmail()`, puis émet le couple access+refresh token via le grant `password` de
  Passport — voir `docs/DECISIONS.md`), `refresh` (renouvelle le couple de tokens à partir d'un
  refresh token valide), `logout` (révoque l'access token courant et son refresh token associé),
  `me`, `destroyAccount` (suppression self-service : exige le mot de passe courant en plus du
  jeton, supprime le `User` — personnages en cascade —, les jetons d'accès Passport et leurs
  refresh tokens, le tout en transaction ; les tables OAuth n'ayant pas de FK vers `users`, elles
  ne se videraient pas seules). `login`/`me` renvoient `user.characters` (liste, pas un
  pseudo/statut unique).
- `Api\CharacterController` — `store` (crée un personnage pour l'utilisateur connecté, pseudo +
  ville obligatoires), `index` (liste les personnages du compte connecté), `update` (changement de
  ville de résidence : repasse `is_validated` à `false` et lève `pending_residence_change`, le
  personnage doit être revalidé depuis le dashboard admin).
- `Api\MapController` — `index`, arbre `Kingdom::with(['provinces.cities'])`, public, pas de
  pagination (~300 villes, volume géré en un seul payload pour un sélecteur cascade côté front).
  **Mis en cache** par `App\Support\MapTree` (store par défaut, TTL 7 jours) : le cache porte
  le tableau, pas la réponse HTTP. ⚠️ Il survit au déploiement : sa clé
  (`api.map.tree.<empreinte>`) est empreintée sur `MapTree.php` et les trois modèles, et un test
  de forme garde le reste (colonne ajoutée par migration → `cache:clear` en prod). Vidé par le trait `Models\Concerns\FlushesMapTree`
  (`saved`/`deleted`) sur `Kingdom`/`Province`/`City` — aucun écran admin n'écrit ces tables,
  seul `MapSeeder` ; le TTL couvre les écritures hors Eloquent (SQL direct, `update()` de masse).
- `Web\DashboardController` — `index` (liste personnages paginée, eager-load
  `user`/`city.province.kingdom`), `toggleValidation`, `users` (liste **tous** les users, avec
  recherche), `destroyUser`.
- `Web\MapController` — **mort** : vue `map.list` référencée mais inexistante (voir « Finitions
  transversales » dans `roadmap.md`).
  Sans lien avec `Api\MapController` (nouveau, actif, JSON).
- `ProfileController` + `Auth/*` — scaffolding Breeze, non modifié.

- `Api\MandateController` / `Web\MandateAdminController` — mandats, voir la section « Mandats ».
  Ils ne valident que la forme : toutes les règles vivent dans `App\Services\MandateWorkflow`.

## Middleware & rôles

- Un seul middleware maison : `RecordLastSeen` (groupes `api` et `web`, voir « Dernier passage »).
- `bootstrap/app.php` enregistre l'alias `role` → `Spatie\Permission\Middleware\RoleMiddleware`.
- **Un seul rôle Spatie : `admin`**, seedé via `UserSeeder` sur `SEEDER_SUPER_ADMIN_EMAIL`. Aucune
  permission Spatie créée (uniquement le rôle, vérifié via `role:admin` ou `hasRole('admin')` côté
  Blade).

## Dernier passage (`last_seen_at`, 04/10/2026)

Mesure de l'inactivité promise par `/legal/privacy` §5 (brief `admin/content/brief-politique-promesses.md`,
fil `admin/echanges/politique-promesses`). **« Connexion » = toute requête authentifiée**, API comme
panneau Blade.

- `App\Support\LastSeen` est le **seul écrivain** : au plus une écriture par jour, gardée par une
  lecture du modèle déjà chargé ; un passage remet `deletion_notice_sent_at` à `null` (préavis
  annulé, pas suspendu). Écriture hors Eloquent : `updated_at` ne bouge pas.
- `App\Http\Middleware\RecordLastSeen` écrit dans `terminate()`, **après la réponse**.
- ⚠️ `register`, `verifyEmail`, `login` et `refresh` sont **hors du groupe `auth:api`** : le
  middleware ne les voit pas seul, elles désignent le compte par `LastSeen::markForRequest()`.
  Une nouvelle route publique qui authentifie doit faire de même.
- Lignes existantes remplies **à la date de la migration**, jamais à `created_at` : le compteur
  part du jour où on sait compter.

## Notification des modifications de la politique (§10, 04/10/2026)

`/legal/privacy` §10 promet que toute modification substantielle est notifiée par email (brief
`admin/content/brief-politique-promesses.md` §4, fil `admin/echanges/politique-promesses`, Q5-Q6).

| Pièce | Rôle |
|---|---|
| `resources/policy/changelog.php` | **source unique** du journal (aucune copie dans `admin/`) : date, résumé FR/EN, `substantial`, `decided_by` |
| `App\Support\PolicyChangelog` | lecture + contrôle de forme d'une entrée |
| `php artisan policy:notify <id>` | lancée **à la main** par Greg, jamais planifiée ; refuse une entrée inconnue, mal formée, non substantielle ou déjà envoyée ; comptes **vérifiés** seulement ; affiche le nombre de destinataires et demande confirmation (`--force` pour s'en passer) |
| `App\Notifications\PolicyUpdated` | email bilingue FR puis EN, lien vers `/legal/privacy`, **aucun lien de désinscription** (information légale) |
| `policy_notifications` | une ligne par entrée envoyée (anti double envoi). **Hors taxinomie** des données utilisateur tant qu'elle ne porte aucun lien vers un compte — une colonne par destinataire rouvrirait la question |

⚠️ N'ajouter une entrée substantielle au journal qu'une fois le texte **en ligne** : l'email renvoie
à la page. « Substantielle » est une décision humaine, jamais déduite d'un diff.

## Gestion des utilisateurs (admin)

Tout vit dans `Web\DashboardController` + `users.blade.php` :
- Liste **tous** les users, paginée (14), avec recherche (`?search=`) par email OU pseudo du
  personnage
  (`orWhereHas('characters', ...)`), `withQueryString()` pour garder le filtre à travers la
  pagination.
- Colonnes affichées : `id`, `email`, **personnage** (pseudo + badge validé/en attente, ou
  "Aucun personnage"), date d'inscription, badge email vérifié.
- Suppression = hard delete (pas de soft delete), cascade sur `characters` via FK.
- Pas d'édition d'un autre user, pas de reset de mot de passe admin, pas de gestion de rôles
  (un seul rôle existe).
- Tests : `tests/Feature/DashboardTest.php` couvre les 4 routes admin (guest / non-admin / admin)
  et la recherche de `/users`.

## Inscription / vérification d'email (comptes joueurs)

Flux en 3 étapes :

1. **`POST /api/v1/auth/register`** — email + password + confirmation uniquement. Crée le
   `User`, envoie `App\Notifications\VerifyApiEmail` (sous-classe de
   `Illuminate\Auth\Notifications\VerifyEmail`, texte 100% français, lien signé vers
   `verification.verify.api` au lieu de la route Blade). Pas de token émis à ce stade.
2. **Clic sur le lien** → `GET auth/verify-email/{id}/{hash}` (signé, sans session) → contrôleur
   vérifie `hash_equals(sha1($user->getEmailForVerification()), $hash)`, marque
   `markEmailAsVerified()`, émet un access token Passport (grant `personal_access`, pas de refresh
   token — pas de mot de passe disponible à cette étape du flux), **redirige (302)** vers
   `config('app.frontend_url') . '/verify-email?token=...'` (ou `?error=invalid`). Connexion
   automatique côté frontend à ce stade (décision Greg : pas de renvoi vers un formulaire de
   login après confirmation) — l'utilisateur repassera par `login()` normalement une fois ce
   jeton (sans refresh) expiré.
3. Une fois connecté, le joueur est invité à créer un ou plusieurs personnages via
   `POST /api/v1/characters` (pseudo + `city_id` obligatoires, `is_validated=false`).

**`login()` bloque sur l'email non vérifié** (403, "Email non vérifié."), puis émet le couple
access+refresh token Passport (grant `password`) — voir `docs/DECISIONS.md` pour l'architecture
« Rester connecté » (durées, `remember_me`). La validation **par personnage**
(`characters.validate`, dashboard admin) reste indépendante — elle ne bloque pas la connexion,
seulement l'accès aux fonctionnalités liées à ce personnage précis côté frontend (à affiner au fil
du développement des modules).

**`FRONTEND_URL`** — clé `.env`/`config('app.frontend_url')`, nécessaire pour construire l'URL de
redirection post-vérification.

**Pas de code de compatibilité ascendante** pour les comptes inscrits avant ce flux — décision
explicite de Greg (reprise à 0).

## Chiffrement des données de module

Aucune table de module n'existe encore, mais le mécanisme est en place et testé — chiffrer après
coup imposerait une migration de données sur des lignes de prod (ADR du 20/09/2026,
`admin/strategies/donnees-utilisateur.md` §5).

| Pièce | Rôle |
|---|---|
| `config/module_data.php` | `MODULE_DATA_KEY` + `MODULE_DATA_PREVIOUS_KEYS` (CSV) + cipher |
| `App\Support\ModuleDataEncrypter` | `Encrypter` dédié, gère le préfixe `base64:` comme `APP_KEY` |
| `App\Casts\EncryptedModuleData` | cast `:string` (défaut) ou `:array` pour un payload JSON |
| `AppServiceProvider` | binding paresseux du chiffreur + `key:generate` désactivée en production |

**La clé n'est jamais `APP_KEY`** : `key:generate` la fait tourner en routine, ce qui ne coûte
qu'une reconnexion, alors que la même rotation sur des données de module les rendrait
définitivement illisibles. `MODULE_DATA_PREVIOUS_KEYS` est le filet correspondant — clés essayées
au déchiffrement, jamais au chiffrement.

⚠️ **Chiffrer le contenu, jamais les axes.** `city_id`, `province_id`, `character_id`,
`reported_at` restent en clair : une colonne chiffrée n'est ni indexable, ni triable, ni
filtrable, l'IV aléatoire faisant échouer jusqu'à l'égalité. Filtrage en SQL, agrégation en PHP
après déchiffrement — acceptable à l'échelle de cette communauté, rédhibitoire sur un gros volume.

## Mandats (lot 1, 03/10/2026)

Qui a le droit d'écrire au titre d'un poste, pour les modules futurs. Spécification :
`admin/content/brief-mandats.md` ; **arbitrages qui la complètent et parfois la contredisent** :
`admin/echanges/mandats-lot1/` (Q1 à Q29), à lire avant toute modification.

| Pièce | Rôle |
|---|---|
| `App\Services\MandateAuthority` | **seul point d'entrée des modules** : `activeMayorMandate`, `activeCouncilMandate`, `holdsCouncilOffice(Character, string $key)` — « maintenant » seulement, aucun paramètre de date |
| `App\Services\MandateWorkflow` | **tous** les gestes (joueur et admin) ; seul écrivain de `holds_until` |
| `App\Support\MandateCalendar` | jours civils `Europe/Paris` (`addDays`, jamais `addMonth`), stockés en UTC |
| `App\Support\MandateLabels` | libellés FR/EN ; un motif sans libellé s'affiche par son **code**, jamais par la clé brute |
| `App\Notifications\MandateDecision` | email **toujours bilingue, français puis anglais**, synchrone, jamais la note |
| `config/mandates.php` | durées (30 / 60 j), prolongation (2 j), seuils, codes de motifs |

Règles qui tiennent l'ensemble :
- **Une seule fin effective** : l'autorité court de `in_office_from` à `holds_until`, quel que soit
  le statut. Échéance, prolongation, passation et révocation écrivent toutes `holds_until`, par
  `MandateWorkflow::setHoldsUntil()`, qui écrit **dans le même appel** `holds_until_set_by` et la fin
  de la période de poste en cours. `valid_until` (fin nominale) ne bouge qu'avec une correction de
  `started_at`.
- **Le poste détenu est la période en vigueur** de `council_office_periods` — il n'y a pas de
  `council_office_id` sur le mandat. Une période naît **fermée** à la fin du mandat
  (`end_reason = fin_du_mandat`), n'est jamais rouverte ni réécrite. **Exclusivité** dans une
  province : impossible à exprimer en MySQL, gardée par le test `officeOverlaps()` de
  `MandateOfficeTest` — seul garde-fou.
- Un poste ne se pose que sur un mandat **en fonction**. Le gagner le retire à son détenteur, à la
  même seconde. **Tous les postes sont vidés à l'élection d'un dirigeant** (passation ou démission
  du comte) : une province sans aucune autorité de poste est un **état légitime**.
- **Une réponse négative de `MandateAuthority` veut dire « l'Office ne connaît pas »**, pas « le
  siège est vacant » : seuls les mandats déclarés par les joueurs sont connus.
- `character_id` en `cascadeOnDelete` (catégorie A, **portante** pour l'anonymisation §2-C) ; les
  périodes suivent par `council_mandate_id`.
- **Langue** : `lang/fr/mandates.php` complet (email, API, admin) ; `lang/en/mandates.php` limité à
  ce qui part en anglais (l'email). Les pages Blade des mandats n'ont aucun texte en dur. Les pages
  Blade antérieures restent à reprendre (passe de traduction à part).
- ⚠️ **Prod** : le déploiement ne lance pas les migrations ; celle qui sème les 10 postes appelle
  le seeder idempotent (couplage voulu, commenté). Les 10 titres anglais sont **relevés en jeu**
  (Connétable = Sergeant, Prévôt des maréchaux = Constable) : jamais traduits.

**Lot 2 — contrat de l'API joueur** (fil `admin/echanges/mandats-lot2/`) :
- **Toute réponse d'erreur** des routes des mandats est complète (`App\Support\MandateApiErrors`,
  branché dans `bootstrap/app.php`) : une 422 porte `errors` (inchangé), `codes` et
  `messages[champ] = {fr, en}` — refus métier (`App\Exceptions\MandateRefusal`, code déduit de la
  clé `mandates.api.<code>`) comme validation de forme (`validation.<règle>`, noms de champs dans
  `lang/{fr,en}/validation.php`) ; un 404 ou un 429 porte `code` et `messages` au premier niveau.
  `messages.fr` est toujours la chaîne d'`errors`. Les refus d'administration ont un code `admin.…`
  que l'API **refuse d'émettre** (500). Aucune table code → texte côté frontend.
- `GET /mandates` renvoie aussi `characters[].requestable.{mayor,council} = {can_request,
  blocked_by}` (la règle de la demande, exposée telle quelle) et, sur chaque mandat, les libellés
  `{fr, en}` des titres, motifs et causes de fin ; `GET /council-offices` sert les titres avec leur
  `label`. **Pas de `status_label`** : un statut est une présentation du frontend.

**Lot 3 — tâches planifiées** (`routes/console.php`, chaque jour à 09:00 Paris ; fil
`admin/echanges/mandats-lot3/`) :

| Commande | Effet |
|---|---|
| `mandates:send-reminders` | rappel bilingue au joueur **2 jours après la fin effective** de son mandat, tant qu'il est renouvelable (avant, l'élection est en cours) ; jamais deux fois (`reminder_sent_at`), jamais si un renouvellement existe (en attente ou validé), jamais pour un révoqué, jamais à un maire dont la mairie a un successeur validé |
| `mandates:verification-digest` | un email par jour aux comptes `admin` tant que la file de vérification a des lignes en retard (4 j maire, 7 j conseiller titré), chaque ligne avec son ancienneté ; file sans retard = aucun email |
| `mandates:purge-rejected` | supprime les `rejected` de plus de 3 mois (`processed_at`) ; une demande en attente n'est **jamais** purgée |
| `mandates:status` | lecture seule : dernier passage réussi de chaque tâche |

`App\Support\MandateHeartbeat` : chaque tâche écrit en cache son dernier passage **réussi**, en fin
d'exécution. Au-delà de 36 h, `/dashboard` (**le garde-fou**) et l'en-tête des pages Mandats (une
commodité) affichent une alerte qui nomme la tâche. ⚠️ Rien ne tourne en prod sans la tâche cron
`schedule:run` créée dans cPanel (voir `CLAUDE.md`, « Pièges de prod »).

**Historique des postes** (fil `admin/echanges/mandats-historique`, 03/10/2026) :

| Pièce | Rôle |
|---|---|
| `App\Services\OfficeHistory` | **la seule fonction de fusion** des deux sources : tables vivantes (personnages existants) + `office_history_archive` (personnages supprimés). Appelée par l'onglet Blade « Historique » **et** par l'API « Ma province ». Tri total (début, titre, ville, fin, pseudo) |
| `office_history_archive` | ce qui **survit** à la suppression d'un compte (décision de Greg : l'information est publique en jeu). **Catégorie D**, aucune clé vers un compte ou un personnage : pseudo en texte, lieu en id **et** en texte, titre par sa clé. Écrite au moment de la suppression, jamais modifiée ensuite — un personnage est vivant **ou** archivé, jamais les deux |
| `App\Services\AccountDeletion` | **la porte unique** de suppression d'un compte ou d'un personnage : archive, puis supprime. ⚠️ La cascade SQL ne prévient aucun personnage : un `$user->delete()` écrit ailleurs effacerait l'historique en silence. `Enforcement/AccountDeletionTest` échoue sur toute suppression de `User`/`Character` hors de ce service, et vérifie les trois chemins réels (API, admin, profil Breeze) |
| `App\Support\GameCalendar` | année du jeu côté serveur (2026 → 1474), pour les **emails** seulement — l'API n'envoie que des dates réelles. ⚠️ **Jumeau** de `src/modules/gameCalendar.js` (frontend) : `Unit/GameCalendarTwinTest` fige la table **et** des conversions, son jumeau frontend aussi |

Emails : toujours adressés au **joueur**, le personnage nommé ; date réelle d'abord, date de jeu entre
parenthèses (`:date (:date_jeu)`), sauf pour un acte de l'Office (`:date` seule). La famille de chaque
chaîne datée est figée par `MandateLanguageTest`.

Hors lots 1 et 3 : export art. 20 (lot export, sans la `note`).

## Tests

`docker exec odc-backend php artisan test` — décompte à jour dans `README.md` (source unique,
pas dupliqué ici). `CharacterFactory` utilise
`RAND()` MySQL/MariaDB pour `city_id` par défaut → passer `city_id: null` explicitement dans les tests
(incompatible SQLite/CI). Factories `KingdomFactory`/`ProvinceFactory`/`CityFactory` disponibles
pour monter une carte de test. Tester un
lien signé : construire l'URL directement avec `URL::temporarySignedRoute('verification.verify.api', ...)`
dans le test plutôt que parser le contenu de l'email (voir `AuthTest.php`).

## Contraintes projet

- Backend : **aucun texte en dur** — `__()` partout, `lang/fr` complet, `lang/en` seulement pour ce
  qui est réellement rendu en anglais (aujourd'hui les emails des mandats). Interfaces Blade en
  français, sans bascule de langue (arbitrage du 03/10/2026, fil `mandats-lot1`, Q5).
- Ne jamais committer `.env`/credentials.
