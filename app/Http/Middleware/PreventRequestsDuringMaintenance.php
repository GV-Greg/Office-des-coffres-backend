<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;

/**
 * Mode maintenance (brief admin/content/brief-mode-maintenance.md, 18/11/2026).
 *
 * Branché dans bootstrap/app.php à la place du middleware de Laravel (`replace`).
 *
 * Seul ajout : un **second laissez-passer, par en-tête**. Le bypass natif est un cookie posé sur
 * le domaine de l'API (`/{secret}`), que le site des joueurs — autre origine, jetons Bearer, aucun
 * cookie envoyé par axios — ne peut pas présenter. Le SPA envoie donc le même secret dans
 * `X-Maintenance-Bypass`, ce qui permet à l'administrateur d'essayer la version qui vient d'être
 * déployée AVANT de rouvrir l'Office. Le cookie natif reste valable (panneau Blade).
 */
class PreventRequestsDuringMaintenance extends Middleware
{
    public const BYPASS_HEADER = 'X-Maintenance-Bypass';

    /**
     * The URIs that should be reachable while maintenance mode is enabled.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
    ];

    protected function hasValidBypassCookie($request, array $data)
    {
        return $this->hasValidBypassHeader($request, $data)
            || parent::hasValidBypassCookie($request, $data);
    }

    private function hasValidBypassHeader($request, array $data): bool
    {
        $secret = $data['secret'] ?? null;
        $given = $request->header(self::BYPASS_HEADER);

        return is_string($secret) && $secret !== ''
            && is_string($given) && $given !== ''
            && hash_equals($secret, $given);
    }
}
