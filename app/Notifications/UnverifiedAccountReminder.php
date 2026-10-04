<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Rappel avant suppression d'un compte jamais confirmé (/legal/privacy §5). Porte un NOUVEAU lien de
 * vérification (fil politique-promesses, Q4 : un rappel sans le moyen de confirmer n'est pas une
 * chance), valable jusqu'à la date de suppression — le lien d'inscription, lui, expire en 60 minutes.
 * Bilingue, français puis anglais (Q5).
 */
class UnverifiedAccountReminder extends Notification
{
    public function __construct(public readonly Carbon $deletionDate) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(__('accounts.email.unverified_subject', [], 'fr').' / '.__('accounts.email.unverified_subject', [], 'en'));
        foreach ($this->lines($notifiable) as $line) {
            $mail->line($line);
        }

        return $mail
            ->action(__('accounts.email.unverified_action', [], 'fr').' / '.__('accounts.email.unverified_action', [], 'en'),
                $this->verificationUrl($notifiable))
            ->salutation(__('accounts.email.salutation', [], 'fr')."\n\n".__('accounts.email.salutation', [], 'en'));
    }

    public function verificationUrl(object $notifiable): string
    {
        return URL::temporarySignedRoute('verification.verify.api', $this->deletionDate, [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ]);
    }

    /** @return array<int, string> */
    public function lines(object $notifiable): array
    {
        $dates = [
            'created' => $notifiable->created_at->copy()->setTimezone('Europe/Paris')->format('d/m/Y'),
            'date' => $this->deletionDate->copy()->setTimezone('Europe/Paris')->format('d/m/Y'),
        ];
        $lines = [];
        foreach (['fr', 'en'] as $locale) {
            if ($locale === 'en') {
                $lines[] = '———';
            }
            $lines[] = __('accounts.email.greeting', [], $locale);
            $lines[] = __('accounts.email.unverified_notice', $dates, $locale);
            $lines[] = __('accounts.email.unverified_not_you', [], $locale);
        }

        return $lines;
    }
}
