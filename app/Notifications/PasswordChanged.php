<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * « Votre mot de passe a été modifié » (à chaque changement — réinitialisation ET profil). Alerte un
 * propriétaire dont le compte serait pris. Bilingue FR puis EN ; ne dit JAMAIS le mot de passe ni un lien.
 */
class PasswordChanged extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('verification.password_changed_notice.subject', [], 'fr').' / '.__('verification.password_changed_notice.subject', [], 'en'));

        foreach (['fr', 'en'] as $locale) {
            if ($locale === 'en') {
                $mail->line('———');
            }
            $mail->line(__('verification.password_changed_notice.greeting', [], $locale))
                ->line(__('verification.password_changed_notice.intro', [], $locale))
                ->line(__('verification.password_changed_notice.warning', [], $locale));
        }

        return $mail->salutation(
            __('verification.password_changed_notice.salutation', [], 'fr')."\n\n".__('verification.password_changed_notice.salutation', [], 'en')
        );
    }
}
