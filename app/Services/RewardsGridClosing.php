<?php

namespace App\Services;

use App\Models\AnimRewardsGrid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LA clôture d'une grille, quel que soit son déclencheur : l'organisateur, l'administrateur du site, ou les 30 jours
 * sans action (`anim:close-inactive`). Un seul chemin, pour qu'aucune clôture n'oublie d'effacer ce que la décision
 * de Greg promet.
 *
 *  - Grille PRIVÉE : elle passe à `closed` et GARDE tout (réouverture possible, jamais de suppression automatique).
 *  - Grille SANS COMPTE : clôture DÉFINITIVE. Dans UNE transaction, la photo figée est écrite et les données
 *    personnelles sont effacées — mot de passe haché, empreinte d'IP, adresse email, jeton de réinitialisation.
 *    Les LOTS restent en base (données communautaires) et sont purges à 5 ans par `anim:purge-closed`.
 *    ⚠️ Aucun email : « Hors scope: Notifications email » pour ce module.
 */
class RewardsGridClosing
{
    public function __construct(
        private readonly RewardsGridReader $reader,
    ) {}

    public function close(AnimRewardsGrid $grid): AnimRewardsGrid
    {
        if ($grid->isClosed()) {
            return $grid;
        }

        if (! $grid->is_anonymous) {
            $grid->forceFill(['status' => AnimRewardsGrid::CLOSED, 'closed_at' => Carbon::now()])->save();

            return $grid;
        }

        // Photo figée des métadonnées (nom/organisateur/lieu/dimensions) — les lots restent dans la table.
        $snapshot = $this->reader->snapshot($grid);

        DB::transaction(function () use ($grid, $snapshot) {
            $grid->forceFill([
                'status' => AnimRewardsGrid::CLOSED,
                'closed_at' => Carbon::now(),
                'closing_snapshot' => $snapshot,
                'password_hash' => null,
                'creator_ip_hash' => null,
                'organizer_email' => null,
                'organizer_email_verified_at' => null,
                'reset_requested_at' => null,
                'reset_token_hash' => null,
                'reset_token_expires_at' => null,
            ])->save();
        });

        return $grid->refresh();
    }
}
