<?php

namespace App\Console\Commands;

use App\Models\CustomsReport;
use App\Models\CustomsWatchlistEntry;
use App\Support\CustomsPseudo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retire un pseudo du Registre de douane (module Sécurité) : OUTIL INTERNE, hors politique (Greg, 22/11/2026 : la politique n'annonce aucune
 * procédure de retrait ; « si quelqu'un m'écrit, j'utiliserai l'outil »). Lancé À LA MAIN, jamais planifié.
 *
 * - SIMULATION par défaut (comme accounts:purge) : dit ce qui serait touché, n'écrit rien. `--force` applique.
 * - Listes : les entrées de ce pseudo, dans TOUTES les provinces, sont SUPPRIMÉES (un retrait supprime la ligne).
 * - Bilans : le pseudo est REMPLACÉ par « [retiré] » dans l'analyse ET dans le brut chiffré, de tous les bilans, remplacés compris. Le bilan reste.
 * - Comparaison en MINUSCULES (C4). Dans le brut, le pseudo est un MOT ENTIER : il est précédé du début de ligne, d'une espace ou d'une virgule,
 *   et suivi d'une espace, d'une virgule, d'une tabulation, de la fin de ligne — ou d'UN SEUL point final de phrase. Les points font partie des
 *   pseudos : retirer `Gregor.` ne touche jamais `Gregor..`.
 */
class RemoveCustomsPseudo extends Command
{
    public const PLACEHOLDER = '[retiré]';

    protected $signature = 'customs:remove-pseudo {pseudo : le pseudo à retirer (sans espace)} {--force : appliquer (sans cette option : simulation)}';

    protected $description = 'Retire un pseudo des listes et des bilans du Registre de douane (simulation par défaut).';

    public function handle(): int
    {
        $pseudo = trim((string) $this->argument('pseudo'));
        if ($pseudo === '' || preg_match('/\s/u', $pseudo)) {
            $this->error('Un pseudo ne contient jamais d\'espace.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('force');
        $key = CustomsPseudo::key($pseudo);

        $entries = CustomsWatchlistEntry::query()->get()->filter(fn (CustomsWatchlistEntry $entry) => CustomsPseudo::key($entry->pseudo()) === $key);

        $touched = 0;
        $occurrences = 0;
        $work = [];
        foreach (CustomsReport::query()->orderBy('id')->lazyById(100) as $report) {
            [$payload, $count] = $this->scrub($report->payload ?? [], $pseudo, $key);
            if ($count > 0) {
                $touched++;
                $occurrences += $count;
                $work[] = [$report->id, $payload];
            }
        }

        $this->line(($apply ? 'APPLIQUÉ' : 'SIMULATION').' — « '.$pseudo.' » : '.$entries->count().' entrée(s) de liste, '.$touched.' bilan(s), '.$occurrences.' occurrence(s).');

        if ($apply) {
            DB::transaction(function () use ($entries, $work) {
                $entries->each->delete();
                foreach ($work as [$id, $payload]) {
                    $report = CustomsReport::findOrFail($id);
                    $report->payload = $payload;
                    $report->save();
                }
            });
            $this->info('Terminé.');
        } else {
            $this->comment('Aucune écriture : relancer avec --force pour appliquer.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: int} le payload nettoyé et le nombre d'occurrences remplacées (analyse + brut)
     */
    public function scrub(array $payload, string $pseudo, string $key): array
    {
        $count = 0;
        $swap = function (string $value) use ($key, &$count): string {
            if (CustomsPseudo::key($value) === $key) {
                $count++;

                return self::PLACEHOLDER;
            }

            return $value;
        };
        $swapAll = fn (array $values) => array_map($swap, $values);

        foreach ($payload['sections'] ?? [] as $name => $section) {
            $count += $this->scrubRaw($section['raw'] ?? '', $pseudo, $raw);
            $section['raw'] = $raw;
            $report = $section['report'] ?? [];

            if ($name === 'guet') {
                $report['villagers'] = array_map(fn ($v) => ['pseudo' => $swap($v['pseudo'])] + $v, $report['villagers'] ?? []);
            } elseif ($name === 'visions') {
                $report['entries'] = array_map(fn ($e) => [
                    'singles' => $swapAll($e['singles'] ?? []),
                    'groups' => array_map($swapAll, $e['groups'] ?? []),
                ] + $e, $report['entries'] ?? []);
            } elseif ($name === 'armies_groups') {
                $report['armies'] = array_map(fn ($a) => ['commander' => $swap($a['commander'])] + $a, $report['armies'] ?? []);
                $report['groups'] = array_map(fn ($g) => ['members' => $swapAll($g['members'] ?? [])] + $g, $report['groups'] ?? []);
            }

            $section['report'] = $report;
            $payload['sections'][$name] = $section;
        }

        return [$payload, $count];
    }

    /** Remplace le pseudo comme MOT ENTIER dans un texte brut ; renvoie le nombre de remplacements. */
    private function scrubRaw(string $raw, string $pseudo, ?string &$out): int
    {
        $pattern = '/(?<![^\s,])'.preg_quote($pseudo, '/').'(?=[\s,]|\.?$)/imu';
        $out = (string) preg_replace($pattern, self::PLACEHOLDER, $raw, -1, $replaced);

        return $replaced;
    }
}
