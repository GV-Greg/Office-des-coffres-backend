<?php

use App\Http\Middleware\RecordLastSeen;
use App\Support\MandateApiErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use League\OAuth2\Server\Exception\OAuthServerException;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);

        // Limiteur 'api' (AppServiceProvider) : défini sans ce branchement, il ne gardait rien.
        $middleware->throttleApi();

        // Dernier passage authentifié (App\Support\LastSeen), écrit après la réponse : mesure de
        // l'inactivité promise par /legal/privacy §5 (fil admin/echanges/politique-promesses).
        $middleware->appendToGroup('api', RecordLastSeen::class);
        $middleware->appendToGroup('web', RecordLastSeen::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // API des mandats : toute erreur de validation (refus métier ou forme) porte codes et
        // messages FR/EN (fil mandats-lot2, Q9-Q10). L'administration Blade n'est pas concernée.
        $exceptions->render(function (ValidationException $e, Request $request) {
            return MandateApiErrors::covers($request) ? MandateApiErrors::validation($e) : null;
        });

        // Le TokenGuard de Passport signale tout jeton Bearer refusé (expiré, révoqué, illisible) :
        // une erreur de ~90 lignes dans laravel.log pour un 401 ordinaire, à chaque expiration
        // d'un jeton de joueur. Seul ce refus (`access_denied`) est tu, les autres erreurs OAuth
        // restent signalées.
        $exceptions->dontReportWhen(fn (Throwable $e) => $e instanceof OAuthServerException
            && $e->getErrorType() === 'access_denied');

        // 429 de l'API au format des autres erreurs (le front affiche `message` tel quel) et en
        // français. Le corps `success: false` distingue aussi ce 429 de celui qu'O2Switch
        // renvoie de son côté sur des appels rapprochés.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            // API des mandats : même message, plus son code et sa version anglaise (MandateApiErrors).
            // ⚠️ En prod, un 429 venu de PHP n'arrive JAMAIS au client (O2Switch retient la
            // connexion, mesuré le 29/09/2026) : ce formatage n'y répare rien tant que l'hébergeur
            // ne le laisse pas passer. Le reste de l'API garde son message français : item à part.
            if (MandateApiErrors::covers($request)) {
                return MandateApiErrors::error(429, 'mandates.api.throttled', ['seconds' => $seconds], $e->getHeaders());
            }

            return response()->json([
                'success' => false,
                'message' => "Trop de tentatives. Réessayez dans {$seconds} secondes.",
            ], 429, $e->getHeaders());
        });
    })->create();
