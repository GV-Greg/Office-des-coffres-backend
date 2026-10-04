<?php

// Sortie de `logs:prune` (rétention des journaux, /legal/privacy §5). Français seulement : rien ne
// part chez le joueur.
return [
    'misconfigured' => "logging.channels.daily.days absente ou invalide — config en cache périmée ? Lancer php artisan config:cache en SSH. Rien n'a été effacé.",
    'pruned' => ':count journal(aux) de plus de :days jours supprimé(s).',
];
