<?php

namespace App\Notifications;

use App\Models\Province;
use App\Models\ProvinceMinePrice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Résumé quotidien des changements de prix des mines d'une province, aux autres occupants des trois
 * postes qui les gèrent (brief admin/content/brief-prix-registre.md ; Greg, 15/10/2026 : un résumé à
 * 15:00 plutôt qu'un email par modification, et seulement s'il y a eu des changements). Bilingue,
 * français puis anglais, comme tous les emails des joueurs. Lien vers le SITE des joueurs, jamais
 * vers l'administration.
 */
class MinePricesDigest extends Notification
{
    /**
     * @param  Collection<int, array{price: ProvinceMinePrice, created: bool, author: ?string}>  $changes
     */
    public function __construct(public readonly Province $province, public readonly Collection $changes) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = fn (string $locale) => __('mines.prices_digest.subject', [
            'province' => $this->province->province_name, 'count' => $this->changes->count(),
        ], $locale);

        $mail = (new MailMessage)->subject($subject('fr').' / '.$subject('en'));
        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail
            ->action(__('mines.prices_digest.action', [], 'fr').' / '.__('mines.prices_digest.action', [], 'en'),
                rtrim((string) config('app.frontend_url'), '/').'/app/eco/registre/prix')
            ->salutation(__('mandates.email.salutation', [], 'fr')."\n\n".__('mandates.email.salutation', [], 'en'));
    }

    /** @return array<int, string> */
    public function lines(): array
    {
        $lines = [];
        foreach (['fr', 'en'] as $locale) {
            if ($locale === 'en') {
                $lines[] = '———';
            }
            $lines[] = __('mines.prices_digest.greeting', [], $locale);
            $lines[] = __('mines.prices_digest.intro', ['province' => $this->province->province_name], $locale);
            foreach ($this->changes as $change) {
                $lines[] = __('mines.prices_digest.line_'.($change['created'] ? 'created' : 'updated'), [
                    'date' => $locale === 'fr'
                        ? $change['price']->effective_from->format('d/m/Y')
                        : $change['price']->effective_from->format('Y-m-d'),
                    'author' => $change['author'] ?? __('mines.prices_digest.unknown_author', [], $locale),
                ], $locale);
            }
            $lines[] = __('mines.prices_digest.outro', [], $locale);
        }

        return $lines;
    }
}
