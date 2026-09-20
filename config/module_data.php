<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clé de chiffrement des données de module
    |--------------------------------------------------------------------------
    |
    | Volontairement distincte d'`APP_KEY`. `php artisan key:generate` fait tourner `APP_KEY`
    | en routine : la rotation ne coûte alors qu'une reconnexion (sessions, cookies, jetons de
    | réinitialisation). La même rotation sur des données de module les rendrait définitivement
    | illisibles — d'où une clé qu'aucune commande du framework ne touche.
    |
    | Voir admin/strategies/donnees-utilisateur.md §5.
    |
    */

    'key' => env('MODULE_DATA_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Clés antérieures
    |--------------------------------------------------------------------------
    |
    | Équivalent d'`APP_PREVIOUS_KEYS` pour les modules : les clés listées ici sont essayées au
    | déchiffrement, jamais au chiffrement. C'est le filet qui transforme une rotation
    | accidentelle en incident réparable plutôt qu'en perte définitive. Plusieurs clés se
    | séparent par une virgule.
    |
    */

    'previous_keys' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MODULE_DATA_PREVIOUS_KEYS', ''))
    ))),

    'cipher' => 'AES-256-CBC',

];
