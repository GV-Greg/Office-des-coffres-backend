<?php

// Livre des naissances : refus que le joueur peut recevoir (français et anglais, mêmes clés).
return [
    'api' => [
        'not_reader' => 'Le Livre des naissances de la province est réservé au bailli et au dirigeant.',
        'not_recorder' => 'Seul le bailli en poste inscrit ou retire une naissance.',
        'not_configurer' => 'Seuls le bailli et le dirigeant règlent les espèces de la province.',
        'not_configured' => "Aucune espèce n'est réglée pour la province : réglez-les d'abord dans Mon bureau.",
        'species_not_raised' => "Cette espèce n'est pas élevée dans la province.",
        'bad_feed' => 'Cette nourriture ne convient pas à cette espèce.',
        'birth_outside_term' => "Une naissance s'inscrit pour un jour de votre mandat, jusqu'à aujourd'hui.",
        'withdraw_closed' => "Une naissance se retire le jour du jeu où vous l'avez inscrite, et seulement la vôtre.",
        'price_not_authorized' => 'Seul le bailli fixe le prix de la nourriture du cheptel.',
        'price_locked' => 'Ce prix ne peut plus être modifié : le terme de votre mandat est passé, ou il date d\'avant votre poste.',
        'price_future' => 'Un prix ne peut pas prendre effet dans le futur.',
        'price_outside_office' => 'Cette date est hors de votre mandat.',
        'price_exists' => 'Un jeu de prix existe déjà au :date.',
    ],
];
