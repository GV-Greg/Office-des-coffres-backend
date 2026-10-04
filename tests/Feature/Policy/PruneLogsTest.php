<?php

use App\Support\MandateHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

// Rétention des journaux par DATE (/legal/privacy §5 : 12 mois maximum ; 180 jours). La rotation
// du canal `daily` garde les 180 derniers FICHIERS, pas les 180 derniers jours, et seulement quand
// un nouveau fichier naît (Monolog RotatingFileHandler::rotate()) : logs:prune est la garantie.

beforeEach(function () {
    $this->dir = storage_path('framework/testing/logs-'.uniqid());
    File::ensureDirectoryExists($this->dir);
    config(['logging.channels.daily.path' => $this->dir.'/laravel.log']);
    Carbon::setTestNow('2026-10-04 10:00:00');
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    Carbon::setTestNow();
});

function logFiles(string $dir): array
{
    return collect(File::files($dir))->map->getFilename()->sort()->values()->all();
}

test('efface les journaux de plus de 180 jours, garde la limite et les récents', function () {
    // 2026-10-04 − 180 jours = 2026-04-07
    foreach (['2026-04-05', '2026-04-06', '2026-04-07', '2026-10-03', '2026-10-04'] as $day) {
        touch("{$this->dir}/laravel-{$day}.log");
    }

    $this->artisan('logs:prune')->assertSuccessful();

    expect(logFiles($this->dir))->toBe(['laravel-2026-04-07.log', 'laravel-2026-10-03.log', 'laravel-2026-10-04.log']);
});

test('des journaux clairsemés sur plus d\'un an sont effacés — ce que la rotation seule laisse passer', function () {
    // Une erreur tous les trois jours : 180 fichiers couvrent ~18 mois, la rotation n'efface rien.
    $day = Carbon::parse('2026-10-04');
    for ($i = 0; $i < 180; $i++, $day->subDays(3)) {
        touch("{$this->dir}/laravel-{$day->format('Y-m-d')}.log");
    }
    // Contrôle : le plus ancien a ~18 mois (179 × 3 jours), bien au-delà de la promesse.
    expect(logFiles($this->dir)[0])->toBe('laravel-'.Carbon::parse('2026-10-04')->subDays(537)->format('Y-m-d').'.log');

    $this->artisan('logs:prune')->assertSuccessful();

    // Restent les fichiers des 180 derniers jours : i × 3 ≤ 180, soit i = 0 … 60.
    expect(logFiles($this->dir)[0])->toBe('laravel-2026-04-07.log')
        ->and(logFiles($this->dir))->toHaveCount(61);
});

test('la durée vient de config/logging.php, seule source', function () {
    config(['logging.channels.daily.days' => 30]);
    touch("{$this->dir}/laravel-2026-09-03.log"); // 31 jours
    touch("{$this->dir}/laravel-2026-09-04.log"); // 30 jours

    $this->artisan('logs:prune')->assertSuccessful();

    expect(logFiles($this->dir))->toBe(['laravel-2026-09-04.log']);
});

test('tout fichier hors du motif de la rotation est ignoré', function () {
    foreach (['laravel.log', 'laravel-2020-02-30.log', 'autre-2020-01-01.log', 'laravel-2020-01-01.log.bak'] as $name) {
        touch("{$this->dir}/{$name}");
    }

    $this->artisan('logs:prune')->assertSuccessful();

    expect(logFiles($this->dir))->toHaveCount(4);
});

test('chaque passage réussi est enregistré, et la tâche a son libellé dans l\'alerte', function () {
    $this->artisan('logs:prune')->assertSuccessful();

    expect(MandateHeartbeat::lastSuccess('logs'))->not->toBeNull()
        ->and(__('mandates.scheduler.tasks.logs'))->not->toBe('mandates.scheduler.tasks.logs');
});
