<?php

// Un préflight non mis en cache ajoute un aller-retour complet à chaque appel d'API du front
// (mesure prod du 28/09/2026 : ~400 ms chacun). La valeur vit dans config/cors.php, elle se perd
// sans bruit au premier config:cache mal fait — d'où ce test.

test('le préflight CORS d\'une route d\'API est mis en cache 24 h par le navigateur', function () {
    config(['cors.allowed_origins' => ['https://officedescoffres.creacube.be']]);

    $response = $this->call('OPTIONS', '/api/v1/auth/me', server: [
        'HTTP_ORIGIN' => 'https://officedescoffres.creacube.be',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
    ]);

    $response->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://officedescoffres.creacube.be')
        ->assertHeader('Access-Control-Max-Age', '86400');
});
