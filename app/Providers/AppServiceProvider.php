<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        Event::listen(
            Registered::class,
            SendEmailVerificationNotification::class,
        );

        // Migration Sanctum → Passport (admin/strategies/cookies.md item #13) : access token
        // court (15 min) dans tous les cas, refresh token long par défaut (30 j) — AuthController
        // raccourcit explicitement l'expiration du refresh token à 12h après coup si
        // "remember_me" n'est pas coché, Passport n'ayant pas de notion de durée conditionnelle
        // à l'émission.
        Passport::enablePasswordGrant();
        Passport::tokensExpireIn(now()->addMinutes(15));
        Passport::refreshTokensExpireIn(now()->addDays(30));
    }
}
