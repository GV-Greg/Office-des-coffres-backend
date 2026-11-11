<?php

namespace App\Console\Commands;

use App\Support\AnimKinds;
use App\Support\MandateHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Ferme les activités d'Animation (Décode le code, Grille des lots : `AnimKinds`) restées 30 jours sans action (brief : « auto après 30
 * jours sans action »). Dernière action = `lastActionAt` du service de chaque activité (dernière proposition, dernier lot, lancement, tirage,
 * création ou réouverture) ; une activité jamais commencée se mesure depuis sa création. La fermeture d'une activité SANS COMPTE est la
 * clôture définitive de son service : photo figée, effacement des données personnelles (Décode envoie en plus un compte rendu à l'adresse
 * confirmée, D16, D30, D31 ; la grille n'envoie aucun email).
 *
 * Le nom de la commande, et le battement de cœur `decode`, ne changent pas : renommer effacerait les horodatages en prod.
 */
class CloseInactiveAnimActivities extends Command
{
    protected $signature = 'anim:close-inactive';

    protected $description = 'Ferme les activités d\'Animation (Décode le code, Grille des lots) sans action depuis 30 jours.';

    public function handle(): int
    {
        $total = 0;

        foreach (AnimKinds::all() as $kind) {
            $model = $kind['model'];
            $workflow = app($kind['workflow']);
            $limit = Carbon::now()->subDays($model::INACTIVITY_DAYS);
            $closed = 0;

            $model::query()
                ->where('status', '!=', $model::CLOSED)
                ->each(function ($activity) use ($workflow, $limit, &$closed) {
                    if ($workflow->lastActionAt($activity)->lessThan($limit)) {
                        $workflow->closeNow($activity);
                        $closed++;
                    }
                });

            $this->info("{$kind['label']} : {$closed} fermée(s).");
            $total += $closed;
        }

        MandateHeartbeat::record('decode');
        $this->info("{$total} activité(s) fermée(s).");

        return self::SUCCESS;
    }
}
