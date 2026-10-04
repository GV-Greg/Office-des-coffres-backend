<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\PolicyUpdated;
use App\Support\PolicyChangelog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifie une modification substantielle de la politique (/legal/privacy §10 ; brief
 * politique-promesses §4). Lancée À LA MAIN par Greg — jamais planifiée, jamais déclenchée par un
 * diff : « substantielle » est une décision humaine, prise dans resources/policy/changelog.php.
 *
 * Refuse : une entrée inconnue, mal formée, non substantielle, ou déjà envoyée. Destinataires : les
 * comptes à l'email VÉRIFIÉ seulement (§4.2). Un envoi groupé est irréversible : la commande
 * affiche le nombre de destinataires et demande confirmation (--force pour s'en passer).
 */
class NotifyPolicyUpdate extends Command
{
    protected $signature = 'policy:notify {entry : identifiant de l\'entrée du journal} {--force : envoyer sans demander confirmation}';

    protected $description = 'Envoie par email une modification substantielle de la politique de confidentialité.';

    public function handle(PolicyChangelog $changelog): int
    {
        $id = (string) $this->argument('entry');
        $entry = $changelog->find($id);

        if ($entry === null) {
            return $this->refuse(__('policy.command.unknown', ['id' => $id]));
        }
        if ($problems = PolicyChangelog::problems($entry)) {
            return $this->refuse(__('policy.command.malformed', ['id' => $id, 'fields' => implode(', ', $problems)]));
        }
        if (! $entry['substantial']) {
            return $this->refuse(__('policy.command.not_substantial', ['id' => $id]));
        }
        if ($sent = DB::table('policy_notifications')->where('entry_id', $id)->first()) {
            return $this->refuse(__('policy.command.already_sent', ['id' => $id, 'date' => $sent->sent_at]));
        }

        $recipients = User::query()->whereNotNull('email_verified_at')->orderBy('id')->get();

        if ($recipients->isEmpty()) {
            return $this->refuse(__('policy.command.no_recipient'));
        }

        $this->line(__('policy.command.summary', ['id' => $id, 'date' => $entry['date'], 'summary' => $entry['summary']['fr']]));
        $this->line(__('policy.command.recipients', ['count' => $recipients->count()]));

        if (! $this->option('force') && ! $this->confirm(__('policy.command.confirm'))) {
            $this->warn(__('policy.command.cancelled'));

            return self::FAILURE;
        }

        $failures = 0;
        foreach ($recipients as $user) {
            try {
                $user->notify(new PolicyUpdated($entry));
            } catch (Throwable $e) {
                $failures++;
                // Id seulement, jamais l'adresse : une trace d'exploitation n'a pas à en porter.
                Log::error("policy:notify {$id} : échec d'envoi (user id={$user->id}) — {$e->getMessage()}");
            }
        }

        // Écrite même en cas d'échec partiel : un second lancement renverrait à ceux qui l'ont reçu.
        DB::table('policy_notifications')->insert([
            'entry_id' => $id,
            'recipients' => $recipients->count(),
            'failures' => $failures,
            'sent_at' => Carbon::now(),
        ]);

        if ($failures > 0) {
            $this->error(__('policy.command.partial', ['sent' => $recipients->count() - $failures, 'failures' => $failures]));

            return self::FAILURE;
        }

        $this->info(__('policy.command.done', ['count' => $recipients->count()]));

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
