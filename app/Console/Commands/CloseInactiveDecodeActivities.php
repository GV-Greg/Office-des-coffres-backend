<?php

namespace App\Console\Commands;

use App\Models\AnimDecodeActivity;
use App\Services\DecodeWorkflow;
use App\Support\MandateHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Ferme les activités de « Décode le code » restées 30 jours sans action (brief : « auto après 30 jours sans action »).
 * Dernière action = DecodeWorkflow::lastActionAt (dernière proposition, lancement, création ou réouverture) ; une activité
 * `new` jamais lancée se mesure depuis sa création. La fermeture d'une activité SANS COMPTE est la clôture définitive de
 * DecodeClosing : photo figée, effacement des données personnelles, compte rendu à l'adresse confirmée (D16, D30, D31).
 */
class CloseInactiveDecodeActivities extends Command
{
    protected $signature = 'anim:close-inactive';

    protected $description = 'Ferme les activités de « Décode le code » sans action depuis 30 jours.';

    public function handle(DecodeWorkflow $workflow): int
    {
        $limit = Carbon::now()->subDays(AnimDecodeActivity::INACTIVITY_DAYS);
        $closed = 0;

        AnimDecodeActivity::query()
            ->where('status', '!=', AnimDecodeActivity::CLOSED)
            ->each(function (AnimDecodeActivity $activity) use ($workflow, $limit, &$closed) {
                if ($workflow->lastActionAt($activity)->lessThan($limit)) {
                    $workflow->closeNow($activity);
                    $closed++;
                }
            });

        MandateHeartbeat::record('decode');
        $this->info("{$closed} activité(s) fermée(s).");

        return self::SUCCESS;
    }
}
