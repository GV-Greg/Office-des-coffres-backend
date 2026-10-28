<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Mandats — tâches quotidiennes (admin/content/brief-mandats.md, lot 3 ; fil admin/echanges/mandats-lot3).
|
| ⚠️ PRÉREQUIS EN PROD : O2Switch ne lance rien seul. Ces tâches n'ont d'effet qu'une fois créée,
| dans cPanel → Tâches cron, la ligne :
|     * * * * * cd <chemin du backend> && php artisan schedule:run >> /dev/null 2>&1
| Chaque tâche écrit son passage RÉUSSI (MandateHeartbeat) ; le tableau de bord alerte au-delà de
| 36 h. Aucune n'a d'effet sur l'autorité : l'expiration d'un mandat reste sèche.
*/
Schedule::command('mandates:send-reminders')
    ->dailyAt(config('mandates.schedule_at'))->timezone(config('mandates.timezone'));
Schedule::command('mandates:verification-digest')
    ->dailyAt(config('mandates.schedule_at'))->timezone(config('mandates.timezone'));
Schedule::command('mandates:purge-rejected')
    ->dailyAt(config('mandates.schedule_at'))->timezone(config('mandates.timezone'));

/*
| Journaux — rétention de 180 jours promise par /legal/privacy §5 (config/logging.php). La rotation
| du canal `daily` compte des fichiers, pas des jours : cette purge par date est la vraie garantie.
*/
Schedule::command('logs:prune')
    ->dailyAt(config('mandates.schedule_at'))->timezone(config('mandates.timezone'));

/*
| Comptes — préavis puis suppression des comptes inactifs (1 an) et jamais confirmés (30 jours),
| promis par /legal/privacy §5 (config/accounts.php). Simulation imposée tant que accounts.enforce
| est faux : le texte en ligne doit annoncer la règle avant qu'elle s'applique.
*/
Schedule::command('accounts:purge')
    ->dailyAt(config('mandates.schedule_at'))->timezone(config('mandates.timezone'));

/*
| Registre des mines, lot 2 — résumé quotidien des changements de prix aux occupants des trois postes
| qui les gèrent (Greg, 15/10/2026) : à 15:00, seulement s'il y a eu des changements.
*/
Schedule::command('mines:prices-digest')
    ->dailyAt(config('mandates.price_digest_at'))->timezone(config('mandates.timezone'));

/*
| Animation — Décode le code : fermeture des activités restées 30 jours sans action (brief
| admin/modules/animation/brief-coffres-scelles.md). Une fois par jour, heartbeat `decode`.
*/
Schedule::command('anim:close-inactive')
    ->dailyAt(config('mandates.schedule_at'))->timezone(config('mandates.timezone'));
Schedule::command('anim:purge-closed')
    ->dailyAt(config('mandates.schedule_at'))->timezone(config('mandates.timezone'));
