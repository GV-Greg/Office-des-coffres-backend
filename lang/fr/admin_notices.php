<?php

/*
| Notifications d'administration (brief-notifications-admin, fil notifications-admin, 17/11/2026).
| Français seulement : l'interface d'administration n'a pas de bascule de langue. Courts et
| factuels ; jamais d'adresse email de joueur.
*/

return [
    'when' => 'Le :date à :time (heure de Paris).',

    'account' => [
        'subject' => 'Nouveau compte confirmé',
        'intro' => 'Un joueur vient de confirmer son adresse email.',
        'line' => 'Compte n° :id — :total comptes confirmés au total.',
        'action' => 'Voir les utilisateurs',
    ],

    'character' => [
        'subject' => 'Nouveau personnage créé',
        'intro' => 'Nouveau personnage : :pseudo. Il attend sa validation.',
        'residence' => 'Résidence : :city (:province, :kingdom).',
        'account' => 'Compte n° :id.',
        'action' => 'Valider les personnages',
    ],

    'mandate' => [
        'subject' => 'Nouvelle demande de poste',
        'intro' => ':pseudo demande : :post.',
        'office' => 'Titre demandé : :office.',
        'started' => 'Début déclaré : :date.',
        'action' => 'Ouvrir les demandes en attente',
    ],

    'renewal' => [
        'subject' => 'Nouvelle demande de renouvellement',
        'intro' => ':pseudo demande le renouvellement de : :post.',
    ],
];
