<?php

namespace App\Console\Commands;

use App\Models\CouncilOfficePeriod;
use App\Support\OfficeStart;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Recale le début des périodes de poste inscrites AVEC un mandat déjà en fonction : elles commençaient à la date de
 * la validation du mandat (Greg, 16/10/2026 : « la validation du mandat ne doit pas rentrer en compte sur le poste occupé
 * par le personnage »), elles commencent désormais à l'entrée en fonction du mandat (App\Support\OfficeStart).
 *
 * Seules sont touchées les PREMIÈRES périodes d'un mandat, nées à la validation du mandat (début à moins de 5 minutes de
 * `processed_at`) et postérieures à son entrée en fonction. Jamais une nomination faite après coup (déclaration, geste
 * de l'administration) : celle-là commence bien « maintenant ». Et jamais si un autre mandat a tenu ce poste dans
 * l'intervalle (exclusivité du poste).
 *
 * Simulation par défaut ; `--force` pour écrire. Idempotente : relancée, elle ne change plus rien.
 */
class RealignOfficeStarts extends Command
{
    protected $signature = 'mandates:realign-office-starts {--force : écrire (sans cette option, simulation)}';

    protected $description = 'Recale le début des postes inscrits avec un mandat sur son entrée en fonction (validation du mandat sans effet sur le poste).';

    private const SAME_GESTURE_SECONDS = 300;

    public function handle(): int
    {
        $rows = [];

        CouncilOfficePeriod::query()->with('mandate.character', 'office')->orderBy('id')->get()
            ->each(function (CouncilOfficePeriod $period) use (&$rows) {
                $mandate = $period->mandate;
                if (! $mandate || ! $mandate->in_office_from || ! $mandate->processed_at) {
                    return;
                }
                $first = $mandate->officePeriods()->orderBy('started_at')->orderBy('id')->first();
                $bornWithMandate = abs($period->started_at->diffInSeconds($mandate->processed_at, false)) <= self::SAME_GESTURE_SECONDS;
                if ($first?->id !== $period->id || ! $bornWithMandate || ! $mandate->in_office_from->lessThan($period->started_at)) {
                    return;
                }
                $free = OfficeStart::isFree($period->council_office_id, (int) $mandate->province_id, $mandate->id,
                    Carbon::instance($mandate->in_office_from), Carbon::instance($period->started_at));

                $rows[] = ['period' => $period, 'to' => $mandate->in_office_from, 'free' => $free];
            });

        if ($rows === []) {
            $this->info('Aucun poste à recaler : tous commencent déjà à l\'entrée en fonction de leur mandat (ou à une nomination postérieure).');

            return self::SUCCESS;
        }

        // ->copy() : setTimezone() modifie l'objet, et `to` est ensuite ÉCRIT en base (UTC) — sans copie, un décalage de 2 h.
        $this->table(['Période', 'Personnage', 'Poste', 'Début actuel', 'Nouveau début', 'Décision'], collect($rows)->map(fn (array $r) => [
            $r['period']->id,
            $r['period']->mandate->character?->pseudo,
            $r['period']->office?->key,
            $r['period']->started_at->copy()->setTimezone(config('mandates.timezone'))->format('d/m/Y H:i'),
            $r['to']->copy()->setTimezone(config('mandates.timezone'))->format('d/m/Y H:i'),
            $r['free'] ? 'recalé' : 'ignoré (un autre mandat a tenu ce poste)',
        ])->all());

        $todo = collect($rows)->where('free', true);
        if (! $this->option('force')) {
            $this->warn("Simulation : {$todo->count()} poste(s) seraient recalés. Relancer avec --force pour écrire.");

            return self::SUCCESS;
        }

        $todo->each(fn (array $r) => $r['period']->forceFill(['started_at' => $r['to']])->save());
        $this->info("{$todo->count()} poste(s) recalé(s).");

        return self::SUCCESS;
    }
}
