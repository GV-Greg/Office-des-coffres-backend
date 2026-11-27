<?php

namespace App\Console\Commands;

use App\Models\CouncilMandate;
use App\Models\Mandate;
use App\Models\MayorMandate;
use App\Notifications\MandateReminder;
use App\Support\MandateHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Rappel au joueur 2 jours APRÈS la fin EFFECTIVE de son mandat, tant qu'il est renouvelable
 * (décision de Greg, 03/10/2026 : avant la fin, l'élection est en cours et il ne sait pas s'il est
 * réélu). Aucun effet sur l'autorité : l'expiration à holds_until reste sèche et ne dépend pas de
 * cette tâche — si le cron tombe, seul le rappel manque (et le tableau de bord le dit).
 */
class SendMandateReminders extends Command
{
    protected $signature = 'mandates:send-reminders';

    protected $description = 'Envoie le rappel de renouvellement 2 jours après la fin effective d\'un mandat.';

    /**
     * Un maire dont la mairie a déjà un successeur VALIDÉ (un autre personnage, entré en fonction
     * après lui) n'a pas été réélu : « Réélu ? Renouvelez-le » serait faux (Greg, 03/10/2026).
     * Une demande concurrente encore en attente ne compte pas : Greg ne l'a pas vérifiée. Un conseil
     * n'est pas concerné : 12 sièges, un nouveau conseiller ne dit rien du sortant.
     */
    private function hasAcceptedSuccessor(Mandate $mandate): bool
    {
        if (! $mandate instanceof MayorMandate) {
            return false;
        }

        return MayorMandate::query()
            ->where('city_id', $mandate->city_id)
            ->where('character_id', '!=', $mandate->character_id)
            ->whereIn('status', [Mandate::APPROVED, Mandate::REVOKED])
            ->where('in_office_from', '>', $mandate->in_office_from)
            ->exists();
    }

    public function handle(): int
    {
        $now = Carbon::now();
        // Terminé depuis au moins 2 jours, et encore renouvelable (fin il y a 15 jours au plus).
        $endedBefore = $now->copy()->subDays(config('mandates.reminder_after_days'));
        $stillRenewable = $now->copy()->subDays(config('mandates.renewal_window_days'));
        $sent = 0;

        foreach ([MayorMandate::class, CouncilMandate::class] as $model) {
            $mandates = $model::query()
                ->where('status', Mandate::APPROVED)          // jamais un révoqué
                ->whereNull('reminder_sent_at')               // jamais deux fois
                ->where('holds_until', '<=', $endedBefore)
                ->where('holds_until', '>=', $stillRenewable)
                // Aucun rappel dès qu'un renouvellement existe, en attente OU validé (fil lot 3, Q1) :
                // « renouvelez-le » à quelqu'un qui l'a fait serait faux.
                ->whereNotExists(fn ($query) => $query->selectRaw('1')
                    ->from((new $model)->getTable().' as renewal')
                    ->whereColumn('renewal.renews_id', (new $model)->getTable().'.id')
                    ->whereIn('renewal.status', [Mandate::PENDING, Mandate::APPROVED]))
                ->with(['character.user'])
                ->get()
                ->reject(fn (Mandate $mandate) => $this->hasAcceptedSuccessor($mandate));

            foreach ($mandates as $mandate) {
                $mandate->character?->user?->notify(new MandateReminder($mandate));
                // Posé APRÈS chaque envoi : si la tâche échoue au milieu, la relance ne renvoie
                // pas les rappels déjà partis.
                $mandate->forceFill(['reminder_sent_at' => Carbon::now()])->save();
                $sent++;
            }
        }

        MandateHeartbeat::record('reminders');
        $this->info("{$sent} rappel(s) envoyé(s).");

        return self::SUCCESS;
    }
}
