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
