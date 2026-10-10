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
        'mine_report_needs_confirmation' => 'Un relevé existe déjà pour le :date dans la province de :province. Il sera remplacé, pas effacé : l\'ancien reste visible dans l\'historique.',
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
