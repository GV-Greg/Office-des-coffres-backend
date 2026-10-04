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
];
