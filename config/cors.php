<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // '*' par défaut (dev) ; en prod, CORS_ALLOWED_ORIGINS restreint aux domaines réellement
    // servis (voir .env.example). Item #14 de admin/strategies/cookies.md.
    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', '*'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Durée (s) pendant laquelle le navigateur réutilise un préflight. À 0, chaque appel d'API
    // du front (autre origine) payait un OPTIONS de plus : ~400 ms par aller-retour sur
    // l'hébergement mutualisé (mesure du 28/09/2026). Chromium plafonne à 7 200 s, Firefox à
    // 86 400. Gardé par tests/Feature/Api/CorsTest.php.
    'max_age' => 86400,

    'supports_credentials' => false,

];
