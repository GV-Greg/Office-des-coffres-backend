<?php

// Module Animation — Les Coffres Scellés (brief admin/modules/animation/brief-coffres-scelles.md ;
// fil admin/echanges/animation-coffres-scelles). Messages de l'API envoyés en FR et EN par
// MandateApiErrors : le frontend affiche `messages[sa langue]`, il n'a aucune table code → texte.
return [
    'api' => [
        'not_owner' => "Seul l'organisateur de cette activité peut la gérer.",
        'activity_not_found' => "Cette activité n'existe pas.",
        'character_not_found' => "Ce personnage n'existe pas sur ce compte.",
        'type_inactive' => "Ce type d'activité n'est pas ouvert pour le moment.",
        'place_exclusive' => 'Choisissez une province OU une ville, pas les deux.',
        'activity_already_active' => 'Vous avez déjà une activité de ce type en cours : clôturez-la avant d\'en créer une autre.',
        'reopen_blocked' => 'Vous avez une autre activité de ce type en cours : clôturez-la avant de rouvrir celle-ci.',
        'activity_closed' => 'Cette activité est close.',
        'activity_not_closed' => "Cette activité n'est pas close.",
        'delete_requires_closed' => 'Une activité ne se supprime qu\'une fois close : clôturez-la d\'abord.',
        'secret_pending' => 'Un coffre est déjà scellé et attend d\'être percé.',
        'secret_not_open' => "Ce coffre n'est plus à percer, ou n'appartient pas à cette activité.",
        'combination_invalid' => 'Une combinaison compte exactement cinq chiffres.',
    ],

    'status' => [
        'new' => 'Nouveau',
        'launched' => 'En cours',
        'relaunch' => 'Relancé',
        'closed' => 'Clos',
    ],

    'types' => [
        'decode' => [
            'name' => 'Les Coffres Scellés',
            'public_link_label' => 'Coffre n°:number à percer',
            // ⚠️ BROUILLON de Claude Code (27/10/2026) : Greg a différé la rédaction définitive (tour 03,
            // Q4) ; à retravailler par Cowork avant la mise en ligne.
            'rules' => "Un coffre est scellé par une combinaison secrète de cinq chiffres. Envoyez vos propositions à l'organisateur ; chacune reçoit une note : 2 points par chiffre bien placé, 1 point par chiffre juste mais mal placé. Le premier à trouver la combinaison entière ouvre le coffre, son nom est inscrit au registre, et un nouveau coffre est aussitôt scellé.",
        ],
    ],

    // Administration Blade (français seul, comme les autres pages d'administration).
    'admin' => [
        'title' => 'Animation',
        'nav' => 'Animation',
        'types_title' => "Types d'activité",
        'types_help' => "Un type désactivé n'apparaît plus dans le module des joueurs ; les activités déjà créées restent lisibles.",
        'recent_title' => 'Activités récentes',
        'recent_help' => "Surveillance seulement : vérifiez sur le forum la légitimité d'une activité. Les activités appartiennent à leur organisateur, elles ne se gèrent pas d'ici.",
        'recent_empty' => 'Aucune activité pour le moment.',
        'type' => 'Type',
        'key' => 'Clé',
        'state' => 'État',
        'active' => 'Actif',
        'inactive' => 'Inactif',
        'activate' => 'Activer',
        'deactivate' => 'Désactiver',
        'toggled' => 'Type « :type » : :state.',
        'activity' => 'Activité',
        'organizer' => 'Organisateur',
        'organizer_deleted' => 'compte supprimé',
        'place' => 'Lieu',
        'created' => 'Créée le',
    ],

    'bbcode' => [
        'organizer' => 'Organisateur : :name',
        'place' => 'Lieu : :name',
        'attempts_title' => '[b]Coffre n°:number — tentatives[/b]',
        'attempt_line' => '[*] :combination — :player : :points pt(s)',
        'no_attempt' => 'Aucune tentative pour le moment.',
        'no_open_chest' => 'Aucun coffre à percer pour le moment.',
        'found' => '[b]Le coffre n°:number est ouvert ![/b] La combinaison était :code, trouvée par :player.',
        'opened_title' => '[b]Coffres ouverts[/b]',
        'opened_line' => 'Coffre n°:number : :code — :player',
        'ranking_title' => '[b]Classement cumulé[/b]',
        'ranking_line' => ':rank. :player — :points pt(s)',
        'full_results' => 'Résultats complets',
    ],
];
