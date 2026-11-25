# Architecture technique — Backend (Laravel 13)

> Référence structurelle chargée automatiquement (voir `CLAUDE.md` racine). Mise à jour :
> 23/11/2026. Vérifier le code avant de citer un détail précis si ce fichier date de plus de
> quelques semaines.

Laravel 13 (PHP 8.4) + Passport (OAuth2) + Spatie Permission + MariaDB 11.4.13. Hébergement
mutualisé O2Switch (cPanel, déploiement FTP via GitHub Actions).

## Modèles & relations

`User` → hasMany `Character` (cascade SQL) → hasMany `CouncilMandate` (cascade). `Kingdom` →
hasMany `Province` → hasMany `City`. `CouncilMandate` → hasMany `CouncilOfficePeriod` (cascade).
`AnimDecodeActivity` → hasMany `AnimDecodeSecret` → hasMany `AnimDecodeProposal` (cascades).
`AnimRewardsGrid` → hasMany `AnimRewardsList` (cascade). `MineReport` → belongsTo `Province`,
`Character` (nullable). `ProvinceMinePrice` → belongsTo `Province`, `Character` (nullable).
`SiteAnnouncement` — aucune FK vers un compte.

## Schéma de la base

| Table | Rôle | Catégorie |
|---|---|---|
| `users` | email, password, `pending_email`, `last_seen_at`, `deletion_notice_sent_at` | A |
| `characters` | pseudo, `city_id`, `is_validated`, `pending_residence_change` | A |
| `council_mandates` | mandat mayoral ou de conseiller | A |
| `council_office_periods` | titre de poste, période | A |
| `office_history_archive` | survit à la suppression (pseudo texte, lieu texte+id, titre clé) | D |
| `mine_reports` | relevé des mines (`active` 1/NULL), payload chiffré, `character_id` nullable | C |
| `province_mine_prices` | prix par province et date d'effet, `character_id` nullable | C |
| `character_inventory_settings` | réglages + dernier inventaire collé, chiffré | A |
| `city_inventory_settings` | idem ville, `updated_by_character_id` nullable | C |
| `province_inventory_settings` | réglages province, `updated_by_character_id` nullable | C |
| `province_inventory_snapshots` | historique collages commissaire commerce, chiffré, `active` 1/NULL | C |
| `city_inventory_snapshots` | historique collages du maire (registre des stocks, 20/11), jumeau de la province, `office_key` = `mayor` | C |
| `customs_officers` | douaniers (module Sécurité) : demande opt-in puis accès, `active` 1/NULL, décideur et niveau tracés | A |
| `province_security_settings` | réglages de sécurité de la province (durée d'inactivité du douanier), `updated_by_character_id` nullable | C |
| `customs_reports` | bilans de défense (Registre de douane) : un par ville et par jour du jeu, `active` 1/NULL, payload chiffré (brut + analyse par section), `character_id` nullable | C |
| `customs_watchlist_entries` | listes rouge/verte d'une province, pseudo et raison chiffrés, `added_on`, `added_by_character_id` nullable ; un retrait supprime la ligne | C |
| `anim_activity_types` | référentiel (`key`, `is_active`) | — |
| `anim_decode_activities` | activité Décode (privée ou sans compte), slug unique, lieu obligatoire | A (privé) / hors taxinomie (sans compte) |
| `anim_decode_secrets` | coffre, `code` chiffré | suit l'activité |
| `anim_decode_proposals` | proposition, `character_name` texte libre | suit l'activité |
| `anim_rewards_grids` | grille des lots, slug unique, lieu obligatoire | A (privé) / hors taxinomie (sans compte) |
| `anim_rewards_lists` | lots de la grille | suit la grille |
| `item_icons` | nom → `game_item_id` Celsius Online | hors taxinomie |
| `policy_notifications` | anti double envoi politique | hors taxinomie |
| `site_announcements` | message FR/EN + échéance + visibilité | hors taxinomie |

**Catégories RGPD** : A = portante (cascade), B = pas utilisée, C = communautaire (nullOnDelete), D = archive pseudonymisée.

## Origine canonique

API : `CANONICAL_ORIGIN` (`.env`, une seule valeur, le domaine `https://` sans slash final).
`VerifyCanonicalOrigin` (groupe `api`) : vérifie `Origin` **ou** `Referer` contre la valeur
configurée ; 403 si aucun ne correspond. ⚠️ Le middleware **lit le header brut**, pas la config
CORS — CORS autorise le préflight, l'origine canonique autorise la requête réelle. Sonde prod
dans `deploy.yml`.

## Routes

### API (`/api/v1`)

| Verbe | Chemin | Contrôleur | Auth | Notes |
|---|---|---|---|---|
| POST | `auth/register` | AuthController@register | — | email+password, envoie vérification |
| GET | `auth/verify-email/{id}/{hash}` | AuthController@verifyEmail | signé | marque vérifié, token, redirige FRONTEND_URL |
| POST | `auth/resend-verification` | AuthController@resendVerification | — | |
| POST | `auth/forgot-password` | PasswordResetController@forgot | — | réponse **toujours identique** (pas d'oracle) ; `throttle:password-reset` (3/min par adresse, 10/h par IP) ; voir « Mot de passe oublié » |
| POST | `auth/reset-password` | PasswordResetController@reset | — | jeton+email+nouveau mot de passe ; **une seule** réponse 422 `invalid_token` ; `throttle:password-reset-confirm` (10/min par IP) |
| POST | `auth/login` | AuthController@login | — | par **email**, 403 si non vérifié |
| POST | `auth/refresh` | AuthController@refresh | — | renouvelle access+refresh |
| POST | `auth/logout` | AuthController@logout | api | révoque token courant+refresh |
| GET | `auth/me` | AuthController@me | api | renvoie `user.characters` |
| POST | `auth/update-email` | AuthController@updateEmail | api | stocke `pending_email`, envoie vérification |
| GET | `account/email/verify/{id}/{hash}` | AuthController@confirmEmailChange | signé | applique, notifie ancienne, révoque tous tokens |
| PUT | `auth/password` | AuthController@updatePassword | api | vérifie actuel, révoque sauf courant |
| DELETE | `auth/account` | AuthController@destroyAccount | api | art. 17, exige mot de passe |
| GET | `auth/export` | AuthController@exportAccount | api | art. 20 RGPD, JSON |
| GET | `characters` | CharacterController@index | api | personnages du compte |
| POST | `characters` | CharacterController@store | api | pseudo + city_id |
| PUT | `characters/{id}` | CharacterController@update | api | changement de ville → revalidation |
| PUT | `admin/characters/{id}/pseudo` | AdminCharacterController@updatePseudo | api, role:admin | |
| GET | `map` | MapController@index | — | arbre complet, cache `MapTree` 7j |
| GET/POST/PUT/DELETE | `characters/{id}/mandates`, `mandates/*`, `council-offices` | MandateController | api (sauf GET offices) | voir « Mandats » |
| POST | `characters/{id}/mine-reports` | MineReportController@store | api | voir « Registre des mines » |
| GET | `characters/{id}/mine-registry` | MineRegistryController@index | api | lecture registre |
| GET | `characters/{id}/mine-registry/mandates/{mandate}` | MineReportController@mandate | api | bilans de mandat **passés** (ou en cours), lecture seule, relevés fenêtrés ; 403 `mine_not_reader` ; voir « Registre — bilans de mandat passés » |
| GET/POST/DELETE/PUT | `characters/{id}/customs`, `characters/{id}/customs/settings`, `customs-officers/{id}/{approve,reject,revoke}` | CustomsController | api | douaniers, voir « Sécurité — douaniers » |
| GET/POST | `characters/{id}/customs-access`, `characters/{id}/customs-reports` | CustomsReportController | api | Registre de douane : droits, jours, bilan du jour, saisie ; voir « Registre de douane » |
| GET/POST/PUT/DELETE | `characters/{id}/customs-lists`, `characters/{id}/customs-lists/{entry}` | CustomsListController | api | listes rouge/verte |
| POST | `characters/{id}/customs-preview` | CustomsReportController@preview | api | Relevé unique : couleurs des pseudos collés + guet du dernier bilan de la ville, **sans rien enregistrer** ; `throttle:30,1` |
| GET | `characters/{id}/customs-province` | CustomsReportController@province | api | Vue d'ensemble de la province (PR 5) : couverture par ville (avec arrivées et départs en pseudos) + marques, groupes à surveiller et armées fusionnés, **lecture seule**, 403 `province_not_readable` sous deux villes lisibles, `?date=` = situation au jour D ; `throttle:30,1` |
| GET/POST/PUT | `provinces/{id}/mine-prices` | MinePriceController | api | voir « Prix des mines » |
| GET/PUT | `characters/{id}/inventory-settings/*` | InventoryController | api | 3 niveaux, version |
| GET/PUT | `characters/{id}/inventory/*` | InventoryController | api | texte collé |
| GET/POST | `characters/{id}/inventory/province` | InventoryController | api | historique province ; POST : 409 `registry_needs_confirmation` puis `confirm_replace` |
| GET/POST | `characters/{id}/inventory/city/registry` | InventoryController | api | historique ville (maire seul), même contrat |
| GET | `characters/{id}/inventory-scopes` | InventoryController | api | niveaux accessibles |
| POST | `characters/{id}/inventory-settings/personal/copy` | InventoryController | api | copie entre persos même compte |
| GET | `anim/activity-types` | AnimController | — | types actifs |
| GET | `anim/decode-activities/{slug}` | DecodeController | — | page publique |
| GET | `anim/decode-slug` | DecodeController | — | aperçu et dispo |
| POST | `anim/decode-manage` | DecodeController | — | création sans compte |
| POST | `anim/decode-manage/{slug}/*` | DecodeController | — | gestion sans compte (mot de passe dans le corps) |
| GET/POST/DELETE | `characters/{id}/decode-activities`, `decode-activities/{id}/*` | DecodeController | api | mode privé |
| — | `anim/rewards-*`, `characters/{id}/rewards-grids`, `rewards-grids/{id}/*` | RewardsGridController | mixte | même schéma que Décode |
| GET | `item-icons` | ItemIconController | — | `?names[]=…` (200 max), cache 24h |
| GET/POST/PUT/DELETE | `admin/item-icons` | ItemIconController | api, role:admin | |
| GET | `announcements/current` | AnnouncementController | — | annonce en cours, cache 60s |

### Web (panneau d'administration Blade, `role:admin`)

`/dashboard` (personnages, toggleValidation), `/users` (liste, recherche, suppression), mandats (`/mandates/*`), `/anim` (surveillance, recours sans compte), `/item-icons`, `/announcements`, `/customs` (douaniers, niveau 3 ; **retrait d'un pseudo** : formulaire en deux temps, des NOMBRES seulement).

## Contrôleurs

- `Api\AuthController` — `register`, `verifyEmail` (token + redirige `FRONTEND_URL`), `login` (par **email**, 403 si non vérifié, couple access+refresh via grant `password`), `refresh`, `logout`, `me` (renvoie `user.characters`), `destroyAccount`, `exportAccount` (art. 20), `updateEmail`/`confirmEmailChange` (back #75, verify-before-apply), `updatePassword` (révoque sauf courant).
- `Api\CharacterController` — `store`, `index`, `update` (changement de ville → `is_validated = false`).
- `Api\MapController` — arbre complet, **cache `MapTree`** (store par défaut, TTL 7j, clé empreintée). ⚠️ Survit au déploiement : `cache:clear` si migration. Vidé par `FlushesMapTree` sur Kingdom/Province/City.
- `Api\MandateController` / `Web\MandateAdminController` — voir « Mandats ».
- `Web\DashboardController` — personnages paginés, `toggleValidation`, `users` (recherche email OU pseudo), `destroyUser`.

## Middleware & rôles

- `RecordLastSeen` (groupes `api` et `web`). Un seul middleware maison.
- **Un seul rôle Spatie : `admin`**, seedé via `UserSeeder`. Aucune permission Spatie.

## Dernier passage (`last_seen_at`)

`App\Support\LastSeen` : au plus une écriture/jour, hors Eloquent. Remet `deletion_notice_sent_at` à null. Écriture dans `terminate()`. ⚠️ `register`, `verifyEmail`, `login`, `refresh` hors `auth:api` : ces routes appellent `LastSeen::markForRequest()`. Une nouvelle route publique qui authentifie doit faire de même. Lignes existantes remplies à la date de migration, jamais à `created_at`.

## Notification politique (§10)

`resources/policy/changelog.php` (source unique) → `php artisan policy:notify` (lancée **à la main**). Emails bilingues, **aucun lien de désinscription**. `policy_notifications` anti double envoi. 🔴 **Un seul email par jour** (Greg) : sans argument, regroupe toutes les entrées non envoyées du jour de Paris. ⚠️ N'ajouter une entrée substantielle qu'une fois le texte **en ligne**.

## Purge des comptes (`accounts:purge`)

Préavis puis suppression (`/legal/privacy` §5). Planifiée 09:00 Paris, heartbeat `accounts`.
- Vérifié inactif : préavis à 335j, suppression à 365j **et** ≥ 30j après préavis.
- Non confirmé : rappel à J+23 (nouveau lien signé), suppression à J+30 **et** ≥ 7j après rappel.
- 🔴 **Aucune suppression sans avis enregistré** (`AccountPurge::delete()` → `LogicException`).
- ⚠️ `last_seen_at` **nul** → jamais prévenable ni supprimable. Ne jamais `COALESCE`.
- Mandat en cours → non supprimé (signalé sur `/dashboard`).
- 🔴 **Config absente → la purge ne fait rien** (`assertConfigured()`). `accounts.enforce` = `false` impose simulation.
- Suppression via `AccountDeletion::deleteUser()` (efface traces sans FK : OAuth, `password_reset_tokens`).

## Gabarit des emails

Fond `slate-800`, logo PNG du **site des joueurs**, carte claire liseré orange, pied bilingue. 🔴 **Aucun lien vers le domaine d'administration** (hameçonnage). Liens de confirmation via `App\Support\EmailVerificationLink` → `/verify-email` du site. ⚠️ Les tâches planifiées n'ont pas de requête pour déduire `APP_URL`. Version texte : `vendor/mail/text/message.blade.php`. Tests : `EmailLayoutTest`.

## Notifications d'administration (17/11/2026)

`App\Services\AdminNotifier` : un email aux `admin` quand un joueur confirme son compte, crée un personnage, ou demande un poste/renouvellement. 🔴 **Garde `web` en clair** (`role('admin', 'web')`, sinon `RoleDoesNotExist` en silence). 🔴 **Jamais l'email d'un joueur dans le corps**. 🔴 **L'envoi ne bloque jamais le geste du joueur** (exception attrapée, journalisée sans message). Tests : `AdminNotificationsTest`.

## Gestion des utilisateurs (admin)

`DashboardController` + `users.blade.php`. Liste paginée (14), recherche email OU pseudo. Hard delete (pas de soft delete). Pas d'édition, pas de reset password admin, pas de gestion de rôles. Tests : `DashboardTest`.

## Mot de passe oublié (24/11/2026)

Fil `admin/echanges/mot-de-passe-oublie/` (état des lieux 01, questions 02, **réponses de Greg 03 : toutes les recommandations**) ; texte légal `Legal.Privacy` §4 (ligne du jeton) et §5 (`PasswordReset`), **non substantiel**, en ligne avant le code. `PasswordResetController` : `forgot` répond **toujours pareil** (Q7), l'envoi qui échoue est journalisé par sa classe, jamais l'adresse ; `reset` (broker de Laravel, jeton **haché** en base `password_reset_tokens`, **60 min**, à usage unique) : mot de passe changé, **email confirmé** si ce n'était pas fait (Q4), **tous les jetons Passport révoqués** (Q6), email d'avis `PasswordChanged` (Q5, aussi depuis le profil), jeton faux / adresse inconnue / expiré = **la même réponse**. `User::sendPasswordResetNotification` envoie `PasswordResetLink` (gabarit de l'Office, FR puis EN) avec `App\Support\PasswordResetLink` : lien vers le **site des joueurs** (`/reset-password?token&email`), **jamais** le domaine d'administration — y compris pour le formulaire Blade de l'administration. 🔴 **`auth:clear-resets` est planifié chaque jour** (promesse « nettoyage quotidien » du §5). ⚠️ **Prod** : `route:cache` (2 routes) ; aucune migration, `config/*.php`, `.env` ni paquet. Tests : `Api/PasswordResetTest`.

## Inscription / vérification d'email

1. `POST register` → crée User, envoie `VerifyApiEmail` (FR, lien signé). Pas de token.
2. Clic lien → vérifie hash, `markEmailAsVerified()`, token Passport (personal_access, sans refresh), **redirige 302** vers `FRONTEND_URL/verify-email?token=…`. Connexion auto (décision Greg).
3. Joueur crée personnage(s) via `POST characters`.

`login()` bloque sur email non vérifié (403), puis émet access+refresh (grant `password`). Validation **par personnage** indépendante (ne bloque pas la connexion).

## Chiffrement des données de module

Mécanisme en place et utilisé par les tables de module (relevés des mines, inventaires, activités d'Animation…). `MODULE_DATA_KEY` ≠ `APP_KEY` (rotation de `APP_KEY` = reconnexion, rotation sur données = illisibles). `EncryptedModuleData` cast (`:string` ou `:array`). `php artisan module-data:check` : lecture seule, vérifie chaque donnée chiffrée. ⚠️ **Chiffrer le contenu, jamais les axes** (indexation impossible avec IV aléatoire).

## Mandats (lot 1)

Spéc. `admin/content/brief-mandats.md` ; **arbitrages** : `admin/echanges/mandats-lot1/` (Q1-Q29).

| Pièce | Rôle |
|---|---|
| `MandateAuthority` | **seul point d'entrée des modules** : `activeMayorMandate`, `activeCouncilMandate`, `holdsCouncilOffice`, `canManageMines` / `canReadMines` (province du **poste**), `canManagePrices` / `canReadPrices`, `inventoryCity` / `INVENTORY_PROVINCE_OFFICES` |
| `MandateWorkflow` | tous les gestes, seul écrivain de `holds_until` |
| `MandateCalendar` | **jour du jeu : 04:00 Paris** (constante `GAME_DAY_STARTS_AT_HOUR`, jamais en config), `addDays` jamais `addMonth`, stockés UTC ; `today()` = jour du jeu en cours ; suivi aussi par les prix, `MineRegistry` et `ProvinceInventoryRegistry` |
| `MandateLabels` | libellés FR/EN ; motif sans libellé → **code**, jamais clé brute |
| `MandateDecision` | email bilingue, synchrone, jamais la note |

Règles clés :
- **Une seule fin effective** : `holds_until`, écrite par `setHoldsUntil()` (avec `holds_until_set_by` + fin de période en un seul appel).
- **Poste = période en vigueur** de `council_office_periods`. Période naît **fermée**. **Exclusivité** gardée par test `officeOverlaps()`.
- **Début de période** (Greg, 16/10) : titre inscrit AVEC mandat en fonction → tenu depuis **entrée en fonction du mandat** (`OfficeStart`), sauf si un autre mandat l'a tenu dans l'intervalle. `mandates:realign-office-starts` (simulation par défaut).
- Poste uniquement sur mandat **en fonction**. Gagner un poste le retire au détenteur. **Tous vidés** à l'élection d'un dirigeant.
- Réponse négative de `MandateAuthority` = « l'Office ne connaît pas », pas « siège vacant ».
- `character_id` en `cascadeOnDelete` (catégorie A).
- ⚠️ Prod : déploiement ne lance pas les migrations ; 10 titres anglais **relevés en jeu** (Connétable = Sergeant, etc.).

**Lot 2 — API joueur** (fil `mandats-lot2/`) : erreurs complètes (`MandateApiErrors` : `errors`, `codes`, `messages[champ] = {fr, en}`). `GET /mandates` renvoie `characters[].requestable`, libellés FR/EN sur chaque mandat. **Pas de `status_label`** : c'est une présentation frontend.

**Lot 3 — tâches planifiées** (09:00 Paris) :
- `mandates:send-reminders` — 2j après fin effective, si renouvelable, jamais deux fois.
- `mandates:verification-digest` — email/jour aux admin tant que file en retard (4j maire, 7j conseiller).
- `mandates:purge-rejected` — `rejected` > 3 mois.
- `mandates:status` — lecture seule.
- `MandateHeartbeat` : dernier passage réussi en cache, alerte après 36h sur `/dashboard`.

**Historique des postes** (fil `mandats-historique`) : `OfficeHistory` fusionne tables vivantes + `office_history_archive`. `AccountDeletion` = **porte unique** de suppression (archive, efface traces sans FK, supprime). ⚠️ `$user->delete()` ailleurs effacerait l'historique en silence (`AccountDeletionTest` le garde). `GameCalendar` : 2026→1474, **jumeau** de `gameCalendar.js` frontend.

**Registre des mines — PR 1a** (brief `brief-registre-mines.md`, fil `registre-mines`) : table `mine_reports`. Axes en clair, payload chiffré. 🔴 **Ajout seul** : un relevé ne se supprime jamais, se remplace (`active` 1/NULL, index unique `(province_id, reported_at, active)` ignore NULL). `character_id` en **nullOnDelete, catégorie C**.

**Registre des mines — PR 1b** : `MineRegistry` seul écrivain. Province du **poste**. Date = dernier jour des données du relevé. 409 si même province/jour sans `confirm_replace`. 🔴 **R3 bis** : refus si analysé identique ou strictement moins (comparaison par `facts()`). `mines:redate` pour les relevés pré-10/10.

**Registre — PR 4a, lecture** : `MineRegistryReader` sert les **faits** (sans texte brut). Mi-mandat/fin = `in_office_from` du lecteur + 30/60j. **Prédécesseur** = relevés d'**autres** dans les 30j avant le début.

**Registre — bilans de mandat passés** (fil `admin/echanges/bilans-passes-mines/`, décisions de Greg et Cowork du 22/11/2026) : un ancien titulaire relit, **en lecture seule**, les relevés de **son** mandat. `MandateAuthority::pastMineReaderMandates()` (règle d'**historique**, à part des méthodes « maintenant » — Q10 intact) : mandats `approved` (🔴 **jamais `revoked`**), entrés en fonction, **terminés** (`holds_until` échu), avec au moins une période de `MINE_READER_OFFICES` ; `lastMineReaderOffice()` = le dernier poste éligible tenu. `MineRegistryReader::readForMandate()` ; `readFor()` l'appelle avec le mandat en cours. 🔴 **Fenêtrage d'un mandat PASSÉ** : relevés de `in_office_from` − 30 j à `holds_until` **exclu** (le jour où le successeur entre n'est plus le sien) ; **aucun relevé postérieur** (sans cette borne, un ancien titulaire lirait pour toujours le travail de ses successeurs). Un mandat **en cours** garde l'ancien comportement (aucune borne haute). La réponse garde le format de `reports` et ajoute `read_only`, `window`, `mandate.id` et `mandate.ended_at`. `access` ajoute `past_mandates` (une entrée par mandat). **Hors scope** : le poste vidé pendant un mandat qui continue (élection d'un dirigeant) — le successeur nommé fera le bilan. Texte légal : front #153. ⚠️ **Prod** : `route:cache` ; aucune migration, `config/*.php`, `.env` ni paquet. Tests : `Mines/MinePastBalancesTest`.

**Prix des mines** (lot 2, brief `brief-prix-registre.md`) : `MinePrices` seul écrivain. `PRICE_OFFICES` (commerce, mines, bailli — tous égaux, dirigeant lit seul). Autorisation : mandat **approuvé** dont la fenêtre recouvre le jour de Paris, et fin < 7j grâce. Prix futur ou hors période refusé. `mines:prices-digest` 15:00 Paris (un email par occupant, pas ses propres changements). ⚠️ Prod : `route:cache`, `config:cache`.

## Animation — Décode le code (27/10/2026, refondue 28/10)

Brief `brief-coffres-scelles.md` ; **spéc. à jour : `admin/echanges/animation-coffres-scelles/12-synthese-cc.md`** (D1-D40). Organisateur scelle un coffre (5 chiffres), saisit les propositions, scoring Bulls-and-Cows.

**Deux modes** (`is_anonymous`) : **Privé** (character_id, catégorie A, cascadeOnDelete, réouvrable) et **Sans compte** (slug + mot de passe, clôture définitive).

🔴 **Autorisation PAR PROPRIÉTÉ** (premier module sans Spatie ni mandat). `DecodeActor` = personnage ou auteur sans compte. Refus **403** (`not_owner`).

| Pièce | Rôle |
|---|---|
| `DecodeWorkflow` | seul écrivain (création, coffres, réouverture, clôture, suppression) |
| `DecodeAccess` | preuve sans compte ; refus ne distingue pas slug inconnu/privé/close/mauvais mot de passe |
| `DecodeClosing` | **la** clôture. Sans compte : photo figée + effacement (coffre non percé, propositions, description, mdp, IP, email, jeton) |
| `DecodeScoring` | Bulls-and-Cows, trouvé (10) → **20** |
| `DecodeSlug` | slug : lettres+tirets, **aucun chiffre**, 3-20 car., aucun mot réservé, jamais de suffixe auto (D27) |
| `DecodePasswordReset` | lien à usage unique 24h, stocké haché, sans compte avec email confirmé → auto-demande, sinon admin émet et remet au pseudo en jeu |
| `DecodeProposals` | saisie, propriétaire seul |
| `DecodeActivityReader` | lecture publique et organisateur, deux sources (tables ou photo figée) |
| `DecodeExporter` | BBCode français fixe, lien par slug |
| `AnimMailer` + `Anim*` / `DecodeFinalReport` | **commun à toutes les activités sans compte** (`AnimKinds`). 4 emails bilingues : confirmation email, réinitialisation, avis mdp modifié, et (Décode seul) compte rendu de fin |
| `DecodeIp` | HMAC-SHA256 (clé = `MODULE_DATA_KEY`), **nulle** pour IP privée/bouclage |
| `DecodePassword` | 10 car., ≠ slug et pseudo |
| `DecodeLinks` | liens vers le **site des joueurs**, jamais admin. Page publique : `/public/activite/decode/{slug}` |
| `AnimApiErrors` | contrat d'erreur au patron de `MandateApiErrors`, périmètre propre |

🔴 **Le code du coffre en cours** n'est renvoyé à **personne** (le serveur tire la combinaison). Apparaît seulement une fois percé et dans le compte rendu de fin.
- Suppression : privée close par organisateur ; sans compte par admin seulement.
- Réouverture : privée seulement (refusée si autre activité active).
- **Une seule activité non close par type et par personnage** (privé) ; **une seule par empreinte d'IP** (sans compte).
- Page de résumé sans compte : 5 ans après clôture → suppression + libération slug.

⚠️ **Prod** : `route:cache`, `migrate` (sème le type `decode`), `anim:*` via `schedule:run` cPanel, **aucun** nouveau `config/*.php`/`.env`, **mesurer `$request->ip()` derrière O2Switch**, **rien avant le texte légal**.

## Animation — Grille des lots (10/11/2026, back #82, mergée le 11/11/2026)

Brief `brief-grille-des-lots.md`. Même modèle que Décode : **deux modes**, autorisation **par propriété** (catégorie A, cascadeOnDelete). `RewardsGridWorkflow` seul écrivain. Machine à états : `new → incomplete → filled → confirmed → closed` (🔴 **`drawed` jamais produit** : tirage → `confirmed` directement). Page publique qu'à partir du tirage.

Ajout de lots : `name` (un lot) ou `names` (liste, ≤ 500). Dimensions : 3-20 par côté. Emails par `AnimMailer` (option A, « faire comme Décode »), **aucun compte rendu de fin**. `RewardsGridPasswordReset` jumelle de `DecodePasswordReset`.

Tâches planifiées communes (`AnimKinds`) : `anim:close-inactive` (30j), `anim:purge-closed` (5 ans sans compte). Durées = **constantes** du modèle. 🔴 **Type `rewards` naît inactif** : activer depuis `/anim` après texte légal.

## Inventaires (11/11/2026, back #83 et #84 mergées le 11/11/2026 ; registre des stocks back #100 à #103, 20-21/11/2026)

Fils `inventaire-scopes/` et `inventaire-bilans/`. **Une table par niveau avec sa vraie FK** (pas de polymorphe). Niveau et propriétaire résolus **côté serveur** depuis le poste tenu maintenant (`InventorySettings::owner()`).

Autorisation (`MandateAuthority`) : personnel = le personnage ; ville = **maire** ; province = **commissaire au commerce** seul écrit, dirigeant lit aussi. Commissaire aux mines/bailli : **aucun accès**.

| Pièce | Rôle |
|---|---|
| `InventorySettingsDocument` | garde de forme : clés connues, slugs, tailles bornées (30 catégories, 3000 objets, 256 Ko) |
| `InventorySettings` | seul écrivain, **écriture à version** (409 `version_conflict`) |
| `InventoryText` | dernier inventaire collé, chiffré, remplacé à chaque collage |
| `InventoryRegistry` | **socle** des registres de territoire (fil `registre-stocks`, 20/11) : `record()` (texte identique du jour = rien créé ; autre texte = 409 `InventoryReplacementNeedsConfirmation` sauf `confirm_replace`, l'ancien passe `active` NULL + `replaced_by_id`) et `history()` (≤ 100 exemplaires en vigueur ; renvoie aussi `today` — le jour du jeu en cours, bascule 04:00 —, `mandate: { in_office_from, mid_at, end_at, predecessor_from }` — les repères du mandat **du lecteur**, au format des mines : entrée en fonction, mi-mandat et fin = la moitié et la totalité du mandat (`MID_DAYS`/`END_DAYS` des sous-classes), début de la fenêtre « prédécesseurs » (`PREDECESSOR_DAYS` = 30) — et `backdate_min`, pour qui écrit) |
| `ProvinceInventoryRegistry` / `CityInventoryRegistry` | sous-classes : modèle, clé du lieu, poste qui écrit/lit, repères du mandat. Province = commissaire au commerce (le **bailli n'écrit pas**, Q2), lit aussi le dirigeant, **30/60 jours du jeu** ; ville = **maire seul**, `office_key` = `MayorMandate::LEVEL`, **15/30 jours du jeu** |

🔴 **Date d'inscription** (brief `10-brief-date-inventaire`) : `POST` accepte `date` (Y-m-d) ; absente = jour du jeu en cours. Plage : de `BACKDATE_BEFORE_MANDATE_DAYS` = 14 jours du jeu **avant** `in_office_from` du titulaire (`MandateCalendar::gameDayOf` / `shiftGameDay`) à aujourd'hui, jamais le futur (422 `date`, `registry_date_out_of_range`). Le conflit se cherche **à cette date** : pendant le mandat → 409 `registry_needs_confirmation` puis `confirm_replace` ; **avant le mandat → 409 `registry_before_mandate_locked`, aucun « Remplacer », aucun rédacteur nommé** (l'inscription d'un prédécesseur est définitive) ; un texte **identique** déjà en place reste `unchanged` même avant le mandat. Le jour d'entrée en fonction lui-même est *pendant* le mandat.

🔴 **Auteurs** (Q5, politique §3) : l'auteur d'un exemplaire en vigueur n'est **jamais** nommé (`by_me` seulement) ; le pseudo n'apparaît que pour le rédacteur **remplacé** (`replaced` dans la liste) et pour l'exemplaire à remplacer (`existing` du 409) ; `pseudo` NULL si le personnage a disparu. **Pas de registre personnel** (Q4, reporté). Mi-mandat/fin pour la lecture analytique (PR 3b) : moitié et totalité du mandat — province 30/60 j, ville 15/30 j, en jours du jeu.

Copie : réglages **personnels** entre personnages **du même compte**. Ne copie jamais le texte collé. Routes : `GET/PUT characters/{id}/inventory-settings/{personal|city|province}` (PUT à version, `throttle:30,1`). ⚠️ Prod : `migrate`, `route:cache`, aucun nouveau config/`.env` ; **registre de la ville : `migrate` (table `city_inventory_snapshots`) et `route:cache` (routes `inventory/city/registry`), et l'écriture ne s'ouvrait qu'une fois la politique §3 en ligne (PR 0, front #143 et back #99, en ligne le 20/11/2026)**.

## Sécurité — douaniers (21/11/2026, back #104, mergée le 21/11/2026)

Brief `admin/content/brief-module-securite.md` ; fil `admin/echanges/module-securite/` (réponses de Greg `02`). **PR 2 sur 5** : les douaniers seulement, aucun bilan ni liste (PR 3).

Le douanier **n'existe pas en jeu** : rôle de l'Office, que le joueur **demande** (opt-in) et que le trio valide — jamais une nomination d'office. Une ligne de `customs_officers` porte la demande ET l'accès (`status` pending/approved/rejected/revoked, `active` 1/NULL, un seul en cours par personnage). `CustomsOfficers` est le **seul écrivain**.

| Pièce | Rôle |
|---|---|
| `MandateAuthority::customsDeciderLevel` | **Niveau 1** = prévôt des maréchaux ou connétable en fonction dans la province ; **niveau 2** = capitaine, dirigeant ou maire d'une ville de la province, **seulement si ni prévôt ni connétable n'est connu** ; niveau 3 = administrateur (page Blade `/customs`, hors de cette classe). `customsDeciderScope` donne province + niveau. `provinceHasSecurityTrio` = prévôt, connétable ou capitaine connu |
| `CustomsOfficers` | `request`, `decide`, `decideAsAdmin`, `withdraw` (retrait par soi : une demande en attente disparaît, un accès laisse sa trace), `revoke`, `recordReport` (**appelée par la saisie d'un bilan, PR 3** : seule action qui remet le compteur à zéro), `setInactivityDays`, `lapsesAt`, `present` |
| `customs:inactivity` | 09:00 Paris, heartbeat `customs`. **Avec trio connu : rien n'est révoqué**, l'API signale `inactive` au trio ; **sans trio** : avis email à 7 jours (une fois), révocation automatique à l'échéance, nouvelle demande possible |

🔴 Le repli (niveau 2) est **le mode courant** d'une province (limite 2 de `MandateAuthority` : l'Office ne connaît que les mandats déclarés) ; le frontend le dit (`decider.has_security_trio`). Inactivité : **90 jours par défaut, réglable 30 à 365** par le niveau 1 (ou 2) — **constantes de `CustomsOfficer`, pas de nouveau `config/*.php`** (piège du `config:cache`). Compteur : de `in_office_from`, puis `last_report_at`. Le pseudo d'un demandeur n'est servi qu'au décideur et à lui-même. Erreurs : `security.api.*`, mêmes garanties bilingues que les mandats (`MandateApiErrors::covers`). Export art. 20 : section `customs` (le décideur n'y est pas nommé). Tests : `Security/CustomsOfficersTest`, `Security/CustomsAdminTest`.

⚠️ **Prod** : `migrate` (deux tables), **`route:cache`** (routes API `customs` et web `/customs`), aucun nouveau `config/*.php` ni `.env`, aucun paquet ; la tâche passe par le cron `schedule:run` existant. **Rien n'est écrit en base avant le texte légal du module** (PR 3 : listes et bilans, pseudos de tiers) — cette PR ne touche aucun tiers sans compte.

## Registre de douane — bilans et listes (22/11/2026, PR 3-1)

Briefs `brief-module-securite.md` ; fil `admin/echanges/module-securite/` (décisions de Greg `02`, `05`, `08`, `11`, `12`). **Parseur au FRONTEND seulement, comme les mines** : le serveur reçoit le JSON analysé **et le brut**, valide la **forme**, ne re-parse jamais.

| Pièce | Rôle |
|---|---|
| `CustomsAccess` | **la** matrice d'accès (brief §3, corrigée) : prévôt/connétable = tout + correction + 7 jours en arrière ; capitaine = lecture/écriture sans correction ; maire = sa ville (province s'il est aussi douanier) ; douanier = toute la province ; **maire et douanier écrivent les listes SEULEMENT sans trio connu** ; juge = ajout au rouge ; procureur = listes en lecture ; dirigeant = lecture. Le repli « sans trio » est le mode **courant** d'une province |
| `MandateAuthority::securityScope` | rôle du personnage MAINTENANT dans UNE province (poste, mairie, douanier) |
| `CustomsReports` | **seul écrivain des bilans** : un par ville et par jour, tenu par la base (`active`) ; le **même auteur complète ET corrige le sien** le jour du jeu même (24/11/2026, fil `module-securite` 22 et 23 : ses groupes avaient changé après sa première saisie ; `can_correct` de `present()` le dit), le **prévôt et le connétable corrigent** le bilan d'un autre et les 7 jours passés (ajout seul : l'ancien passe `active` NULL ; texte légal §6 d'abord) ; refus d'un parseur trop ancien (`MIN_PARSER_VERSION`) ; saisie d'un douanier → `CustomsOfficers::recordReport` |
| `CustomsWatchlist` | **seul écrivain des listes** : l'auteur retire la sienne même avec un trio actif ; un retrait **supprime** la ligne ; changer de couleur repart d'une nouvelle date ; « ajouté par » visible du **trio** seulement |
| `CustomsPseudo` | comparaison **en minuscules**, affichage d'origine |
| `customs:remove-pseudo` | outil **interne, hors politique** (simulation par défaut ; `--force` applique) : supprime les entrées et **remplace** le pseudo dans l'analyse ET le brut de tous les bilans, comme MOT ENTIER (les points font partie des pseudos). Aussi sur `/customs` : nombres seulement, pseudo **jamais journalisé** |

🔴 **Payload chiffré** (`MODULE_DATA_KEY`) : par section `guet` / `visions` / `armies_groups`, `{raw, parser_version, report}` ; brut = liste des villageois **sans préambule**, collage armées/groupes/contrôle de la zone, lignes des visions **du jour D** (C1/C2 : l'en-tête J = la nuit J-1 → J). Axes en clair : ville, jour, auteur, couleur. **La lecture ne sert jamais le brut ni l'auteur** (`by_me` seulement) ; la **coloration** est calculée à la lecture, en minuscules, à partir de la date d'ajout ; le **différentiel du guet** compare au dernier bilan antérieur de la ville et dit lequel.
⚠️ Le jour D est décidé par le **serveur** (jour du jeu, bascule 04:00) ; `GET customs-access` le dit au client avant la saisie. Export art. 20 : section `customs_activity`, **trace sans contenu** (jour, ville, couleur, sections). **`SecurityNotConsultableTest`** garde la promesse de la politique : aucune surface d'administration ne référence ces tables ni ces classes.
⚠️ **Prod** : `migrate` (2 tables), `route:cache` (routes `customs-access`, `customs-reports`, `customs-lists` et web `customs/remove-pseudo`), aucun `config/*.php`/`.env`. **L'écriture ne s'ouvre qu'une fois le texte légal vérifié en ligne et `policy:notify` lancé.**

**Relevé unique — `customs-preview`** (PR 3-2, fil `module-securite` 12 à 14, 22/11/2026) : le Relevé du guet (page publique) colle les cinq types ; avec un compte, il demande au serveur, **avant tout enregistrement**, la couleur des pseudos collés et le guet du dernier bilan de la ville (`CustomsReports::preview`). 🔴 **Rien n'est écrit ni journalisé** (test `CustomsPreviewTest`) : seuls les pseudos arrivent (≤ 2000, sans espace), seuls ceux **envoyés** reviennent colorés (jamais la liste), à la date d'ajout du jour du jeu, en minuscules. Droits : couleurs = droit de **lire les listes** (Q4), guet précédent = droit de **lire les bilans de la ville** (Q3) ; un simple joueur avec compte reçoit 422 `no_security_role`. Route couverte par `MandateApiErrors::covers`. ⚠️ **Prod : `route:cache`** (route ajoutée) ; aucun `migrate`, `config/*.php`, `.env` ni paquet.

**Vue d'ensemble de la province — `customs-province`** (PR 5, fil `module-securite` 18 et 19, maquette validée par Greg le 23/11/2026) : `CustomsProvinceOverview`, **lecture seule et aucun droit nouveau** — seules les villes que le rôle lit déjà (`CustomsAccess::readableCities`), et la vue n'a de sens qu'à partir de **deux** villes lisibles (trio, douanier, dirigeant ; un maire simple, un juge, un procureur ou un joueur sans rôle : 403 bilingue `province_not_readable`). 🔴 **Fusion des dernières inscriptions, chaque ligne datée de son jour de bilan, jamais de différentiel entre villes** : par ville, le bilan le **plus récent qui contient la section** (guet, visions, armées/groupes — une ville peut avoir envoyé le guet hier et les armées il y a quatre jours), sans limite d'âge ; on ne fusionne PAS les listes d'habitants, seulement les **marques des listes** (un pseudo rouge ou vert, où et comment vu : habitant, vision, commandant, membre d'un groupe), les **groupes à surveiller** (seul « Groupe de … » alerte) et les **armées**, plus, par ville, la couverture (dernier bilan, nombre d'habitants, **nombre** de groupes — jamais leur détail) et les **PSEUDOS** des arrivées et des départs (Greg, 24/11/2026 : « pas juste les comptes »), comparés au bilan antérieur de la MÊME ville — des différences, jamais la liste des habitants. **Naviguer dans les jours** (Greg, 24/11/2026) : `?date=AAAA-MM-JJ` donne la situation **AU jour D** (pour chaque ville, ses bilans jusqu'à ce jour ; jamais le futur, ramené à aujourd'hui ; mal formée : 422) ; la réponse dit `as_of` (le jour demandé), `today` (le jour du jeu) et `days` (les 90 jours les plus récents qui ont un bilan dans une ville lisible, de quoi naviguer comme dans le Registre). Agrégé côté serveur : jamais le brut, jamais l'auteur, jamais la liste complète des habitants ; couleurs à la date de chaque bilan (`CustomsReports::present`) ; au plus 60 bilans lus par ville. ⚠️ **Prod : `route:cache`** (route ajoutée) ; aucun `migrate`, `config/*.php`, `.env` ni paquet. Tests : `Security/CustomsProvinceTest`.

## Icônes des items (05/11/2026)

Brief `brief-icones-items.md`. Table `item_icons` : nom → `game_item_id` Celsius Online. L'Office ne stocke **aucune image**. Lecture publique : `GET item-icons?names[]=…` (200 max), clé = **nom tel que demandé** (rapprochement PHP `normalizeName` : casse et espaces ignorés, **accents significatifs**). `Cache-Control: public, max-age=86400`. ⚠️ Collation `utf8mb4_unicode_ci` refuse deux noms ne différant que par casse/accent. Admin : page Blade `/item-icons`. 🔴 **Aucun aperçu** côté frontend (IP joueur → CDN Celsius exclu par `/legal/privacy` §6). Tests : `ItemIconsTest`.

## Mode maintenance (18/11/2026, back #89, mergée le 18/11/2026)

Brief `brief-mode-maintenance.md`. **Pas de SSH** : `scripts/maintenance.sh` dépose/retire `storage/framework/down` **par FTP**. `deploy.yml` : ferme avant envoi, rouvre après (sauf si déjà fermé → mode `held`). `maintenance.yml` : ferme/rouvre à la main.

🔴 **Secret** : `MAINTENANCE_SECRET` (GitHub Secret, 16+ car. `[A-Za-z0-9_-]`). Absent → déploiement sans maintenance.
- API : 503 JSON `{message: "maintenance", retry_after: 300}` + `Retry-After`. `HandleCors` **avant** maintenance (sinon erreur réseau). Bypass : cookie natif (Blade) + header `X-Maintenance-Bypass` (site joueurs, `hash_equals`).
- Page 503 Blade avec accès administrateur (champ masqué → `/{secret}`).
- Tests : `MaintenanceModeTest`.
- ⚠️ Prod : créer `MAINTENANCE_SECRET` dans GitHub Secrets.

## Annonces du site (18/11/2026, back #91, mergée le 18/11/2026)

Annonce de maintenance défilante. Table `site_announcements` : message FR/EN (500 car.), échéance `exact`/`period`/null, visibilité `visible_from`/`visible_until`, `is_active`. Dates en UTC, saisies à Paris.

Marqueur `{quand}`/`{when}` remplacé par l'API (dates formatées, « cette semaine »/« la semaine prochaine »). **Une seule annonce à la fois** (maintenance la plus proche d'abord). API publique `GET announcements/current`, cache 60s. Admin Blade `/announcements`. Tests : `AnnouncementsTest`. ⚠️ Prod : `migrate` + `route:cache`.

## Tests

`docker exec odc-backend php artisan test` — le décompte s'affiche à la fin de la suite et dans la CI : **plus recopié** dans le `README.md` (25/11/2026, `scripts/docs-sync-check.sh` le refuse). `CharacterFactory` utilise `RAND()` MySQL → passer `city_id: null` en test (incompatible SQLite). Factories Kingdom/Province/City disponibles. Lien signé : `URL::temporarySignedRoute(…)` plutôt que parser l'email.

## Contraintes projet

- **Aucun texte en dur** — `__()` partout, `lang/fr` complet, `lang/en` seulement pour l'anglais réel. Blade en français, sans bascule de langue.
- Ne jamais committer `.env`/credentials.
