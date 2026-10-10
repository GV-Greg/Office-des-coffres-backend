<?php

namespace App\Console\Commands;

use App\Models\MineReport;
use App\Services\MineRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Redate les relevés du Registre des mines inscrits avant le 10/10/2026, tous datés du jour du collage :
 * un ancien collage prenait la date du jour et REMPLAÇAIT le relevé du jour (Greg, 10/10 : historique
 * des niveaux à l'envers). Nouvelle date = dernier jour présent dans les données (MineRegistry::reportDate).
 *
 * Puis, par province et par date : le relevé le plus récemment inscrit est EN VIGUEUR, les autres sont
 * remplacés par le suivant (ajout seul tenu : rien ne s'efface). Un relevé à tort « remplacé » parce
 * qu'il tombait le même jour qu'un autre redevient en vigueur s'il est désormais seul à sa date.
 *
 * Simulation par défaut ; `--force` pour écrire. Idempotente : relancée, elle ne change plus rien.
 */
class RedateMineReports extends Command
{
    protected $signature = 'mines:redate {--force : écrire (sans cette option, simulation)}';

    protected $description = 'Redate les relevés du Registre des mines d\'après leurs données, et recalcule ceux en vigueur.';

    public function handle(): int
    {
        $today = Carbon::now('Europe/Paris')->toDateString();
        $reports = MineReport::query()->orderBy('created_at')->orderBy('id')->get();

        $plan = $reports->map(function (MineReport $r) use ($today) {
            $date = MineRegistry::reportDate($r->payload['report'] ?? []);

            return ['report' => $r, 'date' => $date > $today ? $r->reported_at->toDateString() : $date];
        });

        // Par province et date, dans l'ordre d'inscription : le dernier est en vigueur.
        $final = [];
        foreach ($plan->groupBy(fn ($p) => $p['report']->province_id.'|'.$p['date']) as $group) {
            $group = $group->values();
            foreach ($group as $i => $p) {
                $next = $group[$i + 1]['report'] ?? null;
                $final[$p['report']->id] = [
                    'reported_at' => $p['date'],
                    'active' => $next ? null : true,
                    'replaced_by_id' => $next?->id,
                    'replaced_at' => $next?->created_at,
                ];
            }
        }

        $changes = $reports->filter(fn (MineReport $r) => $r->reported_at->toDateString() !== $final[$r->id]['reported_at']
            || (bool) $r->active !== (bool) $final[$r->id]['active']
            || $r->replaced_by_id !== $final[$r->id]['replaced_by_id']);

        if ($changes->isEmpty()) {
            $this->info(__('mines.redate.nothing'));

            return self::SUCCESS;
        }

        $this->table(
            [__('mines.redate.id'), __('mines.redate.from'), __('mines.redate.to'), __('mines.redate.state')],
            $changes->map(fn (MineReport $r) => [
                $r->id,
                $r->reported_at->toDateString(),
                $final[$r->id]['reported_at'],
                ($r->active ? __('mines.redate.active') : __('mines.redate.replaced')).' → '
                    .($final[$r->id]['active'] ? __('mines.redate.active') : __('mines.redate.replaced')),
            ])->values()->all(),
        );

        if (! $this->option('force')) {
            $this->warn(__('mines.redate.dry_run', ['count' => $changes->count()]));

            return self::SUCCESS;
        }

        DB::transaction(function () use ($reports, $final) {
            // D'abord tout hors vigueur : l'index unique (province, date, active) ne doit jamais voir deux
            // relevés en vigueur à la même date pendant la réécriture.
            MineReport::whereIn('id', $reports->pluck('id'))->update(['active' => null]);
            foreach ($final as $id => $values) {
                MineReport::whereKey($id)->update($values);
            }
        });

        $this->info(__('mines.redate.done', ['count' => $changes->count()]));

        return self::SUCCESS;
    }
}
