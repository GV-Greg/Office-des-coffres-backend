<?php

namespace App\Notifications;

use App\Support\PolicyChangelog;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification d'une modification substantielle de la politique de confidentialité (/legal/privacy
 * §10 ; brief-politique-promesses §4). Bilingue, français puis anglais, synchrone, comme les emails
 * des mandats (fil politique-promesses, Q5).
 *
 * 🔴 AUCUN lien de désinscription : c'est une information légale, pas une lettre d'information
 * (brief §4.2). Ne pas en ajouter « par réflexe » — PolicyNotifyTest le vérifie.
 */
class PolicyUpdated extends Notification
{
    /** @param array{date: string, summary: array{fr: string, en: string}} $entry */
    public function __construct(public readonly array $entry) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(__('policy.email.subject', [], 'fr').' / '.__('policy.email.subject', [], 'en'));
        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail
            ->action(__('policy.email.action', [], 'fr').' / '.__('policy.email.action', [], 'en'),
                rtrim((string) config('app.frontend_url'), '/').'/legal/privacy')
            ->salutation(__('policy.email.salutation', [], 'fr')."\n\n".__('policy.email.salutation', [], 'en'));
    }

    /** @return array<int, string> */
    public function lines(): array
    {
        $date = PolicyChangelog::date($this->entry)->format('d/m/Y');
        $lines = [];
        foreach (['fr', 'en'] as $locale) {
            if ($locale === 'en') {
                $lines[] = '———';
            }
            $lines[] = __('policy.email.greeting', [], $locale);
            $lines[] = __('policy.email.intro', ['date' => $date], $locale);
            $lines[] = $this->entry['summary'][$locale];
            $lines[] = __('policy.email.why', [], $locale);
        }

        return $lines;
    }
}
