<?php

namespace App\Console\Commands;

use App\Support\MandateHeartbeat;
use Illuminate\Console\Command;

/**
 * Lecture seule : dernier passage réussi de chaque tâche des mandats. Vérification PONCTUELLE après
 * la création du cron en prod ; le garde-fou permanent est l'alerte du tableau de bord.
 */
class MandateStatus extends Command
{
    protected $signature = 'mandates:status';

    protected $description = 'Affiche le dernier passage réussi de chaque tâche planifiée des mandats.';

    public function handle(): int
    {
        $stale = MandateHeartbeat::stale();

        $this->table(['Tâche', 'Commande', 'Dernier passage réussi', 'État'], collect(MandateHeartbeat::TASKS)
            ->map(fn (string $command, string $task) => [
                $task,
                $command,
                MandateHeartbeat::lastSuccess($task)?->setTimezone(config('mandates.timezone'))->format('d/m/Y H:i') ?? 'jamais',
                array_key_exists($task, $stale) ? 'EN RETARD' : 'ok',
            ])->values()->all());

        return $stale === [] ? self::SUCCESS : self::FAILURE;
    }
}
