<?php

namespace App\Console\Commands;

use App\Models\CouncilMandate;
use App\Services\MandateWorkflow;
use App\Support\MandateCalendar;
use App\Support\MandateLabels;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Remet à sa valeur normale (terme + délai d'élection) la fin enregistrée des mandats de conseil écrits avec une fin
 * repoussée de 30 jours (27/11/2026) : l'attente du successeur ne s'écrit pas, elle s'applique à la lecture des droits.
 * Ne touche que les fins naturelles plus longues que la normale ; jamais une passation ni une révocation.
 *
 * Simulation par défaut ; `--force` pour écrire. Idempotente. À lancer une fois, APRÈS `config:cache`.
 */
class RestoreCouncilEnd extends Command
{
    protected $signature = 'mandates:restore-council-end {--force : écrire (sans cette option, simulation)}';

    protected $description = 'Remet à la normale la fin enregistrée des mandats de conseil repoussée de 30 jours.';

    public function handle(MandateWorkflow $workflow): int
    {
        // Avec l'ancienne configuration encore en cache, la « normale » vaudrait elle-même 30 jours.
        if (config('mandates.council_waiting_days') === null) {
            $this->error('Configuration pas à jour (mandates.council_waiting_days) : lancer php artisan config:cache.');

            return self::FAILURE;
        }

        $rows = CouncilMandate::query()
            ->whereNotNull('valid_until')
            ->where('holds_until_set_by', 'nominal')
            ->with('character', 'province')
            ->orderBy('id')
            ->get()
            ->map(fn (CouncilMandate $mandate) => [
                'mandate' => $mandate,
                'to' => MandateCalendar::nominalHoldsUntil($mandate::LEVEL, $mandate->valid_until),
            ])
            ->filter(fn (array $row) => $row['mandate']->holds_until->greaterThan($row['to']));

        if ($rows->isEmpty()) {
            $this->info('Aucun mandat de conseil à remettre à la normale.');

            return self::SUCCESS;
        }

        $this->table(['Mandat', 'Personnage', 'Province', 'Fin actuelle', 'Fin normale'], $rows->map(fn (array $row) => [
            $row['mandate']->id,
            $row['mandate']->character?->pseudo,
            $row['mandate']->province?->province_name,
            MandateLabels::date($row['mandate']->holds_until),
            MandateLabels::date(Carbon::instance($row['to'])),
        ])->all());

        if (! $this->option('force')) {
            $this->warn("Simulation : {$rows->count()} mandat(s) seraient remis à la normale. Relancer avec --force pour écrire.");

            return self::SUCCESS;
        }

        $rows->each(fn (array $row) => $workflow->setHoldsUntil($row['mandate'], $row['to'], 'nominal'));
        $this->info("{$rows->count()} mandat(s) remis à la normale.");

        return self::SUCCESS;
    }
}
