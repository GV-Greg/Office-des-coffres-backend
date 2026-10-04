<?php

// Purge des comptes (/legal/privacy §5). `email` part chez le joueur : ses clés existent aussi dans
// lang/en/accounts.php (AccountPurgeTest). `command` et `dashboard` : français seulement.
return [
    'email' => [
        'greeting' => 'Bonjour,',
        'salutation' => "Ludiquement,\nOffice des coffres",

        'inactive_subject' => 'Office des coffres — votre compte sera supprimé faute de connexion',
        'inactive_notice' => "Votre compte de l'Office des coffres n'a pas été utilisé depuis le :last_seen. Comme l'annonce la politique de confidentialité, un compte inactif est supprimé après un préavis : sans connexion de votre part, le vôtre le sera à partir du :date, avec ses personnages.",
        'inactive_keep' => 'Pour le garder, il suffit de vous connecter avant le :date.',
        'history_kept' => "L'historique public des postes tenus par vos personnages est conservé, comme l'indique la politique de confidentialité.",
        'inactive_action' => 'Me connecter',

        'unverified_subject' => 'Office des coffres — confirmez votre adresse, ou le compte sera supprimé',
        'unverified_notice' => "Un compte de l'Office des coffres a été créé avec cette adresse le :created, mais l'adresse n'a jamais été confirmée. Sans confirmation, le compte sera supprimé à partir du :date.",
        'unverified_not_you' => "Si vous n'êtes pas à l'origine de cette inscription, ignorez cet email : le compte disparaîtra de lui-même.",
        'unverified_action' => 'Confirmer mon email',
    ],

    'command' => [
        'not_enforced' => "accounts.enforce = false : simulation imposée tant que le nouveau texte de la politique n'est pas en ligne. Rien n'est envoyé, rien n'est supprimé.",
        'dry_run' => "Simulation (--dry-run) : rien n'est envoyé, rien n'est supprimé.",
        'columns' => ['Action', 'Compte', 'Email', 'Détail'],
        'actions' => [
            'remind' => 'rappel (non confirmé)',
            'notify' => 'préavis (inactivité)',
            'delete' => 'suppression',
            'blocked' => 'BLOQUÉ : poste en cours',
            'failed' => "ÉCHEC d'envoi",
        ],
        'detail_deletion' => 'suppression à partir du :date',
        'misconfigured' => ':key absente ou invalide — config en cache périmée ? Lancer php artisan config:cache en SSH. Rien n\'a été fait.',
        'summary' => ':remind rappel(s), :notify préavis, :delete suppression(s), :blocked bloqué(s), :failed échec(s).',
    ],

    'dashboard' => [
        'blocked_title' => 'Purge des comptes : suppression suspendue',
        'blocked_line' => 'Compte n° :id (:email) — personnage(s) en poste : :characters.',
        'blocked_help' => 'Inactif et prévenu, mais un de ses personnages tient un mandat en cours : la purge ne décide pas seule. Vérifier le mandat, puis laisser la purge repasser ou supprimer le compte depuis « Utilisateurs ».',
    ],
];
