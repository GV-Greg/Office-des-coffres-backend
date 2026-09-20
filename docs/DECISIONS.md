# Décisions structurantes — Office des Coffres (backend)

ADR minimalistes : titre, contexte, décision, conséquences. Une entrée par décision non triviale
qui aurait pu raisonnablement être prise autrement. But : que la raison derrière un choix
survive au contributeur qui l'a fait, sans avoir à fouiller `roadmap.md` ou l'historique git.

## Sanctum plutôt que Passport pour l'authentification API

**Contexte** — Laravel propose deux solutions officielles pour l'auth API : Sanctum (tokens
simples, sessions SPA) et Passport (OAuth2 complet). Passport a été évoqué au tout début du
projet (une commande `passport:install` traînait encore dans d'anciens scripts).

**Décision** — Sanctum. Le frontend est un client de confiance (même éditeur), pas un
consommateur tiers : pas besoin du protocole OAuth2 complet, ses scopes, ni la complexité de
gestion de clients qui va avec.

**Conséquences** — Auth simple par token bearer (`personal_access_tokens`). Si un jour un client
tiers doit consommer l'API (partenaire externe, appli mobile indépendante), Passport ou un
OAuth2 dédié redeviendra pertinent — pas un blocage architectural, juste hors scope actuel.

## Deux flux de vérification d'email indépendants (Blade admin vs API joueurs)

**Contexte** — Le projet sert deux publics distincts : Greg en admin via le panneau Blade
(session `web`), et les joueurs via l'API Vue (token Sanctum). Les comptes admin n'ont pas de
`Character`.

**Décision** — Ne pas réutiliser le flux Breeze standard (`verification.verify`, Blade) pour les
joueurs. Créer un second flux API dédié (`verification.verify.api`, `VerifyApiEmail`) avec son
propre lien signé, sa propre redirection (`FRONTEND_URL`), son propre texte 100% français.

**Conséquences** — Deux notifications à maintenir en parallèle, mais aucun couplage entre le
scaffolding Breeze (non modifié, stable) et la logique métier joueurs (évolutive). Un changement
du flux joueurs ne risque jamais de casser l'auth admin, et inversement.

## Connexion par email plutôt que par pseudo

**Contexte** — Le flux initial (Phase 1) authentifiait par pseudo, hérité du fait qu'un compte
n'avait qu'un seul personnage à l'origine.

**Décision** — Basculer sur l'email dès qu'un compte peut avoir plusieurs personnages (04/08/2026)
— un pseudo n'identifie plus un compte de façon unique, seul l'email le fait.

**Conséquences** — `login()` et l'écran `LoginView.vue` changent de champ. Le blocage associé
change de nature au même moment (voir entrée suivante).

## Login bloqué sur l'email non vérifié, plus sur le personnage non validé

**Contexte** — Avant la refonte multi-personnages (PR #3, 03/08/2026), la connexion était bloquée
tant qu'un admin n'avait pas validé le personnage unique du compte — logique quand un compte = un
personnage.

**Décision** — Dès qu'un compte peut avoir plusieurs personnages, ce blocage n'a plus de sens
(lequel des personnages ferait foi ?). Remplacé par un blocage sur l'email non vérifié — la
validation par personnage continue d'exister (`characters.validate`, dashboard admin) mais ne
bloque plus l'accès au compte, seulement les fonctionnalités liées à ce personnage précis.

**Conséquences** — Un compte peut se connecter avec 0 personnage validé. Les modules qui
nécessitent un personnage validé doivent vérifier ce statut eux-mêmes, pas compter sur le login
pour l'avoir déjà filtré.

## Pas de migration des comptes pré-existants à la refonte inscription

**Contexte** — La refonte du 04/08/2026 (email vérifié, comptes multi-personnages) change en
profondeur le schéma logique des comptes joueurs.

**Décision explicite de Greg** — Repartir à 0. Aucun code de compatibilité ascendante pour les
comptes créés avant cette date.

**Conséquences** — Les comptes joueurs antérieurs au 04/08/2026 (peu nombreux, projet en phase de
reprise) sont perdus. Choix délibéré pour ne pas alourdir le code d'un chemin de migration à
usage unique.

## Passport plutôt que Sanctum pour l'authentification API (bascule du 09/08/2026)

**Contexte** — La décision Sanctum ci-dessus tenait tant que l'auth se limitait à un token bearer
sans notion de renouvellement. L'implémentation de « Rester connecté » (item #13 de
`admin/strategies/cookies.md`) impose un couple access token court (15 min) + refresh token long
(30 jours si coché, 12h sinon), avec rotation à chaque refresh — logique de sécurité non triviale
(détection de réutilisation d'un refresh token volé, expiration glissante) que Sanctum ne fournit
pas nativement.

**Décision** — Migrer vers Laravel Passport (OAuth2), en n'utilisant que le strict nécessaire :
grant `password` (émission access+refresh depuis email/mot de passe, appelé en interne par
`AuthController::login()`, jamais exposé tel quel au frontend) et `refresh_token`. Pas de flux
`authorization_code`, pas d'écran d'autorisation, pas de notion de client tiers — le frontend reste
un client de confiance comme au moment de la décision Sanctum initiale, seul le mécanisme de
renouvellement change. Inverse donc la décision précédente sans en contredire le motif : le besoin
d'un protocole OAuth2 complet reste hors scope, seule la brique refresh-token-avec-rotation d'un
serveur OAuth2 standard et audité (`league/oauth2-server`, sur lequel Passport s'appuie) était
recherchée, plutôt que réinventer cette logique côté maison sur Sanctum.

**Conséquences** — `AuthController::login()` route la connexion à travers le grant `password` via
un dispatch HTTP interne vers `/oauth/token` (`Request::create()` + `app()->handle()`, pas un vrai
appel réseau sortant — évite la dépendance à `config('app.url')` et fonctionne identiquement en
test/dev/prod). Un unique client OAuth (`PassportClientSeeder`, ID/secret fixés via `.env` pour
survivre à un `migrate:fresh`) cumule les grant types `password`/`refresh_token`/`personal_access`
— ce dernier réutilisé par le lien de vérification d'email (`AuthController::verifyEmail()`), qui
émet un jeton simple sans refresh (pas de mot de passe disponible à cette étape). Les clés de
chiffrement Passport (`PASSPORT_PRIVATE_KEY`/`PASSPORT_PUBLIC_KEY`) sont stockées en variables
d'environnement (contenu PEM, `\n` échappés) plutôt qu'en fichiers `storage/oauth-*.key` : évite un
problème de permissions Unix sur les montages Windows (WSL/DrvFs) en dev, et simplifie le
déploiement FTP (rien à déposer manuellement en plus de `.env`).

## Reset complet de la base prod plutôt que migration corrective (incident du 05/08/2026)

**Contexte** — Premier test réel d'inscription en prod : la base (`kywq6025_odc`) s'est révélée
être celle du projet original de 2021, jamais recréée, avec un décalage de schéma majeur
(`users.username` legacy, tables Passport OAuth mortes) et 7 tables `anim_*` contenant de vraies
données communautaires 2022-2024, jamais reprises par le code actuel.

**Décision prise avec Greg** — `migrate:fresh` + reseed plutôt qu'une migration corrective qui
aurait dû composer avec un schéma hybride imprévisible. Dump de sauvegarde gardé en local par Greg
avant le reset.

**Conséquences** — Perte définitive assumée des 8 comptes legacy et des données `anim_*` côté
prod (dump froid conservé, potentiellement réutile pour le futur module Animation). Base repartie
propre depuis les migrations actuelles, sans dette de schéma cachée.

## Toute donnée rattachée à un compte porte une catégorie de cycle de vie (19/09/2026)

**Contexte** — La vérification en prod de `DELETE /api/v1/auth/account` a été concluante sur tout
ce qui avait été prévu, et a laissé passer `password_reset_tokens` : la table est clé par `email`,
sans FK vers `users`, et une adresse email survivait donc à un effacement art. 17 — contre ce que
promet `/legal/privacy` §5. Ce n'était pas une erreur dans `destroyAccount()` : la table n'avait
simplement jamais été regardée. Le code de suppression avait été écrit à partir des données qu'on
avait en tête, pas de l'inventaire des tables rattachées à un compte. Tant que le schéma se
limitait à `users` + `characters`, la question ne se posait pas ; Douane, Registre des mines,
Animation et Compagnie sont tous des modules où un compte produit de la donnée.

**Décision** — Toute table portant un lien vers un compte appartient à **exactement une**
catégorie, décidée **à la création de la table**, pas le jour où quelqu'un demande son effacement :
**A** cascade (le défaut), **B** nettoyage explicite dans `destroyAccount()` ou par un hook de
paquet, **C** anonymisation (`user_id` à `NULL`, contenu conservé), **D** conservation justifiée et
non réidentifiante. La règle complète, les trois questions qui mènent à la catégorie et
l'inventaire à jour vivent dans `admin/strategies/donnees-utilisateur.md`.

La première formulation envisagée était « tout cascader pour les futurs modules privés ». Écartée :
sur un module où un compte produit de la donnée que d'autres consultent, cascader détruit de la
donnée communautaire à chaque suppression, et ne pas cascader viole l'art. 17. La catégorie C est
la sortie de ce conflit — la personne disparaît, la contribution reste.

**Conséquences** — Une décision de plus à chaque migration créant une table liée à un compte,
contre une donnée personnelle orpheline découverte après coup. La règle est tenue par un test
structurel (`tests/Feature/Enforcement/UserDataLifecycleTest.php`) qui échoue sur toute table non
déclarée, parce qu'une convention écrite ne survit pas à trois modules. Ce test vérifie la
*déclaration*, pas que l'effacement *fonctionne* : l'introspection des FK diffère entre SQLite (CI)
et MariaDB (prod), et une table MyISAM ignorerait ses contraintes sans rien signaler — seule une
vérification en prod attrape ça, et elle reste nécessaire après tout changement de
`destroyAccount()`.

## Les données de module sont chiffrées avec une clé dédiée, jamais `APP_KEY` (20/09/2026)

**Contexte** — Greg est à la fois éditeur de l'Office et joueur de Renaissance Kingdoms. Les
modules à venir (Douane, Registre des mines) porteront des données qui donnent un avantage de jeu :
quantités, prix, stocks. L'objectif n'est **pas** une impossibilité technique d'accès — elle
n'existe pas, qui détient l'hébergement, la base et le code peut lire ce que l'application
affiche. L'objectif est qu'un `SELECT *` en phpMyAdmin ne rende rien d'exploitable, et que lire
suppose d'écrire délibérément un script qui amorce Laravel. C'est de la friction, assumée comme
telle.

Le réflexe aurait été d'utiliser le cast `encrypted` de Laravel, qui s'appuie sur `APP_KEY`.

**Décision** — Une clé dédiée `MODULE_DATA_KEY` et un cast maison (`App\Casts\EncryptedModuleData`)
bâti sur `Illuminate\Encryption\Encrypter`, plus un équivalent d'`APP_PREVIOUS_KEYS`
(`MODULE_DATA_PREVIOUS_KEYS`). Décidé **avant** la première migration de module, alors qu'aucune
table concernée n'existe.

La raison tient au coût asymétrique d'une rotation de clé : `php artisan key:generate` est une
commande de routine, et faire tourner `APP_KEY` ne casse que des choses récupérables — sessions,
cookies, jetons de réinitialisation, tous réparés par une reconnexion. La même rotation sur des
données de module les rendrait **définitivement illisibles**. Une clé dont la perte est
irréversible ne doit pas être celle qu'un framework fait tourner en routine.

Corollaire du patron : **on chiffre le contenu, jamais les axes.** `city_id`, `province_id`,
`character_id` et `reported_at` restent en clair, parce qu'une colonne chiffrée n'est ni
indexable, ni triable, ni filtrable en SQL — l'IV aléatoire fait qu'une même valeur produit un
chiffré différent à chaque écriture, si bien que même l'égalité échoue. Filtrage par lieu et date
en SQL, déchiffrement puis agrégation en PHP.

**Conséquences** — Une variable d'environnement de plus, à reporter à la main sur le `.env` de
prod et à **sauvegarder ailleurs que la base** : si le dump SQL et le `.env` voyagent ensemble, le
chiffrement n'a rien acheté, même contre un vol de dump. Une sauvegarde jamais restaurée n'étant
pas une sauvegarde, la restauration doit être testée une fois.

`key:generate` est par ailleurs désactivée en production, mais c'est une ceinture-bretelles, pas
la défense : bloquer une action se contourne (`--force`, un accès direct au `.env`, un futur qui a
oublié pourquoi), tandis que supprimer la conséquence ne se contourne pas. Ordre d'importance :
la clé dédiée et le filet de clés antérieures d'abord, le blocage ensuite.

Enfin, l'agrégation en PHP plutôt qu'en SQL est négligeable à l'échelle de cette communauté
(quelques milliers de lignes) et deviendrait rédhibitoire sur un volume réel — limite à rappeler
si un module prend de l'ampleur.

Raisonnement complet : `admin/strategies/donnees-utilisateur.md` §5.
