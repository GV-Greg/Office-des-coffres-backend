<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\LastSeen;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trace le passage de l'utilisateur authentifié, APRÈS l'envoi de la réponse (terminate) : jamais
 * dans le chemin critique d'une requête (fil politique-promesses, Q7). Si l'hébergeur ne libère
 * pas la réponse avant terminate(), le coût retombe à une écriture indexée, une fois par jour et
 * par compte.
 *
 * Branché sur les groupes `api` et `web` (bootstrap/app.php). Le garde actif est celui qu'a choisi
 * le middleware d'authentification de la route (auth:api, auth) ; hasUser() ne résout rien : une
 * route publique ne coûte aucune requête.
 */
class RecordLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = LastSeen::markedFor($request);

        if (! $user && Auth::guard()->hasUser()) {
            $user = Auth::guard()->user();
        }

        if ($user instanceof User) {
            LastSeen::record($user);
        }
    }
}
