<?php

namespace App\Services;

use App\Models\AnimDecodeActivity;
use App\Support\DecodeLinks;

/**
 * Export BBCode de « Décode le code », pour le forum du jeu. **Français fixe**, comme le BBCode du Guet (contenu de forum
 * francophone, indépendant de la langue de l'interface). Corps RP d'abord, puis le lien dans un bloc `[hrp]` en fin (brief,
 * « Export BBCode »). Le lien est celui de la page publique, par SLUG (D8) : jamais le domaine d'administration.
 */
class DecodeExporter
{
    /** Tentatives de la section « dernières tentatives » d'un code trouvé. */
    private const LAST_ATTEMPTS = 10;

    public function __construct(private readonly DecodeActivityReader $reader) {}

    public function exportRules(AnimDecodeActivity $activity): string
    {
        $data = $this->reader->read($activity);
        $number = $data['current']['number'] ?? count($data['opened']) + 1;

        $lines = ['[b]'.$data['name'].'[/b]'];
        if ($data['organizer']) {
            $lines[] = __('anim.bbcode.organizer', ['name' => $data['organizer']], 'fr');
        }
        if ($data['place']) {
            $lines[] = __('anim.bbcode.place', ['name' => $data['place']['name']], 'fr');
        }
        $lines[] = '';
        $lines[] = __('anim.types.decode.rules', [], 'fr');
        $lines[] = '';
        $lines[] = '[hrp]'.$this->link($data, __('anim.types.decode.public_link_label', ['number' => $number], 'fr')).'[/hrp]';

        return implode("\n", $lines);
    }

    public function exportResults(AnimDecodeActivity $activity): string
    {
        $data = $this->reader->read($activity);
        $lines = ['[b]'.$data['name'].'[/b]', ''];

        if ($data['current']) {
            $lines[] = __('anim.bbcode.attempts_title', ['number' => $data['current']['number']], 'fr');
            $lines = array_merge($lines, $this->attempts($data['current']['proposals']));
        } else {
            $lines[] = __('anim.bbcode.no_open_chest', [], 'fr');
        }

        if ($data['opened']) {
            $last = end($data['opened']);
            $lines[] = '';
            $lines[] = __('anim.bbcode.found', ['number' => $last['number'], 'code' => $last['code'], 'character' => $last['discoverer'] ?? '?'], 'fr');
            $lines[] = '';
            $lines[] = __('anim.bbcode.opened_title', [], 'fr');
            foreach ($data['opened'] as $chest) {
                $lines[] = __('anim.bbcode.opened_line', ['number' => $chest['number'], 'code' => $chest['code'], 'character' => $chest['discoverer'] ?? '?'], 'fr');
            }
            $lines[] = '';
            $lines[] = __('anim.bbcode.ranking_title', [], 'fr');
            foreach ($data['ranking'] as $rank => $row) {
                $lines[] = __('anim.bbcode.ranking_line', ['rank' => $rank + 1, 'character' => $row['character_name'], 'points' => $row['points']], 'fr');
            }
        }

        $lines[] = '';
        $lines[] = '[hrp]'.$this->link($data, __('anim.bbcode.full_results', [], 'fr')).'[/hrp]';

        return implode("\n", $lines);
    }

    /** @param  list<array{combination: string, character_name: string, points: int}>  $proposals */
    private function attempts(array $proposals): array
    {
        $recent = array_slice($proposals, -self::LAST_ATTEMPTS);

        return array_map(
            fn ($p) => __('anim.bbcode.attempt_line', ['combination' => $p['combination'], 'character' => $p['character_name'], 'points' => $p['points']], 'fr'),
            $recent,
        ) ?: [__('anim.bbcode.no_attempt', [], 'fr')];
    }

    private function link(array $data, string $label): string
    {
        return '[url='.self::publicUrl($data['slug']).']'.$label.'[/url]';
    }

    /** Page publique de l'activité, sur le site des joueurs (jamais le domaine d'administration), par slug. */
    public static function publicUrl(string $slug): string
    {
        return DecodeLinks::page($slug);
    }
}
