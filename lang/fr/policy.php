<?php

// Politique de confidentialité — notification des modifications substantielles (/legal/privacy §10).
// `email` part chez le joueur : ses clés existent aussi dans lang/en/policy.php (PolicyNotifyTest).
// `command` est la sortie de `policy:notify`, en français seulement.
return [
    'email' => [
        'subject' => 'Office des coffres — modification de la politique de confidentialité',
        'greeting' => 'Bonjour,',
        'intro' => "La politique de confidentialité de l'Office des coffres a été modifiée le :date. Ce qui change :",
        'why' => "Vous recevez cet email parce qu'un compte de l'Office est enregistré à cette adresse. C'est une information légale, pas une lettre d'information : chaque modification substantielle de la politique est notifiée à tous les comptes.",
        'action' => 'Lire la politique',
        'english_below' => 'English version below.',
        'salutation' => "Cordialement,\nOffice des coffres",
    ],

    'command' => [
        'unknown' => 'Entrée « :id » absente de resources/policy/changelog.php.',
        'malformed' => "Entrée « :id » mal formée (champs : :fields) : rien n'est envoyé.",
        'not_substantial' => 'Entrée « :id » marquée non substantielle : rien à notifier.',
        'already_sent' => 'Entrée « :id » déjà envoyée le :date : elle ne part jamais deux fois.',
        'no_recipient' => "Aucun compte à l'email vérifié : rien n'est envoyé.",
        'summary' => 'Entrée :id (:date) : :summary',
        'recipients' => 'Destinataires (comptes à l\'email vérifié) : :count',
        'confirm' => "Envoyer maintenant ? L'envoi est irréversible.",
        'cancelled' => "Annulé : rien n'est envoyé.",
        'partial' => 'Envoi partiel : :sent envoyé(s), :failures échec(s) — détail dans laravel.log. L\'entrée est marquée envoyée.',
        'done' => ':count email(s) envoyé(s).',
    ],
];
