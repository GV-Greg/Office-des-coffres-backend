<?php

// Module Sécurité — messages de l'API des douaniers (brief admin/content/brief-module-securite.md ; fil admin/echanges/module-securite).
// Envoyés en FR et EN par MandateApiErrors : le frontend affiche `messages[sa langue]`, il n'a aucune table code → texte.
return [
    'api' => [
        'character_not_validated' => "Seul un personnage validé par l'Office peut demander à devenir douanier.",
        'already_active' => 'Ce personnage a déjà une demande en attente ou un accès de douanier en cours.',
        'not_pending' => "Cette demande n'est plus en attente.",
        'not_officer' => "Ce personnage n'est pas douanier.",
        'officer_not_found' => 'Demande de douanier introuvable.',
        'not_authorized' => "Seuls le prévôt des maréchaux et le connétable de la province valident les douaniers. Faute de l'un comme de l'autre, ce sont le capitaine, le dirigeant ou un maire de la province, et l'Office ne connaît pas un tel poste pour ce personnage.",
        'self_decision' => 'Vous ne pouvez pas valider votre propre demande.',
        'no_decider' => "Cette demande ne peut pas être traitée d'ici : aucun poste connu de l'Office ne vous y autorise.",
        'inactivity_range' => 'La durée d\'inactivité se règle entre :min et :max jours.',
    ],
    'admin' => [
        'title' => 'Douaniers',
        'intro' => "Dernier recours de la validation des douaniers (niveau 3) : le prévôt, le connétable puis le capitaine, le comte ou un maire de la province décident d'abord. Une demande ne doit arriver ici que sur demande expresse.",
        'pending_heading' => 'Demandes en attente',
        'approved_heading' => 'Douaniers en vigueur',
        'none_pending' => 'Aucune demande en attente.',
        'none_approved' => 'Aucun douanier en vigueur.',
        'approve' => 'Valider',
        'reject' => 'Refuser',
        'revoke' => 'Révoquer',
        'inactive' => 'inactif',
        'approved' => 'Douanier validé.',
        'rejected' => 'Demande refusée.',
        'revoked' => 'Accès retiré.',
    ],
    'email' => [
        'inactivity_subject' => 'Votre accès de douanier de :province prendra fin',
        'greeting' => 'Bonjour,',
        'inactivity_detail' => 'Vous n\'avez saisi aucun bilan de défense depuis un moment : votre accès de douanier pour la province de :province prendra fin le :date, faute de nouvelle saisie. Saisir un bilan remet le compteur à zéro. Vous pourrez ensuite refaire une demande.',
        'action' => 'Ouvrir l\'Office des Coffres',
        'salutation' => 'Cordialement, l\'Office des Coffres',
    ],
];
