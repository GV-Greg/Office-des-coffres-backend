<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification envoyée à l'ANCIENNE adresse après confirmation du changement d'email.
 * Alerte le joueur si le changement n'est pas de lui. Bilingue FR puis EN.
 */
class EmailChanged extends Notification
{
    public function __construct(private readonly string $newEmail) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(
                __('verification.email_changed_notice.subject', [], 'fr')
                .' / '
                .__('verification.email_changed_notice.subject', [], 'en')
            );

        foreach (['fr', 'en'] as $locale) {
            if ($locale === 'en') {
                $mail->line('———');
            }
            $mail->line(__('verification.email_changed_notice.greeting', [], $locale))
                ->line(__('verification.email_changed_notice.intro', ['new_email' => $this->newEmail], $locale))
                ->line(__('verification.email_changed_notice.warning', [], $locale));
        }

        return $mail->salutation(
            __('verification.email_change.salutation', [], 'fr')
            ."\n\n"
            .__('verification.email_change.salutation', [], 'en')
        );
    }
}
