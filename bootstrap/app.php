<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
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
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 429 de l'API au format des autres erreurs (le front affiche `message` tel quel) et en
        // français. Le corps `success: false` distingue aussi ce 429 de celui qu'O2Switch
        // renvoie de son côté sur des appels rapprochés.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return response()->json([
                'success' => false,
                'message' => "Trop de tentatives. Réessayez dans {$seconds} secondes.",
            ], 429, $e->getHeaders());
        });
    })->create();
