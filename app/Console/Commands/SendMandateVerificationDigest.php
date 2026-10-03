<?php

namespace App\Console\Commands;

use App\Http\Controllers\Web\MandateAdminController;
use App\Models\User;
use App\Notifications\MandateVerificationDigest;
use App\Support\MandateHeartbeat;
use Illuminate\Console\Command;

/**
 * Email quotidien de la file de vérification (fil mandats-lot1, tour 05 ; fil mandats-lot3, Q2-Q3) :
 * un email par jour TANT QUE la file contient des lignes en retard, avec toutes ces lignes et leur
 * ancienneté. Une file sans retard n'envoie rien. Destinataires : tous les comptes `admin`.
 *
 * ⚠️ Conséquence écrite au fil lot 3 (Q2) : cet email contient des pseudos et des postes. Donner le
 * rôle `admin` à un modérateur lui donnera aussi cette file — une décision à prendre ce jour-là.
 */
class SendMandateVerificationDigest extends Command
{
    protected $signature = 'mandates:verification-digest';

    protected $description = 'Envoie aux administrateurs la liste des mandats terminés non vérifiés au-delà du seuil.';

    public function handle(): int
    {
        $overdue = app(MandateAdminController::class)->verificationQueue()
            ->filter(fn (array $item) => $item['overdue'])
            ->values();

        if ($overdue->isNotEmpty()) {
            User::role('admin')->get()->each(fn (User $admin) => $admin->notify(new MandateVerificationDigest($overdue)));
        }

        MandateHeartbeat::record('verification');
        $this->info($overdue->count().' ligne(s) en retard.');

        return self::SUCCESS;
    }
}
