<?php

namespace App\Notifications;

use App\Support\EmailVerificationLink;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

class VerifyApiEmail extends VerifyEmail
{
    /**
     * Lien signé pointant vers l'API (pas la route Blade verification.verify) —
     * AuthController::verifyEmail() redirige ensuite vers le frontend.
     */
    /**
     * Lien vers le site des joueurs (App\Support\EmailVerificationLink), qui rappelle l'API : jamais
     * l'adresse de l'API elle-même dans l'email.
     */
    protected function verificationUrl($notifiable)
    {
        return EmailVerificationLink::url($notifiable, Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)));
    }

    /**
     * Bilingue, français puis anglais, comme tous les emails des joueurs (Greg, 04/10/2026) : la
     * langue de l'inscrit n'est pas connue à ce stade.
     */
    protected function buildMailMessage($url)
    {
        $minutes = (int) Config::get('auth.verification.expire', 60);
        $mail = (new MailMessage)
            ->subject(__('verification.email.subject', [], 'fr').' / '.__('verification.email.subject', [], 'en'));

        foreach (['fr', 'en'] as $locale) {
            if ($locale === 'en') {
                $mail->line('———');
            }
            $mail->line(__('verification.email.greeting', [], $locale))
                ->line(__('verification.email.thanks', [], $locale))
                ->line(__('verification.email.expires', ['minutes' => $minutes], $locale))
                ->line(__('verification.email.not_you', [], $locale));
        }

        return $mail
            ->action(__('verification.email.action', [], 'fr').' / '.__('verification.email.action', [], 'en'), $url)
            ->salutation(__('verification.email.salutation', [], 'fr')."\n\n".__('verification.email.salutation', [], 'en'));
    }
}
