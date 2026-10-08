# Changelog — Office des Coffres (backend)

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/). Une entrée par PR
mergée sur `main` (`master` avant le 09/08/2026, voir `admin/strategies/git.md` §10 — ou merge
direct pour les deux entrées antérieures aux PR GitHub). Pas de versionnage sémantique — chaque
merge sur `main` déclenche un déploiement, la date de merge fait foi. L'historique détaillé
(raisonnement, incidents, décisions) vit dans `admin/suivi/*.md` et `admin/archives/` à la racine
du workspace ; ce fichier n'en retient que le résumé daté.

## [2026-10-08] — PR #58 (Registre des mines : accès affichable)

### Added
- `GET /api/v1/characters/{character}/mine-registry` → `{write, read}` : province (id, nom) où le
  personnage peut écrire / consulter **maintenant**, ou `null`. L'écran du Bilan l'affiche **avant**
  l'envoi (brief §2) ; la règle reste au backend (`canManageMines` / `canReadMines`).
- Réponses d'écriture : `report.province_name`, et `office_label {fr, en}` sur l'auteur remplacé.
- 🐛 Le relevé analysé garde désormais le **libellé** et la **ressource** de chaque mine : sans règle de
  validation, Laravel les retirait (relevé enregistré en dev par Greg le 08/10). Contrôle positif fait.
- ⚠️ **Route ajoutée** : `php artisan route:cache` en SSH après le déploiement (sinon 404).

## [2026-10-08] — PR #57 (Registre des mines, PR 1b : écriture)

