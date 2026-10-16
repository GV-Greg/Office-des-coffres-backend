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
 * Notifie les modifications substantielles de la politique (/legal/privacy §10 ; brief
 * politique-promesses §4). Lancée À LA MAIN par Greg — jamais planifiée, jamais déclenchée par un
 * diff : « substantielle » est une décision humaine, prise dans resources/policy/changelog.php.
 *
 * 🔴 UN SEUL EMAIL PAR JOUR (Greg, 16/10/2026) : par défaut, la commande regroupe toutes les entrées
 * substantielles non envoyées d'un jour (`--day=AAAA-MM-JJ`, aujourd'hui à Paris sans argument) dans
 * un email unique. Des identifiants en argument désignent des entrées précises (même regroupement).
 * Un second envoi le même jour est refusé sans `--again`.
 *
 * Refuse : une entrée inconnue, mal formée, non substantielle (désignée par son id), ou déjà envoyée.
 * Destinataires : les comptes à l'email VÉRIFIÉ seulement (§4.2). Un envoi groupé est irréversible :
 * la commande affiche le nombre de destinataires et demande confirmation (--force pour s'en passer).
 */
class NotifyPolicyUpdate extends Command
{
    protected $signature = 'policy:notify {entries?* : identifiants des entrées du journal (sinon toutes celles du jour)} {--day= : jour AAAA-MM-JJ à notifier (défaut : aujourd\'hui, Paris)} {--force : envoyer sans demander confirmation} {--again : autoriser un second envoi le même jour}';

    protected $description = 'Envoie par email, en un seul message, les modifications substantielles de la politique de confidentialité d\'un jour.';

    public function handle(PolicyChangelog $changelog): int
    {
        $ids = (array) $this->argument('entries');
        $explicit = $ids !== [];

        if ($explicit && $this->option('day') !== null) {
            return $this->refuse(__('policy.command.ids_and_day'));
        }

        if (! $explicit) {
            $day = $this->option('day') ?? Carbon::now('Europe/Paris')->toDateString();
            if (! is_string($day) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                return $this->refuse(__('policy.command.bad_day', ['day' => (string) $this->option('day')]));
            }
            $ids = collect($changelog->all())->filter(fn ($e, $id) => ($e['date'] ?? null) === $day)->keys()->all();
            if ($ids === []) {
                return $this->refuse(__('policy.command.nothing_for_day', ['day' => $day]));
            }
        }

        $entries = [];
        foreach ($ids as $id) {
            $entry = $changelog->find($id);
            if ($entry === null) {
                return $this->refuse(__('policy.command.unknown', ['id' => $id]));
            }
            if ($problems = PolicyChangelog::problems($entry)) {
                return $this->refuse(__('policy.command.malformed', ['id' => $id, 'fields' => implode(', ', $problems)]));
            }
            $sent = DB::table('policy_notifications')->where('entry_id', $id)->first();

            if (! $entry['substantial']) {
                if ($explicit) {
                    return $this->refuse(__('policy.command.not_substantial', ['id' => $id]));
                }
                $this->line(__('policy.command.skipped_not_substantial', ['id' => $id]));

                continue;
            }
            if ($sent) {
                if ($explicit) {
                    return $this->refuse(__('policy.command.already_sent', ['id' => $id, 'date' => $sent->sent_at]));
                }
                $this->line(__('policy.command.skipped_already_sent', ['id' => $id, 'date' => $sent->sent_at]));

                continue;
            }
            $entries[$id] = $entry;
        }

        if ($entries === []) {
            return $this->refuse(__('policy.command.nothing_to_send'));
        }

        $dates = collect($entries)->pluck('date')->unique();
        if ($dates->count() > 1) {
            return $this->refuse(__('policy.command.several_days', ['days' => $dates->implode(', ')]));
        }

        $todayStart = Carbon::now('Europe/Paris')->startOfDay()->utc();
        if (! $this->option('again') && DB::table('policy_notifications')->where('sent_at', '>=', $todayStart)->exists()) {
            return $this->refuse(__('policy.command.already_today'));
        }

        $recipients = User::query()->whereNotNull('email_verified_at')->orderBy('id')->get();

        if ($recipients->isEmpty()) {
            return $this->refuse(__('policy.command.no_recipient'));
        }

        foreach ($entries as $id => $entry) {
            $this->line(__('policy.command.summary', ['id' => $id, 'date' => $entry['date'], 'summary' => $entry['summary']['fr']]));
        }
        $this->line(__('policy.command.recipients', ['count' => $recipients->count()]));

        if (! $this->option('force') && ! $this->confirm(__('policy.command.confirm'))) {
            $this->warn(__('policy.command.cancelled'));

            return self::FAILURE;
        }

        $list = array_values($entries);
        $failures = 0;
        foreach ($recipients as $user) {
            try {
                $user->notify(new PolicyUpdated($list[0], array_slice($list, 1)));
            } catch (Throwable $e) {
                $failures++;
                // Id seulement, jamais l'adresse : une trace d'exploitation n'a pas à en porter.
                Log::error('policy:notify '.implode(', ', array_keys($entries))." : échec d'envoi (user id={$user->id}) — {$e->getMessage()}");
            }
        }

        // Écrites même en cas d'échec partiel : un second lancement renverrait à ceux qui l'ont reçu.
        $now = Carbon::now();
        foreach (array_keys($entries) as $id) {
            DB::table('policy_notifications')->insert([
                'entry_id' => $id,
                'recipients' => $recipients->count(),
                'failures' => $failures,
                'sent_at' => $now,
            ]);
        }

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
