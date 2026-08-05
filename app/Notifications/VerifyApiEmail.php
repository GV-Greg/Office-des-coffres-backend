<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class VerifyApiEmail extends VerifyEmail
{
    /**
     * Lien signé pointant vers l'API (pas la route Blade verification.verify) —
     * AuthController::verifyEmail() redirige ensuite vers le frontend.
     */
    protected function verificationUrl($notifiable)
    {
        return URL::temporarySignedRoute(
            'verification.verify.api',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }

    protected function buildMailMessage($url)
    {
        return (new MailMessage)
            ->subject('Confirmez votre adresse email — Office des coffres')
            ->line("Merci de vous être inscrit sur l'Office des coffres.")
            ->action('Confirmer mon email', $url)
            ->line('Ce lien expire dans 60 minutes.')
            ->line("Si vous n'êtes pas à l'origine de cette inscription, vous pouvez ignorer cet email.")
            ->salutation("Cordialement,\nOffice des coffres");
    }
}