Brief `admin/content/brief-registre-mines.md` §3, §6 ; fil `admin/echanges/registre-mines` (R3, R3 bis).
Ouverte après `policy:notify 2026-10-08-registre-des-mines` (texte d'abord).

### Added
- `POST /api/v1/characters/{character}/mine-reports` (`auth:api`, `throttle:6,1`) → `App\Services\MineRegistry`,
  seul écrivain : autorisation `canManageMines()`, province du **poste**, date du jour **à Paris côté
  serveur**, poste estampillé, payload chiffré (texte brut, relevé analysé, prix, taux).
- **Ajout seul** : même province et même jour → 409 avec l'auteur à remplacer, puis remplacement sur
  `confirm_replace` (l'ancien reste, `active` NULL, `replaced_by_id`).
- **R3 bis** : refus si le relevé **analysé** est identique ou en dit strictement moins
  (`MineRegistry::facts()`, clavetage sur le nœud, sans libellé de langue). Contrôles positifs faits.
- Messages FR/EN `lang/{fr,en}/mines.php`, au contrat de `MandateApiErrors` (codes `mine_*`).

## [2026-10-08] — PR #56 (journal de la politique : le Registre des mines)

### Added
- `resources/policy/changelog.php` : entrée **substantielle** `2026-10-08-registre-des-mines` (texte en
  ligne par front #84 et #85), résumé FR/EN de l'email. Une entrée propre à cette modification
  (Greg, 07/10). Envoi : `php artisan policy:notify 2026-10-08-registre-des-mines` en SSH, à la main.

## [2026-10-08] — PR #55 (Registre des mines, PR 1a : schéma)

Brief `admin/content/brief-registre-mines.md` §2, §3, §5 ; fil `admin/echanges/registre-mines` (07 :
feu vert de Greg pour la PR 1a seule). **Ne stocke rien** : l'API d'écriture (PR 1b) attend le texte
de la politique en ligne et `policy:notify`.

### Added
- Table `mine_reports` + modèle `MineReport` : axes en clair, `payload` chiffré par `MODULE_DATA_KEY`.
- **Ajout seul, tenu par la base** : `active` = 1 / NULL et index unique `(province_id, reported_at,
  active)` — un seul relevé actif par province et par date, les remplacés s'accumulent (vérifié sur
  MariaDB et SQLite ; contrôle positif : sans l'index, le test échoue).
- `character_id` en `nullOnDelete`, **catégorie C** (`UserDataLifecycleTest`) : le relevé reste, le lien
  vers l'auteur est coupé.
- `MandateAuthority::canReadMines()` : le dirigeant de la province (comte, duc…) **consulte** le registre (Greg,
  08/10/2026 : « c'est le chef de la province ») ; l'écriture reste à `canManageMines()`. Remplace le
  « jamais le comte » du brief §4. Contrôle positif fait.
- `MandateAuthority::canManageMines()` : province du **poste** (commissaire aux mines ou bailli), jamais
  la résidence ; vérifie le titre.

⚠️ **Prod** : migration à lancer en SSH (`php artisan migrate`), le déploiement ne le fait pas.

## [2026-10-07] — PR #54 (les tests n'écrivent plus dans les journaux)

### Changed
- `phpunit.xml` : `LOG_CHANNEL=null` (`force="true"`, sinon le `LOG_CHANNEL` du conteneur
  l'emporte). Les erreurs **simulées** par les tests — « SMTP refusé » de `PolicyNotifyTest` —
  finissaient dans `storage/logs` du poste de dev, indiscernables d'une vraie : elles ont été prises
  le 07/10 pour un échec de `policy:notify` en prod (fil `bilan-mines`, 09 et 10). Contrôle positif :
  avec l'ancienne configuration, la ligne réapparaît.

## [2026-10-05] — PR #53 (plus aucun lien vers le domaine admin dans les emails)

Suite d'un email de test classé en spam (SPF, DKIM et DMARC pourtant PASS). Décision de Greg :
aucun domaine `odc-admin` dans un email de joueur — il ressemble à de l'hameçonnage.

### Fixed
- Logo servi par le site des joueurs (front #80) ; version texte des emails alignée sur le HTML
  (elle affichait le panneau admin en tête, « Tous droits réservés » et un lien en Markdown brut).
- Liens de confirmation d'email vers `/verify-email` du site (`EmailVerificationLink`, front #81),
  qui rappelle l'API en JSON ; la redirection reste pour les liens envoyés avant.

## [2026-10-04] — PR #52 (gabarit des emails, tous bilingues)

### Changed
- **Gabarit des emails** aux couleurs de la charte (`resources/views/vendor/{mail,notifications}`) :
  logo PNG, carte claire à liseré orange, bouton orange, pied « outil non officiel » bilingue.
  Plus de « Bonjour ! » ajouté par Laravel avant notre salutation, signature sur plusieurs lignes,
  ligne d'aide bilingue, filet entre français et anglais.
- Signature « Ludiquement, » / « Playfully, » ; « English version below. » retiré ; email
  d'inscription bilingue. `EmailLayoutTest` (10 tests).

## [2026-10-04] — PR #51 (première entrée du journal de la politique, purge activée)

Étape 5 du brief `admin/content/brief-politique-promesses.md`, après la mise en ligne du texte
(front #79).

### Changed
- Journal `resources/policy/changelog.php` : entrée `2026-10-04-comptes-inactifs` (substantielle,
  Greg), premier envoi réel de `policy:notify`.
- `accounts.enforce` → `true` : `accounts:purge` cesse d'être une simulation. Lié par test à
  l'entrée substantielle du journal.

## [2026-10-04] — PR #50 (purge : refus de tourner sans configuration valide)

Correctif de la PR #49, après un incident en prod le 04/10/2026 (rien envoyé, rien supprimé).

### Fixed
- Config en cache d'avant `config/accounts.php` : durées lues à 0 jour, la simulation listait le
  compte de Greg pour un préavis « suppression le jour même ». `accounts:purge` refuse désormais de
  tourner si une durée manque, vaut 0 ou est incohérente (rien fait, pas de heartbeat) ; même garde
  sur `logs:prune`, qui aurait effacé tous les journaux sauf celui du jour.

## [2026-10-04] — PR #49 (purge des comptes inactifs et non confirmés, en simulation)

Étape 4 du brief `admin/content/brief-politique-promesses.md` (§2).

### Added
- **`accounts:purge`** (09:00 Paris, heartbeat `accounts`, `--dry-run`) : préavis à 11 mois sans
  passage puis suppression à 12 mois (≥ 30 j après le préavis) ; rappel aux comptes non confirmés à
  J+23, avec un lien valable jusqu'à la suppression, puis suppression à J+30 (≥ 7 j après le rappel).
  Aucune suppression sans avis enregistré ; `last_seen_at` nul jamais prévenu ni supprimé ; mandat
  en cours → signalé sur `/dashboard`, pas supprimé. **Livrée en simulation** (`accounts.enforce` =
  `false`) tant que la politique en ligne annonce 2 ans.

### Fixed
- `AccountDeletion` efface les jetons OAuth et `password_reset_tokens` pour **tous** les chemins de
  suppression : par l'admin ou le profil Breeze, l'adresse email survivait dans `password_reset_tokens`.

## [2026-10-04] — PR #48 (purge des journaux par date)

Correctif de la PR #47.

### Fixed
- La rotation de Monolog garde les 180 derniers **fichiers**, pas jours, et ne purge qu'à la
  naissance d'un fichier : en `LOG_LEVEL=error`, des journaux de plus d'un an survivaient.
  **`logs:prune`** efface d'après la date du nom de fichier, chaque jour à 09:00 (Paris), avec
  heartbeat `logs` (alerte `/dashboard`, `mandates:status`).

## [2026-10-04] — PR #47 (rotation des journaux, 180 jours)

Étape 3 du brief `admin/content/brief-politique-promesses.md` (§3).

### Changed
- Journaux applicatifs : canal `stack` → `daily`, **180 jours** écrits en dur (le défaut était 14),
  pour tenir la promesse de `/legal/privacy` §5 (12 mois maximum). `LoggingRetentionTest` déplie la
  pile et échoue sur tout canal sans rotation. ⚠️ Prod : `config:cache` en SSH, et l'ancien
  `laravel.log` à supprimer à la main.

## [2026-10-04] — PR #46 (notification des modifications de la politique, §10)

Étape 2 du brief `admin/content/brief-politique-promesses.md` (fil `admin/echanges/politique-promesses`).

### Added
- **`php artisan policy:notify <id>`** : lancée à la main, jamais planifiée ; refuse une entrée
  inconnue, mal formée, non substantielle ou déjà envoyée ; comptes vérifiés seulement ; nombre de
  destinataires et confirmation avant l'envoi.
- Journal `resources/policy/changelog.php` (source unique, vide à la livraison), email
  `PolicyUpdated` bilingue FR puis EN sans lien de désinscription, table `policy_notifications`
  (anti double envoi, hors taxinomie des données utilisateur).

## [2026-10-04] — PR #45 (dernier passage des comptes, `last_seen_at`)

Étape 1 du brief `admin/content/brief-politique-promesses.md` (fil `admin/echanges/politique-promesses`).

### Added
- **`users.last_seen_at`** (indexée) et **`deletion_notice_sent_at`** : mesure de l'inactivité promise
  par `/legal/privacy` §5. Comptes existants remplis à la date de la migration, jamais à `created_at`.
- `App\Support\LastSeen` (seul écrivain, une écriture par jour au plus, `updated_at` intact) et le
  middleware `RecordLastSeen` (groupes `api` et `web`, écriture après la réponse). « Connexion » =
  toute requête authentifiée ; `register`, `verifyEmail`, `login` et `refresh`, publiques, désignent
  le compte elles-mêmes.

## [2026-10-04] — PR #43 (MariaDB en dev et en CI)

### Added
- **Job CI `migrations`** (`tests.yml`) : `php artisan migrate` sur **MariaDB 11.4.13**, le moteur de
  la prod (pilote `mysql`, comme la prod). La suite Pest reste sur SQLite ; `deploy.yml` appelle ce
  workflow, donc une migration refusée par le moteur de prod bloque le déploiement. **Vu échouer**
  le 04/10/2026 (back #42, branche jetable, jamais mergée) : `1074 Column length too big`.

### Changed
- Le dev passe de MySQL 5.7 à **MariaDB 11.4.13** (conteneur `odc-db`) ; README à jour, version de
  prod écrite comme fait daté. Répétition du déploiement sur la structure de prod : les six
  migrations `2026_10_03_*` s'appliquent, 23 tables (brief `admin/content/brief-mariadb-dev.md`).

## [2026-10-04] — PR #44 (mandats de maire et de conseiller comtal, historique des postes)

Lots 1 à 3 du brief `admin/content/brief-mandats.md` et historique des postes, livrés ensemble.

#### Historique des postes

### Added
- **Historique des postes par province** (fil `admin/echanges/mandats-historique`) : onglet admin
  « Historique » (conseil par titre, maires par ville, réattributions en évidence, villes sans
  mandat comptées) et API `characters/{id}/province` pour la page joueur « Ma province ». Une seule
  fonction de fusion (`OfficeHistory`).
- **L'historique survit à la suppression d'un compte** : `office_history_archive` (catégorie D),
  écrite par la **porte unique** `AccountDeletion`, par laquelle passent désormais les trois
  suppressions de compte (API, admin, profil Breeze). Test structurel contre toute suppression
  hors de la porte.
- `GameCalendar` (année du jeu côté serveur), jumeau de `gameCalendar.js`, figé par un test jumeau.

### Changed
- **Emails des mandats** : adressés au joueur, personnage nommé (objets compris), dates
  « réelle (jeu) » ; `revoked` devient « L'Office a révoqué le mandat de votre personnage… Effet
  au… ». Anglais aligné.

#### Lot 2 — API joueur

### Changed
- **Réponses d'erreur de l'API des mandats complètes et bilingues** (`App\Support\MandateApiErrors`,
  `App\Exceptions\MandateRefusal`) : `codes` et `messages` {fr, en} pour chaque champ, refus
  métier comme validation de forme ; 404 et 429 avec `code` et `messages`. Aucun code `admin.` ne
  sort par l'API. `lang/en/mandates.php` gagne la section `api` ; `lang/{fr,en}/validation.php` les
  noms de champs des mandats.
- `GET /mandates` expose `requestable` par personnage et par niveau, et les libellés FR/EN des
  titres, motifs et causes de fin ; `GET /council-offices` les libellés des titres.
- Le code de révocation `deces` devient **`retranchement`** (terme du jeu, demande de Greg), en anglais
  **« Retrenchment »**, relevé en jeu par Greg.
- 10 tests (`Feature/Mandates/MandateApiErrorsTest`), contrôles positifs compris.

#### Lot 3 — tâches planifiées

### Added
- **Tâches planifiées des mandats** (`routes/console.php`, chaque jour à 09:00 Paris ; fil
  `admin/echanges/mandats-lot3/`) : rappel bilingue au joueur 2 jours après la fin effective de son
  mandat, tant qu'il est renouvelable, et jamais à un maire dont la mairie a un successeur validé
  (`mandates:send-reminders`), récapitulatif quotidien de la file de vérification aux comptes
  `admin` tant qu'il reste des lignes en retard (`mandates:verification-digest`), purge des refus
  de plus de 3 mois sans jamais toucher une demande en attente (`mandates:purge-rejected`).
- **Battement du planificateur** (`App\Support\MandateHeartbeat`) : chaque tâche écrit son
  dernier passage réussi ; au-delà de 36 h, `/dashboard` affiche une alerte qui nomme la tâche, et
  `mandates:status` échoue. ⚠️ Prérequis en prod : la tâche cron `schedule:run` dans cPanel.
- 18 tests, contrôles positifs compris (horodatage écrit avant les envois, renouvellement validé
  non exclu, purge sur l'âge de la ligne, alerte retirée du tableau de bord).

#### Lot 1 — modèle, gestes et administration

### Added
- **Mandats de maire et de conseiller comtal, lot 1** (`admin/content/brief-mandats.md`, arbitrages
  `admin/echanges/mandats-lot1/`). Un joueur demande la validation de son poste, l'administrateur
  la vérifie, et le mandat devient une autorité datée que les modules liront par
  `App\Services\MandateAuthority`.
  - Schéma : `council_offices` (10 postes, semés par une migration qui appelle le seeder
    idempotent), `mayor_mandates`, `council_mandates`, `council_office_periods` (historique des
    postes). Catégorie A, `character_id` ajouté aux colonnes surveillées par le garde-fou du cycle
    de vie.
  - API joueur : demande, renouvellement (sans poste), annulation, « Déclarer mon poste »,
    référentiel public des postes.
  - Administration : file des demandes (validation groupée réservée aux renouvellements),
    mandats en cours avec prolongation, passation et vidage des postes par province, correction
    directe d'un poste, révocation avec ou sans successeur et son annulation, corrections de dates
    et de motif, file de vérification des mandats terminés.
  - Emails de décision bilingues, français puis anglais (`lang/en/mandates.php`, limité à l'email).
  - 101 tests, dont des contrôles positifs consignés dans la PR.

## [2026-10-02] — PR #41

### Performance
- **Cache de `/api/v1/map` porté d'1 h à 7 jours** (`App\Support\MapTree`). À 1 h, tout
  visiteur arrivé après une heure de calme — le cas lent qui a ouvert l'enquête — trouvait le
  cache expiré : le gain de back #39 ne valait que pour des appels rapprochés. La semaine ne sert
  plus qu'à réparer une écriture hors Eloquent. Le cache survit au déploiement : sa clé porte
  une **empreinte** de `MapTree.php` et des trois modèles, qui change seule quand l'un d'eux
  change. Un test de forme garde ce que l'empreinte ne voit pas (colonne ajoutée par migration).
  Mesure du 02/10 à 21:47 (heure belge), TTL d'1 h : `map` à 0,56 s d'attente serveur, cache
  expiré, contre 0,21 à 0,25 s cache chaud. Gardé par `Feature/Api/MapTest` : deux tests sur
  la durée (échouent avec 1 h), la forme du tableau, la liste des fichiers empreintés et le
  changement d'empreinte — chacun vu en échec.

## [2026-10-02] — PR #40

### Fixed
- **Plus de polices Font Awesome en 404 sur le panneau admin** (`resources/sass/app.scss`). Les
  feuilles SCSS de Font Awesome déclaraient des `@font-face` vers `build/webfonts/`, que Vite ne
  copie pas : deux 404 par page en prod depuis l'installation de Breeze. Les icônes s'affichaient
  malgré tout, en SVG, par le JS de Font Awesome importé dans `app.js` : le SCSS faisait doublon.
  Il est retiré (CSS : 534 → 448 Ko), et le rendu a été vérifié en navigateur headless : 6 icônes
  en SVG, aucune requête de police. Gardé par `Unit/Enforcement/AdminIconsTest`.

## [2026-10-02] — PR #39

### Performance
- **L'arbre de `/api/v1/map` est mis en cache** (`App\Support\MapTree`, TTL 1 h). La route
  renvoyait 69 938 octets identiques à chaque appel, pour une attente serveur de 0,43 à 8,83 s
  en prod. Le cache porte les données, jamais la réponse HTTP. Il est vidé à toute écriture
  Eloquent sur un royaume, une province ou une ville (trait `FlushesMapTree`), le TTL couvrant
  les écritures hors Eloquent. JSON identique au bit près avant et après. Gardé par
  `Feature/Api/MapTest`, qui compte les requêtes SQL (0 au second appel, contrôle positif au
  premier) ; chaque test échoue quand on retire le cache, l'invalidation ou le TTL.

## [2026-10-02] — PR #38

### Changed
- **Le jeton d'accès vit 1 h au lieu de 15 min** (`AppServiceProvider`). Chaque expiration faisait
  passer le joueur par le chemin le plus lent de l'API (préflight, `me` refusé, `refresh`, `me`),
  vu à ~30 s en prod le 01/10. La révocation reste vérifiée en base à chaque requête : la
  déconnexion et la suppression de compte coupent toujours l'accès aussitôt. Amendement de l'ADR
  Passport dans `docs/DECISIONS.md`. Gardé par un test dans `Feature/Api/AuthTest`, qui échoue
  à 15 min.
## [2026-10-02] — PR #37

### Fixed
- **Un jeton Bearer refusé n'écrit plus d'erreur dans `laravel.log`** (`bootstrap/app.php`). Le
  `TokenGuard` de Passport signale tout jeton refusé, qu'il soit expiré, révoqué ou illisible.
  Chaque expiration d'un jeton de joueur laissait donc une entrée
  ERROR d'environ 90 lignes pour un 401 ordinaire. La sonde de latence en ajoutait 15 par jour.
  Seul le refus `access_denied` est tu, les autres erreurs OAuth restent signalées. Gardé par
  `Feature/Api/RejectedTokenReportingTest`, qui échoue sans le correctif.

## [2026-10-01] — PR #36

### Security
- **Redirection vers l'origine canonique de l'admin passée en 301** (`public/.htaccess`). Elle
  était en 302 depuis back #31, le temps de vérifier la règle en prod. Elle a tenu du 29/09 au
  01/10 et l'étape « origine canonique » de `deploy.yml` la contrôle à chaque déploiement.

## [2026-09-30] — PR #34

### Fixed
- **L'admin et l'API ne s'indexent plus** : `public/robots.txt` était celui de Laravel
  (`Disallow:` vide, qui autorise tout), il porte désormais `Disallow: /`.
- **`public/favicon.ico` n'est plus vide** (0 octet servi en 200) : l'écu du site, repris du
  frontend. Gardés par `tests/Unit/Enforcement/AdminRobotsTest.php`.
## [2026-09-29] — PR #35

### Fixed
- **`public/vendor/` est de nouveau déployé** (`deploy.yml`). Le motif `**/vendor/**` excluait aussi
  les ressources publiées des paquets (`public/vendor/sweetalert/`), présentes en prod par un
  dépôt manuel ancien : un serveur reconstruit aurait perdu SweetAlert sans alerte. Le motif est
  resserré sur le `vendor/` Composer à la racine ; `DeployExcludeTest` couvre les deux cas.

## [2026-09-29] — PR #33

### Changed
- **Le déploiement n'envoie plus l'outillage ni la documentation** (`deploy.yml`) : `docs/`,
  `scripts/`, sources `resources/js|sass`, `README.md`, `CHANGELOG.md`, `.env.example`,
  `.env.testing`, `phpunit.xml`, `phpstan.neon`, `package*.json`, configs Vite/Tailwind/PostCSS,
  `.editorconfig`. Hors racine web, donc de l'encombrement plutôt qu'une divulgation. Ce qui est
  déjà sur le serveur se retire à la main (le déploiement ne supprime rien).
  `tests/Unit/Enforcement/DeployExcludeTest.php` garde le sens dangereux : n'exclure jamais
  `composer.json`/`composer.lock` (vendor/ est construit sur le serveur), `artisan`, `public/`,
  les vues, la config, les routes, les traductions, les migrations.

## [2026-09-29] — PR #32

### Sécurité
- **`SESSION_SECURE_COOKIE=true` documentée dans `.env.example`.** Posée à la main sur le serveur
  le 29/09/2026 (cookie de session admin et `XSRF-TOKEN` marqués `secure`, constaté au `curl`),
  elle manquait au dépôt : une réinstallation depuis l'exemple l'aurait perdue sans alerte. À
  mettre à `false` en local sur `http://localhost`.

## [2026-09-29] — PR #31

### Sécurité
- **Une seule adresse pour l'admin et l'API** (`public/.htaccess`). Les quatre variantes
  (`http`/`https`, avec ou sans `www`) servaient toutes le panneau admin et l'API, dont la page
  de connexion admin **en clair**. Elles redirigent désormais vers `https://odc-admin.creacube.be`,
  chemin et paramètres conservés, en **302** le temps de vérifier la règle en prod (301 ensuite).
  Un `POST` en `http://` échoue volontairement plutôt que d'être rejoué en https. Vérifié à chaque
  déploiement (`deploy.yml`) et par `tests/Unit/Enforcement/HtaccessCanonicalOriginTest.php`.
  Pas de HSTS : décision séparée.

## [2026-09-29] — PR #30

### Sécurité
- **La restriction CORS est vérifiée à chaque déploiement** (`deploy.yml`). Elle dépend de
  `CORS_ALLOWED_ORIGINS` dans le `.env` du serveur, que le déploiement ne touche jamais : la prod
  a répondu `Access-Control-Allow-Origin: *` des semaines sans que rien ne le signale. La
  variable est posée depuis le 29/09/2026 (après la redirection de `www`/`http` vers l'origine
  canonique, front #73). L'étape exige l'en-tête exact pour l'origine de l'Office et refuse `*`
  comme l'écho d'une origine tierce. Contrôle positif fait contre une API ouverte.

## [2026-09-29] — PR #29

### Sécurité
- **Limitation de débit enfin appliquée.** Le limiteur `api` était déclaré dans
  `AppServiceProvider` sans jamais être branché : 65 essais de mot de passe sur `login` depuis une
  IP donnaient 65 × 401 et aucun 429 (constat du 28/09/2026). Désormais : `login` à deux couches
  (5/min par email+IP, 30/min par IP, l'email compté qu'il existe ou non, sans verrouillage de
  compte), `register` 5/min/IP, `refresh` 20/min/IP, et un plancher de 60/min sur tout `/api/*`
  (`throttleApi()`). 429 en JSON français, distinct de celui d'O2Switch. ⚠️ En prod, la couche
  O2Switch retient ce 429 : la requête reste sans réponse, et c'est le délai d'attente de 20 s du
  frontend (front #72) qui la borne. Compteurs par visiteur vérifiés en prod le 29/09 (téléphone
  servi pendant que le PC était bloqué) : pas de `trustProxies` nécessaire.
- **`login` ne trahit plus l'existence d'un compte par son délai** : un email inconnu paie
  désormais le même `Hash::check` qu'un email connu (hash factice mis en cache).
- `tests/Feature/Api/RateLimitTest.php` (11 tests, dont 10 échouent sans le correctif ; un `X-Forwarded-For` forgé ne donne pas de compteur neuf).

## [2026-09-28] — PR #28

### Modifié
- **Préflights CORS mis en cache** : `config/cors.php` passe `max_age` de `0` à `86400`. À 0,
  chaque appel d'API du front payait un OPTIONS de plus, ~400 ms par aller-retour sur le
  mutualisé (mesure prod du 28/09/2026). Chromium/Edge plafonnent à 2 h, Firefox honore 24 h.
  Garde-fou `tests/Feature/Api/CorsTest.php`.

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

## [2026-09-20] — PR #22

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
