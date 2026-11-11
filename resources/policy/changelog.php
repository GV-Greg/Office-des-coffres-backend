<?php

/*
|--------------------------------------------------------------------------
| Journal des modifications de la politique de confidentialité (/legal/privacy)
|--------------------------------------------------------------------------
|
| SOURCE UNIQUE (fil admin/echanges/politique-promesses, Q6 : aucune copie dans admin/). Lu par
| App\Support\PolicyChangelog, envoyé par `php artisan policy:notify <id>` — commande lancée À LA
| MAIN par Greg, jamais planifiée (brief-politique-promesses §4.3).
|
| Une entrée par modification du texte, clé = identifiant stable (jamais réutilisé) :
|
|   'AAAA-MM-JJ-sujet' => [
|       'date'        => 'AAAA-MM-JJ',     // mise en ligne du texte modifié
|       'summary'     => ['fr' => '…', 'en' => '…'],   // ce qui change, une ou deux phrases
|       'substantial' => true,             // décision humaine, à chaque fois — jamais déduite d'un diff
|       'decided_by'  => 'Greg',           // qui a tranché « substantielle ou non »
|   ],
|
| ⚠️ N'ajouter une entrée substantielle qu'une fois le texte EN LIGNE : l'email renvoie à la page.
| Une entrée non substantielle s'écrit aussi (trace de la décision) : la commande refuse de l'envoyer.
*/

