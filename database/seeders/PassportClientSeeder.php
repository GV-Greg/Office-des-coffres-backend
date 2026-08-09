<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Passport\Client;

class PassportClientSeeder extends Seeder
{
    /**
     * Crée (ou remet à jour) le client OAuth utilisé par AuthController pour émettre les
     * tokens de connexion — un seul client cumule les grant types 'password'/'refresh_token'
     * (login/refresh, cf. #13) et 'personal_access' (User::createToken(), utilisé pour le
     * jeton du lien de vérification d'email et par les tests) : ClientRepository cherche
     * n'importe quel client portant ce dernier grant type, pas la peine d'en créer un second.
     *
     * ID et secret fixés via .env plutôt que générés aléatoirement : le client
     * doit survivre à un migrate:fresh (prod, cf. runbook de déploiement) sans
     * qu'il faille aller rechercher un nouveau secret à chaque fois. Le secret
     * est haché par le mutator du modèle Client à l'écriture — seule sa valeur
     * en clair (celle d'.env) doit être connue pour parler à /oauth/token.
     */
    public function run(): void
    {
        Client::updateOrCreate(
            ['id' => config('services.passport.password_client_id')],
            [
                'name' => 'Office des Coffres — Password Grant',
                'secret' => config('services.passport.password_client_secret'),
                'provider' => 'users',
                'redirect_uris' => [],
                'grant_types' => ['password', 'refresh_token', 'personal_access'],
                'revoked' => false,
            ]
        );
    }
}
