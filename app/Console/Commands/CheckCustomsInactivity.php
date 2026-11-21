<?php

namespace App\Console\Commands;

use App\Models\CustomsOfficer;
use App\Notifications\CustomsInactivityNotice;
use App\Services\CustomsOfficers;
use App\Services\MandateAuthority;
use App\Support\MandateHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Inactivité des douaniers (module Sécurité ; Greg, 21/11/2026). Une fois par jour, 09:00 Paris, heartbeat `customs`.
 *
 * - Province AVEC trio de sécurité connu (prévôt, connétable, capitaine) : RIEN n'est révoqué. L'inactivité est signalée au trio dans
 *   l'interface (`inactive` de l'API) et il décide.
 * - Province SANS trio connu : avis par email au douanier 7 jours avant l'échéance (une seule fois), puis révocation automatique à
 *   l'échéance. Il peut refaire une demande ensuite.
 *
 * Le compteur part de l'approbation, puis du dernier bilan saisi. Durée : réglage de la province, 90 jours par défaut.
 */
class CheckCustomsInactivity extends Command
{
    protected $signature = 'customs:inactivity';

    protected $description = "Avise ou révoque les douaniers inactifs (sans trio connu) ; le trio, lui, voit l'inactivité à l'écran.";

    public function handle(CustomsOfficers $officers, MandateAuthority $authority): int
    {
        $now = Carbon::now();
        $noticed = 0;
        $revoked = 0;

        CustomsOfficer::query()->approved()->with('character.user', 'province')->get()
            ->each(function (CustomsOfficer $officer) use ($officers, $authority, $now, &$noticed, &$revoked) {
                $lapse = $officers->lapsesAt($officer);
                if ($lapse === null || $authority->provinceHasSecurityTrio($officer->province)) {
                    return;
                }

                if ($officers->isInactive($officer, $now)) {
                    $officers->revokeForInactivity($officer);
                    $revoked++;

                    return;
                }

                $noticeFrom = $lapse->copy()->subDays(CustomsOfficer::INACTIVITY_NOTICE_DAYS);
                if ($now->greaterThanOrEqualTo($noticeFrom) && $officer->inactivity_notice_sent_at === null) {
                    $user = $officer->character?->user;
                    if ($user) {
                        $user->notify(new CustomsInactivityNotice($officer, $lapse));
                    }
                    $officer->forceFill(['inactivity_notice_sent_at' => $now])->save();
                    $noticed++;
                }
            });

        MandateHeartbeat::record('customs');
        $this->info("{$noticed} avis envoyé(s), {$revoked} accès révoqué(s).");

        return self::SUCCESS;
    }
}
