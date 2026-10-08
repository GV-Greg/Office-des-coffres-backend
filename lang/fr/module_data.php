<?php

// php artisan module-data:check — contrôle de la clé des données de module (console, en français
// seulement). Ne jamais afficher une clé : seulement sa forme et ce qu'elle permet de lire.
return [
    'check' => [
        'invalid' => 'Clé invalide — :message',
        'key_ok' => 'MODULE_DATA_KEY : bien formée (:cipher, 32 octets).',
        'previous' => 'Clés antérieures (MODULE_DATA_PREVIOUS_KEYS) : :count, toutes bien formées.',
        'roundtrip_ok' => 'Aller-retour de chiffrement : réussi.',
        'roundtrip_ko' => 'Aller-retour de chiffrement : ÉCHEC — la clé ne relit pas ce qu\'elle vient de chiffrer.',
        'data_ok' => 'Relevés du Registre des mines : :count, tous lisibles avec les clés actuelles.',
        'data_ko' => ':failed relevé(s) sur :count illisible(s) avec les clés actuelles : une clé manque dans MODULE_DATA_KEY ou MODULE_DATA_PREVIOUS_KEYS. Ne rien réécrire avant de l\'avoir retrouvée.',
        'done' => 'Tout est en ordre.',
    ],
];
