<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * Email envoyé à la NOUVELLE adresse pour confirmer un changement d'email.
 * Le lien signé pointe vers la route account.email.verify (GET account/email/verify/{id}/{hash}).
 * Bilingue FR puis EN, comme tous les emails des joueurs.
 */
class VerifyEmailChange extends Notification
{
    public function __construct(private readonly string $pendingEmail) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) Config::get('auth.verification.expire', 60);
        $url = $this->verificationUrl($notifiable);

        $mail = (new MailMessage)
            ->subject(
                __('verification.email_change.subject', [], 'fr')
                .' / '
                .__('verification.email_change.subject', [], 'en')
            );

        foreach (['fr', 'en'] as $locale) {
            if ($locale === 'en') {
                $mail->line('———');
            }
            $mail->line(__('verification.email_change.greeting', [], $locale))
                ->line(__('verification.email_change.intro', [], $locale))
                ->line(__('verification.email_change.expires', ['minutes' => $minutes], $locale))
                ->line(__('verification.email_change.not_you', [], $locale));
        }

        return $mail
            ->action(
                __('verification.email_change.action', [], 'fr').' / '.__('verification.email_change.action', [], 'en'),
                $url
            )
            ->salutation(
                __('verification.email_change.salutation', [], 'fr')
                ."\n\n"
                .__('verification.email_change.salutation', [], 'en')
            );
    }

    private function verificationUrl(object $notifiable): string
    {
        $expires = Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60));

        $signed = URL::temporarySignedRoute('account.email.verify', $expires, [
            'id'   => $notifiable->getKey(),
            'hash' => sha1($this->pendingEmail),
        ]);

        // Pointe vers le site des joueurs, pas le domaine admin — même principe que EmailVerificationLink.
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return rtrim((string) config('app.frontend_url'), '/').'/verify-email-change?'.http_build_query([
            'id'        => $notifiable->getKey(),
            'hash'      => sha1($this->pendingEmail),
            'expires'   => $query['expires'],
            'signature' => $query['signature'],
        ]);
    }
}
