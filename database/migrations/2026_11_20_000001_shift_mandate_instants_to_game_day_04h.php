<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Le jour du jeu commence à 04:00 heure de Paris, pas à minuit (constat de Greg, 20/11/2026 ; fil
     * `mandats-heure-bascule`, validé par Cowork). Les instants calculés par l'ANCIENNE règle tombaient à 00:00
     * heure de Paris (début, fin nominale, fin effective, bornes des périodes de poste) : ils passent à 04:00 le
     * même jour. Exemple : le mandat de Buldo, fin le 20/11 à 00:00 (19/11 23:00 UTC), finit le 20/11 à 04:00
     * (03:00 UTC).
     *
     * 🔴 N'est décalée que la valeur dont l'heure locale Paris est EXACTEMENT 00:00:00 : une fin posée à la main à une
     * autre heure (ex. 17:12:26) ne bouge pas, et un second passage ne touche rien (04:00 n'est plus minuit). Le calcul
     * se fait en heure locale Paris (`setTime(4, 0)`), donc sûr aux changements d'heure. L'heure de bascule est écrite
     * ICI en dur : une migration est figée, elle ne doit pas suivre une constante qui changerait un jour.
     *
     * ⚠️ `down()` est SANS EFFET : on ne peut pas distinguer une valeur d'origine à 04:00 d'une valeur migrée.
     * ⚠️ Prod : le déploiement ne lance pas les migrations — `php artisan migrate` à la main, après le déploiement.
     */
    private const TABLES = [
        'mayor_mandates' => ['valid_until', 'in_office_from', 'holds_until'],
        'council_mandates' => ['valid_until', 'in_office_from', 'holds_until'],
        'council_office_periods' => ['started_at', 'ended_at'],
        'office_history_archive' => ['started_at', 'ended_at'],
    ];

    private const TIMEZONE = 'Europe/Paris';

    private const GAME_DAY_STARTS_AT_HOUR = 4;

    public function up(): void
    {
        foreach (self::TABLES as $table => $columns) {
            foreach ($columns as $column) {
                $rows = DB::table($table)->whereNotNull($column)->select('id', $column)->orderBy('id')->get();

                foreach ($rows as $row) {
                    $local = Carbon::createFromFormat('Y-m-d H:i:s', $row->{$column}, 'UTC')->setTimezone(self::TIMEZONE);

                    if ($local->format('H:i:s') !== '00:00:00') {
                        continue;
                    }

                    $shifted = $local->copy()->setTime(self::GAME_DAY_STARTS_AT_HOUR, 0)->setTimezone('UTC');
                    DB::table($table)->where('id', $row->id)->update([$column => $shifted->format('Y-m-d H:i:s')]);
                }
            }
        }
    }

    public function down(): void
    {
        // Volontairement sans effet : voir le commentaire de la classe.
    }
};
