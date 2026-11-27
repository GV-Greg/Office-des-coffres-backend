<?php

namespace App\Notifications;

use App\Models\Mandate;
use App\Support\MandateLabels;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Rappel de renouvellement, 2 jours après la fin EFFECTIVE d'un mandat (lot 3, décision de Greg du
 * 03/10/2026) — ou, pour un conseiller qui attend son successeur, après le TERME de son mandat. Bilingue, français puis anglais, synchrone, comme MandateDecision.
 */
class MandateReminder extends Notification
{
    public function __construct(public readonly Mandate $mandate) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(
            __('mandates.email.reminder_subject', ['character' => (string) $this->mandate->character?->pseudo], 'fr')
            .' / '.__('mandates.email.reminder_subject', ['character' => (string) $this->mandate->character?->pseudo], 'en')
        );
        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail
            ->action(__('mandates.email.action', [], 'fr'),
                rtrim((string) config('app.frontend_url'), '/').'/app/profil')
            ->salutation(__('mandates.email.salutation', [], 'fr')."\n\n".__('mandates.email.salutation', [], 'en'));
    }

    /** @return array<int, string> */
    public function lines(): array
    {
        $lines = [];
        foreach (['fr', 'en'] as $locale) {
            if ($locale === 'en') {
                $lines[] = '———';
            }
            $lines[] = __('mandates.email.greeting', [], $locale);
            $lines[] = $this->panelLine($locale);
            // Encore en poste (attente du successeur) : on dit le terme, pas une fin qui n'est pas arrivée.
            $waiting = $this->mandate->holds_until->isFuture();
            $end = $waiting ? $this->mandate->valid_until : $this->mandate->holds_until;
            $lines[] = __($waiting ? 'mandates.email.reminder_waiting_detail' : 'mandates.email.reminder_detail', [
                'date' => MandateLabels::date($end),
                'date_jeu' => MandateLabels::gameDate($end),
            ], $locale);
            $lines[] = $locale === 'fr' ? '%%button%%' : ('%%button2:'.__('mandates.email.action', [], 'en').'%%');
        }

        return $lines;
    }

    private function panelLine(string $locale): string
    {
        $char = (string) ($this->mandate->character?->pseudo ?? '');
        $post = MandateLabels::post($this->mandate, $locale);
        return "%%panel:{$char}:{$post}::%%";
    }
}
