<?php

use Illuminate\Support\Facades\Exceptions;
use League\OAuth2\Server\Exception\OAuthServerException;

// Le TokenGuard de Passport fait `report($e)` sur tout jeton refusé : chaque jeton expiré d'un
// joueur et chaque passage de la sonde de latence écrivaient une erreur de ~90 lignes dans
// laravel.log. Un jeton refusé est un 401 ordinaire, pas une erreur.

test('un jeton Bearer refusé n\'est pas consigné comme une erreur', function () {
    Exceptions::fake();

    $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer sonde'])
        ->assertUnauthorized();

    Exceptions::assertNotReported(OAuthServerException::class);
});

test('les autres erreurs OAuth restent consignées', function () {
    Exceptions::fake();

    report(OAuthServerException::serverError('panne simulée'));

    Exceptions::assertReported(OAuthServerException::class);
});
