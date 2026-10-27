<?php

namespace App\Services;

use App\Models\AnimDecodeActivity;
use App\Models\AnimDecodeProposal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LA clôture d'une activité, quel que soit son déclencheur : l'organisateur, l'administration, ou les 30 jours sans action
 * (`anim:close-inactive`). Un seul chemin, pour qu'aucune clôture n'oublie d'effacer ce que la décision de Greg promet.
 *
 *  - Activité PRIVÉE : elle passe à `closed` et GARDE tout (réouverture possible, jamais de suppression automatique : D20).
 *  - Activité SANS COMPTE : clôture DÉFINITIVE (D19). Dans UNE transaction, la photo figée est écrite (D18 : nom, organisateur,
 *    lieu, résultats, classement final) et le reste est effacé — coffre non percé et son code, propositions,
 *    mot de passe haché, empreinte d'IP (D16), adresse email (D30), jeton de réinitialisation. Le compte rendu complet part à
 *    l'adresse confirmée APRÈS la transaction : un échec d'envoi ne bloque ni n'annule jamais la clôture.
 */
class DecodeClosing
{
    public function __construct(
        private readonly DecodeActivityReader $reader,
        private readonly DecodeMailer $mailer,
    ) {}

    public function close(AnimDecodeActivity $activity): AnimDecodeActivity
    {
        if ($activity->isClosed()) {
            return $activity;
        }

        if (! $activity->is_anonymous) {
            $activity->forceFill(['status' => AnimDecodeActivity::CLOSED, 'closed_at' => Carbon::now()])->save();

            return $activity;
        }

        // Tout ce qui doit survivre ou partir en courrier est lu AVANT l'effacement.
        $snapshot = $this->reader->snapshot($activity);
        $report = $activity->hasVerifiedEmail() ? $this->reader->fullReport($activity) : null;
        $email = $report !== null ? $activity->organizer_email : null;

        DB::transaction(function () use ($activity, $snapshot) {
            $secretIds = $activity->secrets()->pluck('id');
            AnimDecodeProposal::whereIn('secret_id', $secretIds)->delete();
            $activity->secrets()->delete();

            $activity->forceFill([
                'status' => AnimDecodeActivity::CLOSED,
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

        if ($email !== null) {
            $this->mailer->sendFinalReport($email, $activity->id, $report);
        }

        return $activity->refresh();
    }
}
