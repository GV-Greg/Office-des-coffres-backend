<?php

/*
|--------------------------------------------------------------------------
| Purge des comptes (accounts:purge) — /legal/privacy §5
|--------------------------------------------------------------------------
|
| Brief admin/content/brief-politique-promesses.md §2 ; fil admin/echanges/politique-promesses.
| Durées décidées par Greg le 04/10/2026. ⚠️ Ce sont des durées PROMISES : toute modification oblige
| à relire la politique, et reste sans effet en prod avant `php artisan config:cache`.
*/

return [
    // 🔴 Tant que le texte en ligne annonce 2 ans, supprimer à 1 an romprait la promesse (brief §6) :
    // à `false`, la commande ne fait que lister ce qu'elle ferait (--dry-run imposé). Passe à `true`
    // dans la PR qui accompagne la mise en ligne du nouveau texte (étape 5).
    'enforce' => false,

    // Compte vérifié : suppression après `inactivity_days` sans passage (users.last_seen_at), et au
    // plus tôt `inactivity_notice_days` après le préavis — qui part donc à 11 mois.
    'inactivity_days' => 365,
    'inactivity_notice_days' => 30,

    // Compte jamais confirmé : rappel à J+`unverified_reminder_after_days`, suppression à
    // J+`unverified_days` et au plus tôt `unverified_min_notice_days` après le rappel (Q3 : la
    // garantie ne dépend pas de l'âge du compte).
    'unverified_days' => 30,
    'unverified_reminder_after_days' => 23,
    'unverified_min_notice_days' => 7,
];
