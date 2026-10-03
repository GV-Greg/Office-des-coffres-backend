<?php

namespace App\Console\Commands;

use App\Models\CouncilMandate;
use App\Models\Mandate;
use App\Models\MayorMandate;
use App\Support\MandateHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Purge des refus de plus de 3 mois (point C du brief). Comptés depuis processed_at, que la
 * correction d'un motif ne change pas (tour 17 du lot 1). Ne touche JAMAIS une demande en attente :
 * ce n'est pas une donnée qui expire, c'est une tâche de l'administrateur (fil lot 3, Q4). Les
 * déclarations de poste refusées ne laissent pas de ligne, seulement une trace dans la note du
 * mandat : rien à purger.
 */
class PurgeRejectedMandates extends Command
{
    protected $signature = 'mandates:purge-rejected';

    protected $description = 'Supprime les demandes de mandat refusées depuis plus de 3 mois.';

    public function handle(): int
    {
        $limit = Carbon::now()->subMonths(config('mandates.rejected_retention_months'));
        $deleted = 0;

        foreach ([MayorMandate::class, CouncilMandate::class] as $model) {
            $deleted += $model::query()
                ->where('status', Mandate::REJECTED)
                ->where('processed_at', '<', $limit)
                ->delete();
        }

        MandateHeartbeat::record('purge');
        $this->info("{$deleted} refus supprimé(s).");

        return self::SUCCESS;
    }
}
