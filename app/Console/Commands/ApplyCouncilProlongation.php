<?php

namespace App\Console\Commands;

use App\Models\CouncilMandate;
use App\Services\MandateWorkflow;
use App\Support\MandateCalendar;
use App\Support\MandateLabels;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Applique la nouvelle attente du successeur (27/11/2026 : un conseiller reste en poste jusqu'à son successeur,
 * `council_grace_days` au plus) aux mandats de conseil ENCORE EN COURS, dont la fin effective avait été fixée par l'ancienne
 * règle. Un mandat déjà terminé ne revit pas ; une fin fixée par une passation ou une révocation ne bouge pas ; une fin
 * n'est jamais raccourcie.
 *
 * Simulation par défaut ; `--force` pour écrire. Idempotente : relancée, elle ne change plus rien. À lancer une fois, après
 * `config:cache`.
 */
class ApplyCouncilProlongation extends Command
{
    protected $signature = 'mandates:apply-council-prolongation {--force : écrire (sans cette option, simulation)}';

    protected $description = 'Repousse la fin effective des mandats de conseil en cours selon la nouvelle attente du successeur.';

    public function handle(MandateWorkflow $workflow): int
    {
        $rows = CouncilMandate::running()
            ->whereIn('holds_until_set_by', ['nominal', 'extension'])
            ->with('character', 'province')
            ->orderBy('id')
            ->get()
            ->map(fn (CouncilMandate $mandate) => [
                'mandate' => $mandate,
                'to' => MandateCalendar::nominalHoldsUntil($mandate::LEVEL, $mandate->valid_until),
            ])
            ->filter(fn (array $row) => $row['to']->greaterThan($row['mandate']->holds_until));

        if ($rows->isEmpty()) {
            $this->info('Aucun mandat de conseil à ajuster.');

            return self::SUCCESS;
        }

        $this->table(['Mandat', 'Personnage', 'Province', 'Fin actuelle', 'Nouvelle fin'], $rows->map(fn (array $row) => [
            $row['mandate']->id,
            $row['mandate']->character?->pseudo,
            $row['mandate']->province?->province_name,
            MandateLabels::date($row['mandate']->holds_until),
            MandateLabels::date(Carbon::instance($row['to'])),
        ])->all());

        if (! $this->option('force')) {
            $this->warn("Simulation : {$rows->count()} mandat(s) seraient ajustés. Relancer avec --force pour écrire.");

            return self::SUCCESS;
        }

        $rows->each(fn (array $row) => $workflow->setHoldsUntil($row['mandate'], $row['to'], 'nominal'));
        $this->info("{$rows->count()} mandat(s) ajusté(s).");

        return self::SUCCESS;
    }
}
