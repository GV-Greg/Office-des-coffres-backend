<?php

namespace App\Console\Commands;

use App\Models\AnimDecodeActivity;
use App\Support\MandateHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Supprime la page de résumé d'une activité SANS COMPTE close depuis 5 ans (D7) ; son slug redevient réutilisable (D9).
 * Ne touche JAMAIS une activité privée : elle ne disparaît que par la main de son organisateur ou avec son personnage (D20, D21).
 *
 * La durée est une constante de la classe du modèle (RETENTION_YEARS), pas un `config/*.php` : un nouveau fichier de config
 * est absent du cache en prod (incident du 04/10/2026). Elle est une PROMESSE aux joueurs — la changer, c'est d'abord
 * changer le texte de la politique. `--dry-run` liste sans supprimer.
 */
class PurgeClosedDecodeActivities extends Command
{
    protected $signature = 'anim:purge-closed {--dry-run : Liste les pages concernées sans les supprimer}';

    protected $description = 'Supprime les résumés des activités de « Décode le code » sans compte, closes depuis 5 ans.';

    public function handle(): int
    {
        $limit = Carbon::now()->subYears(AnimDecodeActivity::RETENTION_YEARS);

        $query = AnimDecodeActivity::query()
            ->where('is_anonymous', true)
            ->where('status', AnimDecodeActivity::CLOSED)
            ->where('closed_at', '<', $limit);

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("Simulation : {$count} page(s) de résumé seraient supprimées.");

            return self::SUCCESS;
        }

        $query->each(fn (AnimDecodeActivity $activity) => $activity->delete());

        MandateHeartbeat::record('decode_purge');
        $this->info("{$count} page(s) de résumé supprimée(s).");

        return self::SUCCESS;
    }
}
