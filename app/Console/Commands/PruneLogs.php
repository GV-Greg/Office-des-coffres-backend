<?php

namespace App\Console\Commands;

use App\Support\MandateHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Efface les journaux quotidiens plus vieux que la durée de rétention (/legal/privacy §5 : 12 mois
 * maximum ; 180 jours, décision de Greg du 04/10/2026).
 *
 * Pourquoi la rotation du canal `daily` ne suffit pas (vérifié dans
 * Monolog\Handler\RotatingFileHandler::rotate(), 04/10/2026) : elle garde les N derniers FICHIERS,
 * pas les N derniers jours, et ne fait ce ménage qu'à la création d'un nouveau fichier. En
 * LOG_LEVEL=error, un fichier n'existe que les jours où une erreur survient : 180 fichiers peuvent
 * couvrir plusieurs années, et sans erreur rien n'est jamais effacé.
 *
 * La date fait foi par le NOM du fichier (laravel-AAAA-MM-JJ.log, un fichier = les lignes d'un
 * jour), dans le fuseau de l'application, celui de Monolog. La durée est lue dans
 * config/logging.php : une seule source. Tout autre fichier du dossier est ignoré.
 */
class PruneLogs extends Command
{
    protected $signature = 'logs:prune';

    protected $description = 'Supprime les journaux quotidiens plus anciens que la durée de rétention.';

    public function handle(): int
    {
        $path = (string) config('logging.channels.daily.path');
        $days = (int) config('logging.channels.daily.days');
        $base = preg_quote(pathinfo($path, PATHINFO_FILENAME), '/');
        $limit = Carbon::now()->startOfDay()->subDays($days);
        $deleted = 0;

        foreach (glob(dirname($path).'/*.log') ?: [] as $file) {
            if (! preg_match("/^{$base}-(\d{4}-\d{2}-\d{2})\.log$/", basename($file), $match)) {
                continue;
            }
            $date = Carbon::createFromFormat('!Y-m-d', $match[1]);
            if ($date->format('Y-m-d') !== $match[1]) {
                continue; // date impossible : pas un fichier de la rotation
            }
            if ($date->lessThan($limit) && @unlink($file)) {
                $deleted++;
            }
        }

        MandateHeartbeat::record('logs');
        $this->info(__('logs.pruned', ['count' => $deleted, 'days' => $days]));

        return self::SUCCESS;
    }
}
