<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteAnnouncement;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Annonce du site en cours, lue par l'accueil de l'application (bandeau qui défile). Publique : le
 * bandeau s'affiche aussi pour qui n'a pas de compte. `announcement` vaut `null` s'il n'y en a aucune.
 */
class AnnouncementController extends Controller
{
    public function current(): JsonResponse
    {
        $now = Carbon::now();
        $announcement = SiteAnnouncement::current($now);

        return response()->json([
            'success' => true,
            'announcement' => $announcement === null ? null : [
                'id' => $announcement->id,
                // Les deux langues : le site choisit la sienne (même contrat que les mandats). `{when}` est
                // déjà remplacé par la date ou la période (« cette semaine »).
                'message' => [
                    'fr' => $announcement->renderMessage('fr', $now),
                    'en' => $announcement->renderMessage('en', $now),
                ],
                // L'échéance, pour qui voudrait l'afficher autrement : `exact`, `period` ou null. Dates réelles en UTC.
                'schedule' => [
                    'type' => $announcement->schedule_type,
                    'starts_at' => $announcement->maintenance_starts_at?->utc()->toIso8601ZuluString(),
                    'ends_at' => $announcement->maintenance_ends_at?->utc()->toIso8601ZuluString(),
                ],
                // Fin de visibilité effective : le site peut retirer le bandeau sans rappeler l'API.
                'visible_until' => $announcement->effectiveEnd()?->utc()->toIso8601ZuluString(),
            ],
        ])->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=60');
    }
}
