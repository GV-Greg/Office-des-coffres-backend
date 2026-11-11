<?php

namespace App\Console\Commands;

use App\Support\AnimKinds;
use App\Support\MandateHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Supprime la page de résumé d'une activité d'Animation SANS COMPTE (Décode le code, Grille des lots : `AnimKinds`) close depuis 5 ans (D7) ;
 * son slug redevient réutilisable (D9). Ne touche JAMAIS une activité privée : elle ne disparaît que par la main de son organisateur ou, pour
 * Décode, avec son personnage (D20, D21) — une grille privée dont l'organisateur est supprimé reste (catégorie C).
 *
 * La durée est une constante de la classe du modèle (RETENTION_YEARS), pas un `config/*.php` : un nouveau fichier de config
 * est absent du cache en prod (incident du 04/10/2026). Elle est une PROMESSE aux joueurs — la changer, c'est d'abord
 * changer le texte de la politique. `--dry-run` liste sans supprimer.
 */
class PurgeClosedAnimActivities extends Command
{
    protected $signature = 'anim:purge-closed {--dry-run : Liste les pages concernées sans les supprimer}';

    protected $description = 'Supprime les résumés des activités d\'Animation sans compte (Décode le code, Grille des lots) closes depuis 5 ans.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $total = 0;

        foreach (AnimKinds::all() as $kind) {
            $model = $kind['model'];
            $query = $model::query()
                ->where('is_anonymous', true)
                ->where('status', $model::CLOSED)
                ->where('closed_at', '<', Carbon::now()->subYears($model::RETENTION_YEARS));

            $count = (clone $query)->count();
            $total += $count;

            if ($dry) {
                $this->info("{$kind['label']} — simulation : {$count} page(s) de résumé seraient supprimées.");

                continue;
            }

            $query->each(fn ($activity) => $activity->delete());
            $this->info("{$kind['label']} : {$count} page(s) de résumé supprimée(s).");
        }

        if (! $dry) {
            MandateHeartbeat::record('decode_purge');
        }
        $this->info(($dry ? 'Simulation : ' : '')."{$total} page(s) de résumé".($dry ? ' seraient supprimées.' : ' supprimée(s).'));

        return self::SUCCESS;
    }
}
