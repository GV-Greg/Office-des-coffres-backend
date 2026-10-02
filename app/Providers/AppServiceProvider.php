<?php

namespace App\Providers;

use App\Support\ModuleDataEncrypter;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\KeyGenerateCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Chiffreur dédié aux données de module, alimenté par MODULE_DATA_KEY et jamais par
        // APP_KEY (admin/strategies/donnees-utilisateur.md §5). Résolu paresseusement : une
        // installation sans MODULE_DATA_KEY doit continuer de fonctionner tant qu'aucun module
        // chiffré n'est utilisé — aucune table de module n'existe encore.
        $this->app->singleton(ModuleDataEncrypter::class, static function ($app): ModuleDataEncrypter {
            return ModuleDataEncrypter::fromConfig($app->make('config')->get('module_data'));
        });
    }

    public function boot(): void
    {
        // Plancher de tout /api/* (branché par throttleApi() dans bootstrap/app.php). Déclaré
        // ici depuis l'origine sans jamais être appliqué jusqu'au 29/09/2026 : login se laissait
        // essayer sans borne. `user('api')` : le throttle passe avant auth:api, et la garde par
        // défaut (web, session) ne voit pas le jeton Passport.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user('api')?->id ?: $request->ip());
        });

        // Deux couches : (email, IP) serré contre l'essai de mots de passe sur un compte, IP
        // seule plus large contre le balayage d'un mot de passe sur beaucoup de comptes — une IP
        // de jeu est souvent partagée, d'où la marge. L'email soumis compte qu'il existe ou non :
        // un compteur réservé aux comptes existants ferait du 429 un oracle d'inscription. Pas
        // de verrouillage de compte : un tiers pourrait bloquer n'importe quel joueur.
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('login:'.$email.'|'.$request->ip()),
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
            ];
        });

        // Comptes créés en rafale et emails de vérification sortants.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by('register:'.$request->ip());
        });

        // Exige déjà un refresh token valide : limite généreuse, un onglet rafraîchit toutes
        // les heures.
        RateLimiter::for('refresh', function (Request $request) {
            return Limit::perMinute(20)->by('refresh:'.$request->ip());
        });

        Event::listen(
            Registered::class,
            SendEmailVerificationNotification::class,
        );

        // Migration Sanctum → Passport (admin/strategies/cookies.md item #13) : access token
        // court (1 h, voir docs/DECISIONS.md) dans tous les cas, refresh token long par défaut (30 j) — AuthController
        // raccourcit explicitement l'expiration du refresh token à 12h après coup si
        // "remember_me" n'est pas coché, Passport n'ayant pas de notion de durée conditionnelle
        // à l'émission.
        $this->protectKeyGenerateInProduction();

        Passport::enablePasswordGrant();
        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(30));
    }

    /**
     * Ceinture-bretelles de la clé dédiée (Données utilisateur #11).
     *
     * `key:generate` ne touche qu'`APP_KEY`, dont la rotation ne coûte qu'une reconnexion — les
     * données de module, elles, dépendent de MODULE_DATA_KEY et ne sont pas concernées. Reste
     * qu'en production la commande invalide sessions, cookies et jetons de réinitialisation de
     * tout le monde : elle n'a aucune raison d'être lancée là, et une faute de frappe dans un
     * shell de prod ne doit pas suffire.
     *
     * Laravel protège déjà via `ConfirmableTrait`, mais `--force` passe outre — ici, non. Ce
     * blocage se contourne (un accès direct au `.env`), c'est pourquoi il ne remplace pas la
     * vraie défense : la clé dédiée elle-même.
     */
    private function protectKeyGenerateInProduction(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        $this->app->extend('command.key.generate', function () {
            return new class extends KeyGenerateCommand
            {
                public function handle(): int
                {
                    $this->components->error(
                        'key:generate est désactivée en production sur ce projet. Faire tourner '
                        .'APP_KEY déconnecte tout le monde, et un --force sur MODULE_DATA_KEY '
                        .'rendrait les données de module illisibles. '
                        .'Voir admin/strategies/donnees-utilisateur.md §5.'
                    );

                    return self::FAILURE;
                }
            };
        });
    }
}
