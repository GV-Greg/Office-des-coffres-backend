<?php

// Registre des mines — messages de l'API (brief admin/content/brief-registre-mines.md ; fil
// admin/echanges/registre-mines). Envoyés en FR et EN par MandateApiErrors : le frontend affiche
// `messages[sa langue]`, il n'a aucune table code → texte.
return [
    'api' => [
        'mine_not_authorized' => "Seuls le commissaire aux mines et le bailli de la province peuvent enregistrer un relevé, et l'Office ne connaît pas ce poste pour ce personnage. Un poste se déclare depuis le Profil, puis il est validé.",
        'mine_report_identical' => 'Ce relevé est déjà enregistré pour aujourd\'hui dans la province de :province : rien à remplacer.',
        'mine_report_less' => 'Le relevé enregistré aujourd\'hui dans la province de :province en dit plus que celui-ci : il n\'est pas remplacé, pour que rien ne se perde.',
        'mine_report_needs_confirmation' => 'Un relevé existe déjà pour le :date dans la province de :province. Il sera remplacé, pas effacé : l\'ancien reste visible dans l\'historique.',
    ],
];
