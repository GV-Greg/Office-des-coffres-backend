<?php

namespace App\Notifications;

use App\Models\CustomsOfficer;
use App\Support\MandateLabels;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Avis au douanier, 7 jours avant la fin de son accès pour inactivité (province SANS trio connu ; Greg, 21/11/2026). Bilingue, français
 * puis anglais, synchrone. 🔴 Aucun lien vers le domaine d'administration (hameçonnage), jamais l'email d'un autre joueur.
 */
class CustomsInactivityNotice extends Notification
{
    public function __construct(public readonly CustomsOfficer $officer, public readonly Carbon $lapsesAt) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $province = (string) $this->officer->province?->province_name;
        $mail = (new MailMessage)->subject(
            __('security.email.inactivity_subject', ['province' => $province], 'fr')
            .' / '.__('security.email.inactivity_subject', ['province' => $province], 'en')
        );
        foreach (['fr', 'en'] as $locale) {
            if ($locale === 'en') {
                $mail->line('———');
            }
            $mail->line(__('security.email.greeting', [], $locale));
            $mail->line(__('security.email.inactivity_detail', [
                'province' => $province,
                'date' => MandateLabels::date($this->lapsesAt),
            ], $locale));
        }

        return $mail
            ->action(__('security.email.action', [], 'fr'), rtrim((string) config('app.frontend_url'), '/').'/app/')
            ->salutation(__('security.email.salutation', [], 'fr')."\n\n".__('security.email.salutation', [], 'en'));
    }
}
