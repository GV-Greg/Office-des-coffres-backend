<?php

return [
    'title' => 'Tableau de bord',

    // Bandeau de stats
    'stat_users'              => 'Comptes',
    'stat_users_unverified'   => ':count non vérifié|:count non vérifiés',
    'stat_characters'         => 'Personnages',
    'stat_characters_pending' => ':count à valider',
    'stat_mandates'           => 'Mandats',
    'stat_mandates_pending'   => ':count en attente',
    'stat_economy'            => 'Économie',
    'stat_economy_reports'    => ':count relevé|:count relevés',
    'stat_economy_provinces'  => ':count province|:count provinces',
    'stat_economy_latest'     => 'dern. :date',
    'stat_animation'          => 'Animation',
    'stat_animation_secrets'  => ':count coffre|:count coffres',
    'stat_animation_grids'    => ':count grille|:count grilles',
    'stat_security'           => 'Sécurité',
    'stat_security_empty'     => '—',

    // Alertes
    'alert_expiring_title'   => 'Mandats expirant dans 7 jours',
    'alert_expiring_line'    => ':pseudo (:type — :lieu), le :expires',
    'alert_inactive_title'   => 'Activités proches de la fermeture automatique',
    'alert_inactive_line'    => '« :name » (org. :organizer) — :days j restant',

    // Fil d'activité récente
    'recent_title'      => 'Activité récente',
    'recent_empty'      => 'Aucune activité enregistrée.',
    'recent_today'      => 'Aujourd\'hui',
    'recent_this_week'  => 'Cette semaine',
    'recent_this_month' => 'Ce mois-ci',
    'recent_older'      => 'Plus ancien',

    // Événements du fil
    'event_user_created'       => 'Nouveau compte : :email',
    'event_character_created'  => 'Personnage créé : :pseudo',
    'event_mandate_pending'    => 'Demande de mandat : :pseudo (:type — :lieu)',
    'event_mandate_approved'   => 'Mandat approuvé : :pseudo (:type — :lieu)',
    'event_mandate_expired'    => 'Mandat expiré : :pseudo (:type — :lieu)',
    'event_mine_report'        => 'Relevé mines : :province par :pseudo',
    'event_mine_price'         => 'Prix mines mis à jour : :province par :pseudo',
    'event_activity_created'   => 'Activité créée : « :name » par :organizer',
    'event_activity_launched'  => 'Activité lancée : « :name »',
    'event_activity_closed'    => 'Activité clôturée : « :name »',
    'event_secret_found'       => 'Coffre percé : « :name » par :character',
    'event_grid_created'       => 'Grille créée : « :name » par :organizer',
    'event_grid_drawn'         => 'Grille tirée : « :name »',
    'event_grid_closed'        => 'Grille clôturée : « :name »',
    'event_prize_awarded'      => 'Lot attribué : :prize → :winner (« :grid »)',

    // Divers
    'deleted_character' => 'personnage supprimé',
    'mandate_mayor'     => 'maire',
    'mandate_council'   => 'conseiller',

    // Sidebar
    'characters_nav' => 'Personnages',
];
