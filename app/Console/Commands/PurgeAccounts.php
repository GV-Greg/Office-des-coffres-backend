<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\InactiveAccountNotice;
use App\Notifications\UnverifiedAccountReminder;
use App\Services\AccountPurge;
use App\Support\MandateHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Purge des comptes (/legal/privacy §5) : comptes vérifiés inactifs et comptes jamais confirmés.
 * Les règles vivent dans App\Services\AccountPurge ; cette commande les applique, dans cet ordre :
 * suppressions dues, puis avis (un avis ne rend jamais supprimable le jour même).
 *
 * Simulation : --dry-run, ou imposée tant que config('accounts.enforce') est faux — le texte en ligne
 * annonce encore 2 ans (brief politique-promesses §6). Elle liste, n'envoie rien, n'écrit rien.
 *
 * Un compte dont un personnage tient un mandat en cours n'est PAS supprimé : il est signalé dans la
 * sortie et sur /dashboard (brief §2.6, fil Q8), la décision revient à Greg.
 */
class PurgeAccounts extends Command
{
    /** Comptes bloqués par un mandat en cours, lus par le tableau de bord. */
    public const BLOCKED_CACHE_KEY = 'accounts.purge.blocked';

    protected $signature = 'accounts:purge {--dry-run : lister sans rien envoyer ni supprimer}';

    protected $description = 'Prévient puis supprime les comptes inactifs et les comptes jamais confirmés.';

    public function handle(AccountPurge $purge): int
    {
        $simulate = $this->option('dry-run') || ! config('accounts.enforce');
        if (! config('accounts.enforce')) {
            $this->warn(__('accounts.command.not_enforced'));
        } elseif ($simulate) {
            $this->warn(__('accounts.command.dry_run'));
        }

        $rows = [];
        $blocked = [];
        $counts = array_fill_keys(['remind', 'notify', 'delete', 'blocked', 'failed'], 0);

        foreach ($purge->unverifiedToDelete()->concat($purge->inactiveToDelete()) as $user) {
            if ($characters = $purge->charactersInOffice($user)) {
                $blocked[] = ['id' => $user->id, 'email' => $user->email, 'characters' => $characters];
                $rows[] = $this->row('blocked', $user, implode(', ', $characters));
                $counts['blocked']++;

                continue;
            }
            $rows[] = $this->row('delete', $user);
            $counts['delete']++;
            if (! $simulate) {
                $purge->delete($user);
            }
        }

        $notices = $purge->unverifiedToRemind()->map(fn (User $user) => ['remind', $user])
            ->concat($purge->inactiveToNotify()->map(fn (User $user) => ['notify', $user]));

        foreach ($notices as [$action, $user]) {
            $date = $purge->deletionDate($user);
            if (! $simulate && ! $this->send($user, $action === 'remind'
                ? new UnverifiedAccountReminder($date)
                : new InactiveAccountNotice($user->last_seen_at, $date))) {
                $rows[] = $this->row('failed', $user);
                $counts['failed']++;

                continue;
            }
            if (! $simulate) {
                $purge->recordNotice($user); // l'avis n'est enregistré qu'une fois parti
            }
            $rows[] = $this->row($action, $user, __('accounts.command.detail_deletion', ['date' => $date->format('d/m/Y')]));
            $counts[$action]++;
        }

        Cache::forever(self::BLOCKED_CACHE_KEY, $blocked);

        if ($rows !== []) {
            $this->table(__('accounts.command.columns'), $rows);
        }
        $this->info(__('accounts.command.summary', $counts));

        MandateHeartbeat::record('accounts');

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function send(User $user, object $notification): bool
    {
        try {
            $user->notify($notification);

            return true;
        } catch (Throwable $e) {
            // Id seulement, jamais l'adresse. L'avis n'est pas enregistré : il repartira demain, et
            // aucune suppression ne peut avoir lieu sans lui.
            Log::error("accounts:purge : échec d'envoi (user id={$user->id}) — {$e->getMessage()}");

            return false;
        }
    }

    /** @return array<int, string> */
    private function row(string $action, User $user, string $detail = ''): array
    {
        return [__("accounts.command.actions.{$action}"), (string) $user->id, $user->email, $detail];
    }
}
