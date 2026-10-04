<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Préavis de suppression d'un compte inactif (/legal/privacy §5 : « après un email de préavis à
 * l'adresse déclarée »). Bilingue, français puis anglais, synchrone (fil politique-promesses, Q5).
 * Acte de l'Office : dates réelles seules.
 */
class InactiveAccountNotice extends Notification
{
    public function __construct(public readonly Carbon $lastSeen, public readonly Carbon $deletionDate) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(__('accounts.email.inactive_subject', [], 'fr').' / '.__('accounts.email.inactive_subject', [], 'en'));
        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail
            ->action(__('accounts.email.inactive_action', [], 'fr').' / '.__('accounts.email.inactive_action', [], 'en'),
                rtrim((string) config('app.frontend_url'), '/').'/login')
            ->salutation(__('accounts.email.salutation', [], 'fr')."\n\n".__('accounts.email.salutation', [], 'en'));
    }

    /** @return array<int, string> */
    public function lines(): array
    {
        $dates = [
            'last_seen' => $this->lastSeen->copy()->setTimezone('Europe/Paris')->format('d/m/Y'),
            'date' => $this->deletionDate->copy()->setTimezone('Europe/Paris')->format('d/m/Y'),
        ];
        $lines = [];
        foreach (['fr', 'en'] as $locale) {
            if ($locale === 'en') {
                $lines[] = '———';
            }
            $lines[] = __('accounts.email.greeting', [], $locale);
            $lines[] = __('accounts.email.inactive_notice', $dates, $locale);
            $lines[] = __('accounts.email.inactive_keep', $dates, $locale);
            $lines[] = __('accounts.email.history_kept', [], $locale);
        }

        return $lines;
    }
}
