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
];
