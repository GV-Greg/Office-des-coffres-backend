<?php

namespace App\Notifications;

use App\Models\Mandate;
use App\Support\MandateLabels;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Récapitulatif quotidien de la file de vérification, pour les administrateurs — en français
 * (interface d'administration, arbitrage Q5). Un seul email, deux sections (maires, conseillers),
 * chaque ligne avec son ANCIENNETÉ : une ligne ignorée devient de plus en plus voyante au lieu
 * d'être identique chaque matin (fil lot 3, Q3).
 */
class MandateVerificationDigest extends Notification
{
    /**
     * @param  Collection<int, array{mandate: Mandate, ended_at: Carbon, days: int, overdue: bool}>  $items
     */
    public function __construct(public readonly Collection $items) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(__('mandates.digest.subject', ['count' => $this->items->count()]));
        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail->action(__('mandates.digest.action'), url('/mandates/verification'))
            ->salutation(__('mandates.email.salutation', [], 'fr'));
    }

    /** @return array<int, string> */
    public function lines(): array
    {
        $lines = [__('mandates.digest.intro')];
        foreach (['mayor' => 'section_mayors', 'council' => 'section_councillors'] as $level => $title) {
            $section = $this->items->filter(fn (array $item) => $level === $item['mandate']::LEVEL)
                ->sortByDesc('days');
            if ($section->isEmpty()) {
                continue;
            }
            $lines[] = __("mandates.digest.{$title}");
            foreach ($section as $item) {
                $lines[] = __('mandates.digest.line', [
                    'post' => MandateLabels::post($item['mandate'], 'fr'),
                    'pseudo' => $item['mandate']->character?->pseudo,
                    'ended' => MandateLabels::date($item['ended_at']),
                    'late' => $item['days'] - config('mandates.verification_days.'.$level),
                ]);
            }
        }

        return $lines;
    }
}
