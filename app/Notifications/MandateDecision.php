<?php

namespace App\Notifications;

use App\Models\Mandate;
use App\Support\MandateLabels;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Décision sur un mandat ou un poste, envoyée au joueur. TOUJOURS BILINGUE, français d'abord puis
 * anglais (décision de Greg, 03/10/2026) : aucune langue n'est stockée sur le compte. Jamais la
 * note interne.
 *
 * Envoi SYNCHRONE, volontairement : une file (ShouldQueue) ferait basculer failed_jobs en
 * catégorie B (admin/strategies/donnees-utilisateur.md §3).
 *
 * Garde-fou : MandateLanguageTest rend CHAQUE type dans les deux langues et échoue si une clé
 * brute (« mandates.… ») apparaît — __() la renvoie quand une traduction manque.
 */
class MandateDecision extends Notification
{
    public const TYPES = [
        'approved', 'rejected', 'revoked', 'handover_out', 'handover_in',
        'office_granted', 'office_removed', 'office_rejected', 'reason_corrected',
    ];

    /**
     * @param  array{date?: ?\DateTimeInterface, reason?: ?string, old_reason?: ?string, message?: ?string, locale?: ?string, office?: ?string, elected?: bool, title_dropped?: bool, can_reapply?: bool}  $details
     */
    public function __construct(
        public readonly string $type,
        public readonly Mandate $mandate,
        public readonly array $details = [],
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(
            __('mandates.email.subject', $this->who(), 'fr').' / '.__('mandates.email.subject', $this->who(), 'en')
        );

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail
            ->action(__('mandates.email.action', [], 'fr').' / '.__('mandates.email.action', [], 'en'),
                rtrim((string) config('app.frontend_url'), '/').'/app/profil')
            ->salutation(__('mandates.email.salutation', [], 'fr')."\n\n".__('mandates.email.salutation', [], 'en'));
    }

    /**
     * Tout le texte de l'email, français puis anglais. Public pour que le test de langue lise
     * exactement ce qui part.
     *
     * @return array<int, string>
     */
    public function lines(): array
    {
        $lines = [];
        foreach (['fr', 'en'] as $locale) {
            if ($locale === 'en') {
                $lines[] = '———';
            }
            $lines[] = __('mandates.email.greeting', [], $locale);
            $lines[] = __('mandates.email.post_line', [
                'character' => $this->mandate->character?->pseudo,
                'post' => MandateLabels::post($this->mandate, $locale),
            ], $locale);
            array_push($lines, ...$this->body($locale));
        }

        return $lines;
    }

    /** @return array<int, string> */
    private function body(string $locale): array
    {
        // Les deux formes de la date, toujours fournies : c'est la CHAÎNE qui déclare laquelle elle
        // affiche (`:date (:date_jeu)`, ou `:date` seule pour un acte de l'Office). Aucun formateur
        // ne choisit à sa place (fil mandats-historique, 07 et 10 ; MandateLanguageTest fige le choix).
        $dates = $this->who() + [
            'date' => MandateLabels::date($this->details['date'] ?? null),
            'date_jeu' => MandateLabels::gameDate($this->details['date'] ?? null),
        ];
        $reason = MandateLabels::reason($this->details['reason'] ?? null, $locale);
        $office = MandateLabels::office($this->details['office'] ?? null, $locale);

        $lines = match ($this->type) {
            'approved' => array_filter([
                __('mandates.email.approved', $dates, $locale),
                ($this->details['elected'] ?? false) ? __('mandates.email.approved_elected', [], $locale) : null,
                ($this->details['title_dropped'] ?? false) ? __('mandates.email.approved_title_dropped', [], $locale) : null,
            ]),
            'rejected' => [__('mandates.email.rejected', ['reason' => $reason], $locale)],
            'revoked' => [__('mandates.email.revoked', $dates + ['reason' => $reason], $locale)],
            'handover_out' => [__('mandates.email.handover_out', $dates, $locale)],
            'handover_in' => [__('mandates.email.handover_in', $dates, $locale)],
            'office_granted' => [__('mandates.email.office_granted', ['office' => $office], $locale)],
            'office_removed' => [__('mandates.email.office_removed', $this->who() + ['reason' => $reason], $locale)],
            'office_rejected' => [__('mandates.email.office_rejected', $this->who() + ['reason' => $reason], $locale)],
            // L'email dit ce qui a changé, pas seulement le nouveau motif (tour 17) : sans l'ancien,
            // un second motif différent arriverait sans explication.
            'reason_corrected' => array_filter([
                __('mandates.email.reason_corrected', [
                    'date' => $dates['date'],
                    'old' => MandateLabels::reason($this->details['old_reason'] ?? null, $locale),
                    'new' => $reason,
                ], $locale),
                ($this->details['can_reapply'] ?? false) ? __('mandates.email.reason_corrected_reapply', [], $locale) : null,
            ]),
        };

        // Commentaire libre : envoyé tel quel, étiqueté par la langue dans laquelle il est écrit.
        $message = $this->details['message'] ?? null;
        if ($message !== null && $message !== '') {
            $written = ($this->details['locale'] ?? 'fr') === 'en' ? 'comment_en' : 'comment_fr';
            $lines[] = __("mandates.email.{$written}", [], $locale);
            $lines[] = $message;
        }

        return array_values($lines);
    }

    /** Le personnage concerné : un compte peut en avoir plusieurs, chaque email le nomme (10). */
    private function who(): array
    {
        return ['character' => (string) $this->mandate->character?->pseudo];
    }
}
