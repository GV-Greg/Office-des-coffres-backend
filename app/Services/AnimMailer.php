<?php

namespace App\Services;

use App\Models\AnimDecodeActivity;
use App\Models\AnimRewardsGrid;
use App\Notifications\AnimEmailConfirmation;
use App\Notifications\AnimPasswordChanged;
use App\Notifications\AnimPasswordResetLink;
use App\Notifications\DecodeFinalReport;
use App\Support\AnimKinds;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Seul expéditeur des emails des activités d'Animation sans compte (D23) : Décode le code et Grille des lots. L'adresse n'est
 * jamais écrite dans un journal, ni le contenu d'un message : un échec d'envoi se signale par le type et l'identifiant de
 * l'activité seulement, et ne bloque JAMAIS le geste qui l'a provoqué (une clôture, une création) — l'envoi est synchrone,
 * sans file d'attente. Une adresse n'est utilisée qu'une fois CONFIRMÉE (sauf le message de confirmation lui-même).
 */
class AnimMailer
{
    public function sendConfirmation(AnimDecodeActivity|AnimRewardsGrid $activity): bool
    {
        $email = $activity->organizer_email;
        $kind = AnimKinds::of($activity);

        return $email !== null && $this->send($kind['key'], 'confirmation', $activity->id, $email, new AnimEmailConfirmation(
            $activity->name,
            $activity->slug,
            $kind['links']::emailConfirmation($activity, $email),
            $kind['key'],
            $kind['report'],
        ));
    }

    public function sendResetLink(AnimDecodeActivity|AnimRewardsGrid $activity, string $token): bool
    {
        $kind = AnimKinds::of($activity);

        return $activity->hasVerifiedEmail() && $this->send($kind['key'], 'reset', $activity->id, $activity->organizer_email, new AnimPasswordResetLink(
            $activity->name,
            $kind['links']::reset($activity->slug, $token),
        ));
    }

    public function sendPasswordChanged(AnimDecodeActivity|AnimRewardsGrid $activity): bool
    {
        return $activity->hasVerifiedEmail()
            && $this->send(AnimKinds::of($activity)['key'], 'changed', $activity->id, $activity->organizer_email, new AnimPasswordChanged($activity->name));
    }

    /** Décode le code seulement (la grille ne garde que ses lots, rien à rendre compte).
     * @param  array<string, mixed>  $report  voir DecodeActivityReader::fullReport() */
    public function sendFinalReport(string $email, int $activityId, array $report): bool
    {
        return $this->send('decode', 'report', $activityId, $email, new DecodeFinalReport($report));
    }

    private function send(string $type, string $kind, int $activityId, string $email, object $notification): bool
    {
        try {
            Notification::route('mail', $email)->notify($notification);

            return true;
        } catch (Throwable) {
            // Ni l'adresse, ni le message, ni l'exception (elle peut citer l'adresse) : le type, l'activité et le genre de message seulement.
            Log::warning('anim.mail_failed', ['type' => $type, 'activity' => $activityId, 'kind' => $kind]);

            return false;
        }
    }
}
