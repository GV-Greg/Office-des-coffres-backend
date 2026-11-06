<?php

return [
    'title' => 'Tableau de bord',

    // Cartes compteurs
    'card_users'                 => 'Comptes',
    'card_users_unverified'      => ':count non vérifié|:count non vérifiés',
    'card_characters'            => 'Personnages',
    'card_characters_pending'    => ':count à valider',
    'card_mandates'              => 'Mandats actifs',
    'card_mandates_pending'      => ':count en attente',
    'card_mine_reports'          => 'Relevés mines',
    'card_mine_reports_latest'   => 'dernier le :date',
    'card_mine_reports_none'     => 'aucun relevé actif',
    'card_secrets'               => 'Coffres scellés',
    'card_secrets_found'         => ':count ouvert cette semaine|:count ouverts cette semaine',
    'card_prices'                => 'Provinces avec prix',

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

    // Divers
    'deleted_character' => 'personnage supprimé',
    'mandate_mayor'     => 'maire',
    'mandate_council'   => 'conseiller',

    // Sidebar
    'characters_nav' => 'Personnages',
];
