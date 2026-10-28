<?php

namespace App\Notifications;

use App\Support\DecodeLinks;

/**
 * Compte rendu de fin d'activité (D23, D31) : TOUTES les données, propositions comprises — c'est la seule copie, le site
 * n'en garde que le résumé figé (D18). Envoyé à la clôture, juste avant l'effacement de l'adresse (D30).
 *
 * @phpstan-type Report array{name: string, slug: string, chests: list<array{number: int, code: string, opened: bool, discoverer: ?string, proposals: list<array{combination: string, character_name: string, points: int}>}>, ranking: list<array{character_name: string, points: int}>}
 */
class DecodeFinalReport extends DecodeMessage
{
    /** @param  Report  $report */
    public function __construct(private readonly array $report) {}

    protected function params(): array
    {
        return ['name' => $this->report['name']];
    }

    protected function subjectKey(): string
    {
        return 'anim.email.report_subject';
    }

    protected function lines(string $locale): array
    {
        $lines = [
            __('anim.email.greeting', [], $locale),
            __('anim.email.report_intro', $this->params(), $locale),
        ];

        foreach ($this->report['chests'] as $chest) {
            $lines[] = $chest['opened']
                ? __('anim.email.report_chest_opened', ['number' => $chest['number'], 'code' => $chest['code'], 'character' => $chest['discoverer'] ?? '?'], $locale)
                : __('anim.email.report_chest_sealed', ['number' => $chest['number'], 'code' => $chest['code']], $locale);
            if ($chest['proposals'] === []) {
                $lines[] = __('anim.email.report_no_attempt', [], $locale);
            }
            foreach ($chest['proposals'] as $proposal) {
                $lines[] = __('anim.email.report_attempt', ['combination' => $proposal['combination'], 'character' => $proposal['character_name'], 'points' => $proposal['points']], $locale);
            }
        }

        if ($this->report['ranking'] !== []) {
            $lines[] = __('anim.email.report_ranking', [], $locale);
            foreach ($this->report['ranking'] as $rank => $row) {
                $lines[] = __('anim.email.report_ranking_line', ['rank' => $rank + 1, 'character' => $row['character_name'], 'points' => $row['points']], $locale);
            }
        }

        $lines[] = __('anim.email.report_page', ['years' => 5], $locale);

        return $lines;
    }

    protected function action(): ?array
    {
        return ['anim.email.report_action', DecodeLinks::page($this->report['slug'])];
    }
}
