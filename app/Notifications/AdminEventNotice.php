<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification d'administration interne : un événement lié aux comptes, adressé aux comptes `admin`. En
 * français seulement, comme le récapitulatif des mandats : l'interface d'administration n'a pas de
 * bascule de langue. Courte et factuelle ; le contenu est composé par `App\Services\AdminNotifier`.
 * Jamais d'adresse email de joueur ni de mot de passe dans le corps.
 */
class AdminEventNotice extends Notification
{
    /** @param  array<int, string>  $lines */
    public function __construct(
        public readonly string $subject,
        public readonly array $lines,
        public readonly string $actionLabel,
        public readonly string $actionUrl,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject);
        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail->action($this->actionLabel, $this->actionUrl)
            ->salutation(__('mandates.email.salutation', [], 'fr'));
    }
}
