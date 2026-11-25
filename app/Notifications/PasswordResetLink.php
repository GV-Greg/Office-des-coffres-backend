<?php

namespace App\Notifications;

use App\Support\PasswordResetLink as ResetLink;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Lien de réinitialisation du mot de passe (validé le 24/11/2026) : à usage unique, valable
 * `auth.passwords.users.expire` minutes (60). Le joueur CHOISIT lui-même son nouveau mot de passe sur le site : aucun mot de passe ne circule par
 * email. Bilingue FR puis EN, gabarit commun des emails ; le lien pointe vers le site des joueurs.
 */
class PasswordResetLink extends Notification
{
    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
        $mail = (new MailMessage)
            ->subject(__('verification.password_reset.subject', [], 'fr').' / '.__('verification.password_reset.subject', [], 'en'));

        foreach (['fr', 'en'] as $locale) {
            if ($locale === 'en') {
                $mail->line('———');
            }
            $mail->line(__('verification.password_reset.greeting', [], $locale))
                ->line(__('verification.password_reset.intro', [], $locale))
                ->line(__('verification.password_reset.expires', ['minutes' => $minutes], $locale))
                ->line(__('verification.password_reset.not_you', [], $locale));
        }

        // Le bouton APRÈS le texte (les lignes posées avant `action()` sont au-dessus du bouton, celles d'après en dessous).
        $mail->action(
            __('verification.password_reset.action', [], 'fr').' / '.__('verification.password_reset.action', [], 'en'),
            ResetLink::url($this->token, $notifiable->getEmailForPasswordReset()),
        );

        return $mail->salutation(
            __('verification.password_reset.salutation', [], 'fr')."\n\n".__('verification.password_reset.salutation', [], 'en')
        );
    }
}
