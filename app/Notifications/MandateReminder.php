<?php

namespace App\Notifications;

use App\Models\Mandate;
use App\Support\MandateLabels;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Rappel de renouvellement, 2 jours après la fin EFFECTIVE d'un mandat (lot 3, décision de Greg du
 * 03/10/2026). Bilingue, français puis anglais, synchrone, comme MandateDecision.
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
            ->action(__('mandates.email.action', [], 'fr').' / '.__('mandates.email.action', [], 'en'),
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
            $lines[] = __('mandates.email.reminder', [
                'post' => MandateLabels::post($this->mandate, $locale),
                'character' => $this->mandate->character?->pseudo,
                'date' => MandateLabels::date($this->mandate->holds_until),
                'date_jeu' => MandateLabels::gameDate($this->mandate->holds_until),
            ], $locale);
            if ($locale === 'fr') {
                $lines[] = __('mandates.email.english_below', [], 'fr');
            }
        }

        return $lines;
    }
}