return [
    // Premier envoi réel du mécanisme §10 (brief politique-promesses §4.4) : en ligne par front #79.
    '2026-10-04-comptes-inactifs' => [
        'date' => '2026-10-04',
        'summary' => [
            'fr' => "Un compte inactif est désormais supprimé après 1 an sans connexion (au lieu de 2 ans), toujours après un email de préavis. Nouvelle règle : un compte dont l'adresse email n'est pas confirmée est supprimé au bout de 30 jours, et au plus tôt une semaine après un rappel envoyé à cette adresse.",
            'en' => 'An inactive account is now deleted after 1 year without login (instead of 2 years), still following a notice email. New rule: an account whose email address is not confirmed is deleted after 30 days, and no sooner than one week after a reminder sent to that address.',
        ],
        'substantial' => true,
        'decided_by' => 'Greg',
    ],
    // Registre des mines (front #84 et #85, en ligne le 08/10/2026) — une entrée propre à cette
    // modification (Greg, 07/10 : une entrée par PR de politique, jamais fusionnées).
    '2026-10-08-registre-des-mines' => [
        'date' => '2026-10-08',
        'summary' => [
            'fr' => "La politique de confidentialité décrit désormais le Registre des mines, qui ouvrira bientôt : ce qu'il conserve quand un commissaire aux mines ou un bailli y enregistre un relevé, sans limite de durée, qui peut le consulter (ces deux titulaires et le dirigeant de la province), et ce qui se passe si vous supprimez votre compte : le relevé reste, mais le lien avec votre compte est supprimé.",
            'en' => "The privacy policy now describes the Mine Registry, which will open soon: what it stores when a Mines Superintendent or Sheriff saves a report to it, with no time limit, who can read it (those two office-holders and the province's leader), and what happens if you delete your account: the report remains, but its link to your account is removed.",
        ],
        'substantial' => true,
        'decided_by' => 'Greg',
    ],
    // Page « Vos données, outil par outil » (front #90, en ligne le 11/10/2026) — une entrée propre à
    // cette modification (Greg, 07/10 : une par PR de politique). Résumé validé par Greg tel quel
    // (fil admin/echanges/pages-donnees-joueur, 10 et 12).
    '2026-10-11-vos-donnees' => [
        'date' => '2026-10-11',
        'summary' => [
            'fr' => "Nouvelle page « Vos données, outil par outil » : pour chaque outil de l'Office, elle explique à quoi il sert, qui peut l'utiliser (sans compte, avec un compte, ou avec un poste validé) et ce que vous y mettez. La politique de confidentialité y renvoie désormais, outil par outil ; ses règles ne changent pas.",
            'en' => 'New page "Your data, tool by tool": for each Office tool, it explains what it is for, who can use it (no account, an account, or a validated office) and what you put into it. The privacy policy now links to it, tool by tool; its rules do not change.',
        ],
        'substantial' => true,
        'decided_by' => 'Greg',
    ],
    // Le commissaire au commerce consulte le Registre des mines (front #91, en ligne le 16/10/2026, back #64 en ligne le
    // 15/10) — une entrée propre à cette modification (Greg, 07/10 : une par PR de politique). « Substantielle » :
    // décision de Greg, 16/10/2026 (elle ajoute un destinataire à la liste de ceux qui lisent le registre, §6).
    '2026-10-16-commerce-lit-registre' => [
        'date' => '2026-10-16',
        'summary' => [
            'fr' => "Le commissaire au commerce d'une province peut désormais consulter le Registre des mines de cette province, en lecture seule, comme son dirigeant : il rejoint la liste de ceux qui y ont accès (article 6 de la politique de confidentialité). L'écriture reste réservée au commissaire aux mines et au bailli. Rien d'autre ne change.",
            'en' => "A province's Trade Minister may now read that province's Mine Registry, read-only, like its leader: they join the list of people who have access to it (section 6 of the privacy policy). Writing stays reserved for the Mines Superintendent and the Sheriff. Nothing else changes.",
        ],
        'substantial' => true,
        'decided_by' => 'Greg',
    ],
    // Prix des mines de la province (front #94, en ligne le 16/10/2026 ; les prix eux-mêmes, back #63, en ligne le 15/10) — une
    // entrée propre à cette modification (Greg, 07/10 : une par PR de politique). « Substantielle » : décision de Greg, 16/10/2026
    // (donnée nouvelle liée à un compte, destinataire nouveau — l'email quotidien —, durée sans limite). Texte :
    // admin/content/policy-prix-registre-draft.md.
    '2026-10-16-prix-des-mines' => [
        'date' => '2026-10-16',
        'summary' => [
            'fr' => "Les prix des mines d'une province sont désormais tenus par le commissaire au commerce, le commissaire aux mines et le bailli : l'Office conserve ces prix sans limite de durée, avec le personnage qui les a saisis ou corrigés. Les autres titulaires de ces postes reçoivent un résumé quotidien des changements. Si votre compte est supprimé, les prix restent, sans lien avec vous.",
            'en' => 'A province\'s mine prices are now kept by the Trade Minister, the Mines Superintendent and the Sheriff: the Office keeps these prices with no time limit, along with the character who recorded or corrected them. The other holders of these offices receive a daily summary of changes. If your account is deleted, the prices stay, with no link to you.',
        ],
        'substantial' => true,
        'decided_by' => 'Greg',
    ],
    // Décode le code, activité du module Animation (back #72, front #101 — à dater de leur mise en ligne) — une entrée propre à
    // cette modification. « Substantielle » : décision de Greg, 28/10/2026 (données nouvelles : mot de passe hors compte, email
    // facultatif, empreinte d'IP en mode public ; l'activité privée disparaît avec le personnage ou le compte). Texte :
    // admin/content/policy-coffres-scelles.md. Ne s'envoie (`policy:notify`) qu'une fois le texte EN LIGNE.
    '2026-10-28-decode-le-code' => [
        'date' => '2026-10-28',
        'summary' => [
            'fr' => "Une nouvelle activité, « Décode le code », ouvre dans la Salle des Jeux. Jouée avec votre personnage, elle disparaît avec lui ou avec votre compte. Jouée sans compte, l'Office conserve un pseudo, un mot de passe haché, une adresse email facultative et une empreinte à sens unique de la connexion, effacés à la clôture ; la page de résumé reste visible 5 ans, sans les propositions reçues (articles 3, 5 et 6 de la politique de confidentialité, et article 3 de la politique des cookies).",
            'en' => 'A new activity, “Crack the Code”, opens in the Games Hall. Played with your character, it disappears with it or with your account. Played without an account, the Office keeps a nickname, a hashed password, an optional email address and a one-way fingerprint of the connection, erased when the activity closes; the summary page stays visible for 5 years, without the proposals received (sections 3, 5 and 6 of the privacy policy, and section 3 of the cookie policy).',
        ],
        'substantial' => true,
        'decided_by' => 'Greg',
    ],
    // Grille des lots, 2ᵉ activité du module Animation, et réécriture du texte d'Animation en UN chapitre commun avec Décode le
    // code (back et front de `feat/rewards-grid` — à dater de leur mise en ligne ; la date ci-dessous est provisoire). Une entrée
    // propre à cette modification. « Substantielle » : nouveau traitement (la Grille des lots : données du mode sans compte
    // identiques à celles de Décode le code, lots et noms de personnages conservés 5 ans après la clôture) et réécriture du
    // chapitre en un texte unique — sans mention d'un changement de la clôture à 30 jours (Cowork et Greg, 11/11/2026 : le
    // texte en ligne la décrivait déjà pour les deux modes). Texte : admin/content/policy-animation.md ; fil
    // admin/echanges/animation-textes-legaux/. Ne s'envoie (`policy:notify`) qu'une fois le texte EN LIGNE ; le type `rewards`
    // ne s'active (bouton de /anim) qu'après.
    '2026-11-11-animation-grille-des-lots' => [
        'date' => '2026-11-11',
        'summary' => [
            'fr' => "Une deuxième activité, la « Grille des lots », rejoint « Décode le code » dans la Salle des Jeux, et la politique de confidentialité décrit désormais les deux dans un seul chapitre « Animation ». Jouée avec votre personnage, une activité disparaît avec lui ou avec votre compte. Jouée sans compte, l'Office conserve un pseudo, un mot de passe haché, une adresse email facultative et une empreinte à sens unique de la connexion, effacés à la clôture ; la page de résumé reste visible 5 ans — pour la Grille des lots, avec ses lots et les noms des personnages qui ont choisi une case (articles 3, 5 et 6 de la politique de confidentialité, article 3 de la politique des cookies, et page « Vos données »).",
            'en' => 'A second activity, the “Prize Grid”, joins “Crack the Code” in the Games Hall, and the privacy policy now describes both in a single “Animation” chapter. Played with your character, an activity disappears with it or with your account. Played without an account, the Office keeps a nickname, a hashed password, an optional email address and a one-way fingerprint of the connection, erased when the activity closes; the summary page stays visible for 5 years — for the Prize Grid, with its prizes and the names of the characters who chose a cell (sections 3, 5 and 6 of the privacy policy, section 3 of the cookie policy, and the “Your data” page).',
        ],
        'substantial' => true,
        'decided_by' => 'Greg',
    ],
    // Gestion des stocks — inventaires (back #83 et front #111 mergées le 11/11/2026 ; le texte de la politique, de « Vos données » et
    // des cookies part avec `feat/inventory-legal` — à dater de sa mise en ligne, la date ci-dessous est provisoire). Une entrée propre à
    // cette modification. « Substantielle » : nouveau traitement (réglages et texte des inventaires collés enregistrés pour un compte,
    // chiffrés ; historique de la province) et nouveaux destinataires (le maire lit l'inventaire de sa ville, le commissaire au commerce
    // et le dirigeant celui de leur province). Texte : fil admin/echanges/inventaire-scopes/02-reponse-cowork.md, validé par Greg le
    // 11/11/2026. Ne s'envoie (`policy:notify`) qu'une fois le texte EN LIGNE.
    '2026-11-11-gestion-des-stocks' => [
        'date' => '2026-11-11',
        'summary' => [
            'fr' => "Un nouveau chapitre, la « Gestion des stocks », décrit désormais ce que l'Office conserve de vos inventaires. Avec un compte, vos réglages d'inventaire et le texte de vos inventaires collés sont enregistrés en base de données (le texte est chiffré), afin que vous les retrouviez d'un appareil à l'autre ; sans compte, ils restent dans votre navigateur. L'inventaire d'une ville est lisible du maire en fonction, celui d'une province du commissaire au commerce et du dirigeant, et la province en conserve l'historique. Consultez la politique de confidentialité mise à jour pour le détail.",
            'en' => 'A new chapter, “Stock Management”, now describes what the Office keeps of your inventories. With an account, your inventory settings and the text of your pasted inventories are stored in the database (the text is encrypted) so that you can find them again from one device to another; without an account, they stay in your browser. A city’s inventory can be read by its acting mayor, a province’s by its Trade Minister and its leader, and the province keeps its history. See the updated privacy policy for details.',
        ],
        'substantial' => true,
        'decided_by' => 'Greg',
    ],
];
