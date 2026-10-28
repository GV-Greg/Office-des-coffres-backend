<?php

namespace App\Services;

use App\Models\AnimDecodeActivity;
use App\Notifications\DecodeEmailConfirmation;
use App\Notifications\DecodeFinalReport;
use App\Notifications\DecodePasswordChanged;
use App\Notifications\DecodePasswordResetLink;
use App\Support\DecodeLinks;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Seul expéditeur des emails de « Décode le code » (D23). L'adresse n'est jamais écrite dans un journal, ni le contenu d'un
 * message : un échec d'envoi se signale par l'identifiant de l'activité seulement, et ne bloque JAMAIS le geste qui
 * l'a provoqué (une clôture, une création) — l'envoi est synchrone, sans file d'attente.
 */
class DecodeMailer
{
    public function sendConfirmation(AnimDecodeActivity $activity): bool
    {
        $email = $activity->organizer_email;

        return $email !== null && $this->send('confirmation', $activity->id, $email, new DecodeEmailConfirmation(
            $activity->name,
            $activity->slug,
            DecodeLinks::emailConfirmation($activity, $email),
        ));
    }

    public function sendResetLink(AnimDecodeActivity $activity, string $token): bool
    {
        return $activity->hasVerifiedEmail() && $this->send('reset', $activity->id, $activity->organizer_email, new DecodePasswordResetLink(
            $activity->name,
            DecodeLinks::reset($activity->slug, $token),
        ));
    }

    public function sendPasswordChanged(AnimDecodeActivity $activity): bool
    {
        return $activity->hasVerifiedEmail() && $this->send('changed', $activity->id, $activity->organizer_email, new DecodePasswordChanged($activity->name));
    }

    /** @param  array<string, mixed>  $report  voir DecodeActivityReader::fullReport() */
    public function sendFinalReport(string $email, int $activityId, array $report): bool
    {
        return $this->send('report', $activityId, $email, new DecodeFinalReport($report));
    }

    private function send(string $kind, int $activityId, string $email, object $notification): bool
    {
        try {
            Notification::route('mail', $email)->notify($notification);

            return true;
        } catch (Throwable) {
            // Ni l'adresse, ni le message, ni l'exception (elle peut citer l'adresse) : l'activité et le type seulement.
            Log::warning('anim.decode.mail_failed', ['activity' => $activityId, 'kind' => $kind]);

            return false;
        }
    }
}
