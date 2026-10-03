<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Horodatage du dernier passage RÉUSSI de chaque tâche quotidienne des mandats (fil
 * admin/echanges/mandats-lot3, Q5 et Q6).
 *
 * Pourquoi il existe : une file vide n'envoie aucun email — c'est voulu — mais le silence devient
 * alors ambigu : « rien à signaler » ou « le planificateur est mort ». L'horodatage, écrit EN FIN
 * d'exécution réussie, lève l'ambiguïté : une tâche jamais lancée (cron mort) ou qui échoue en
 * cours de route n'écrit rien, et le tableau de bord alerte au-delà de heartbeat_alert_hours.
 *
 * ⚠️ En cache (`file` en prod) : un `php artisan cache:clear` l'efface, et l'alerte s'affiche alors
 * « aucun passage enregistré » jusqu'au prochain passage (au plus 24 h). Fausse alarme connue, du
 * bon côté : une alerte de trop vaut mieux qu'un silence de trop.
 */
class MandateHeartbeat
{
    /** Les trois tâches, et la commande qui écrit chacune. */
    public const TASKS = [
        'reminders' => 'mandates:send-reminders',
        'verification' => 'mandates:verification-digest',
        'purge' => 'mandates:purge-rejected',
    ];

    public static function record(string $task): void
    {
        Cache::forever(self::key($task), Carbon::now()->toIso8601String());
    }

    public static function lastSuccess(string $task): ?Carbon
    {
        $value = Cache::get(self::key($task));

        return $value ? Carbon::parse($value) : null;
    }

    /**
     * Tâches en retard : jamais passées, ou pas depuis heartbeat_alert_hours.
     *
     * @return array<string, ?Carbon> tâche => dernier passage réussi (null = jamais)
     */
    public static function stale(): array
    {
        $limit = Carbon::now()->subHours(config('mandates.heartbeat_alert_hours'));

        return collect(array_keys(self::TASKS))
            ->mapWithKeys(fn (string $task) => [$task => self::lastSuccess($task)])
            ->filter(fn (?Carbon $last) => $last === null || $last->lessThan($limit))
            ->all();
    }

    private static function key(string $task): string
    {
        return "mandates.heartbeat.{$task}";
    }
}
