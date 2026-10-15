<?php

// Registre des mines — messages de l'API (brief admin/content/brief-registre-mines.md ; fil
// admin/echanges/registre-mines). Envoyés en FR et EN par MandateApiErrors : le frontend affiche
// `messages[sa langue]`, il n'a aucune table code → texte.
return [
    'api' => [
        'mine_not_authorized' => "Seuls le commissaire aux mines et le bailli de la province peuvent enregistrer un relevé, et l'Office ne connaît pas ce poste pour ce personnage. Un poste se déclare depuis le Profil, puis il est validé.",
        'mine_report_identical' => 'Ce relevé est déjà inscrit à cette date dans la province de :province : rien à remplacer.',
        'mine_report_less' => 'Le relevé inscrit à cette date dans la province de :province en dit plus que celui-ci : il n\'est pas remplacé, pour que rien ne se perde.',
        'mine_not_reader' => "Seuls le commissaire aux mines, le bailli et le dirigeant de la province consultent son registre, et l'Office ne connaît pas un tel poste pour ce personnage.",
        'mine_report_future' => 'Ce relevé porte une date à venir : il ne peut pas être inscrit.',
        'price_not_authorized' => "Seuls le commissaire au commerce, le commissaire aux mines et le bailli de la province gèrent ses prix, et l'Office ne connaît pas ce poste pour ce personnage.",
        'price_not_reader' => "Seuls le commissaire au commerce, le commissaire aux mines, le bailli et le dirigeant de la province consultent ses prix, et l'Office ne connaît pas un tel poste pour ce personnage.",
        'price_locked' => 'Ce jeu de prix ne peut plus être modifié : il a été fixé avant votre poste, ou votre poste est terminé depuis plus de :days jours.',
        'price_not_found' => "Ce jeu de prix n'existe pas dans cette province.",
        'price_exists' => 'Un jeu de prix existe déjà au :date dans cette province : modifiez-le plutôt que d\'en créer un second.',
        'price_future' => 'Un jeu de prix ne peut pas porter une date à venir.',
        'price_outside_office' => "Cette date ne tombe pas dans une période où vous occupiez l'un des postes qui gèrent les prix de cette province.",
        'mine_report_needs_confirmation' => 'Un relevé existe déjà pour le :date dans la province de :province. Il sera remplacé, pas effacé : l\'ancien reste visible dans l\'historique.',
    ],
    'prices_digest' => [
        'subject' => 'Prix des mines de :province : :count changement(s)',
        'greeting' => 'Bonjour,',
        'intro' => 'Les prix des mines de :province ont changé depuis le dernier résumé :',
        'line_created' => '- Prix en vigueur le :date, ajoutés par :author.',
        'line_updated' => '- Prix en vigueur le :date, modifiés par :author.',
        'unknown_author' => 'un personnage dont le compte a été supprimé',
        'action' => 'Voir les prix',
        'outro' => 'Vous recevez ce résumé parce que vous occupez un poste qui gère ces prix. Les modifications que vous avez faites vous-même n\'y figurent pas.',
    ],
    'redate' => [
        'nothing' => 'Aucun relevé à redater : toutes les dates suivent déjà leurs données.',
        'id' => 'Relevé',
        'from' => 'Date actuelle',
        'to' => 'Nouvelle date',
        'state' => 'État',
        'active' => 'en vigueur',
        'replaced' => 'remplacé',
        'dry_run' => 'Simulation : :count relevé(s) changeraient. Relancer avec --force pour écrire.',
        'done' => ':count relevé(s) redaté(s).',
    ],
];
