<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use LogicException;
use RuntimeException;

/**
 * Règles de la purge des comptes (/legal/privacy §5 ; brief politique-promesses §2 ; fil
 * admin/echanges/politique-promesses). La commande accounts:purge ne fait qu'appeler ce service.
 *
 * Deux chemins, UNE colonne (users.deletion_notice_sent_at) et UN garde-fou :
 * - compte vérifié inactif : préavis à 11 mois sans passage, suppression à 12 mois et au plus tôt
 *   30 jours après le préavis ;
 * - compte jamais confirmé : rappel à J+23, suppression à J+30 et au plus tôt 7 jours après le rappel.
 *
 * 🔴 AUCUNE SUPPRESSION SANS AVIS ENREGISTRÉ (delete()) : c'est la clause ferme du texte, la seule
 * chose qui sépare « ménage automatique » de « suppression sans prévenir ». AccountPurgeTest échoue
 * si la condition disparaît.
 *
 * ⚠️ Un last_seen_at NUL ne rend JAMAIS un compte vérifié supprimable, ni même prévenu : en SQL,
 * `NULL < date` n'est jamais vrai. Ne jamais écrire `COALESCE(last_seen_at, created_at)` « pour faire
 * propre » : ce serait inverser ce filet en silence (fil, 02).
 */
class AccountPurge
{
    public function __construct(
        private readonly AccountDeletion $deletion,
        private readonly MandateAuthority $authority,
    ) {}

    /** Comptes vérifiés à prévenir : 11 mois sans passage, aucun préavis en cours. */
    public function inactiveToNotify(): Collection
    {
        return User::query()
            ->whereNotNull('email_verified_at')
            ->whereNull('deletion_notice_sent_at')
            ->where('last_seen_at', '<=', Carbon::now()->subDays($this->days('inactivity_days') - $this->days('inactivity_notice_days')))
            ->orderBy('id')->get();
    }

    /** Comptes vérifiés supprimables : 12 mois sans passage, préavis d'au moins 30 jours. */
    public function inactiveToDelete(): Collection
    {
        return User::query()
            ->whereNotNull('email_verified_at')
            ->where('last_seen_at', '<=', Carbon::now()->subDays($this->days('inactivity_days')))
            ->where('deletion_notice_sent_at', '<=', Carbon::now()->subDays($this->days('inactivity_notice_days')))
            ->orderBy('id')->get();
    }

    /** Comptes jamais confirmés à rappeler : J+23, aucun rappel en cours. */
    public function unverifiedToRemind(): Collection
    {
        return User::query()
            ->whereNull('email_verified_at')
            ->whereNull('deletion_notice_sent_at')
            ->where('created_at', '<=', Carbon::now()->subDays($this->days('unverified_reminder_after_days')))
            ->orderBy('id')->get();
    }

    /** Comptes jamais confirmés supprimables : J+30, rappel d'au moins 7 jours. */
    public function unverifiedToDelete(): Collection
    {
        return User::query()
            ->whereNull('email_verified_at')
            ->where('created_at', '<=', Carbon::now()->subDays($this->days('unverified_days')))
            ->where('deletion_notice_sent_at', '<=', Carbon::now()->subDays($this->days('unverified_min_notice_days')))
            ->orderBy('id')->get();
    }

    /** Date à partir de laquelle le compte sera supprimé si rien ne change, l'avis partant maintenant. */
    public function deletionDate(User $user): Carbon
    {
        $now = Carbon::now();

        if ($user->hasVerifiedEmail()) {
            return $user->last_seen_at->copy()->addDays($this->days('inactivity_days'))
                ->max($now->copy()->addDays($this->days('inactivity_notice_days')));
        }

        return $user->created_at->copy()->addDays($this->days('unverified_days'))
            ->max($now->copy()->addDays($this->days('unverified_min_notice_days')));
    }

    /**
     * Pseudos des personnages du compte qui tiennent un mandat EN CE MOMENT (brief §2.6) : la purge
     * le signale et ne fait rien, elle ne décide pas seule. Liste vide = rien ne s'y oppose.
     *
     * @return array<int, string>
     */
    public function charactersInOffice(User $user): array
    {
        return $user->characters()->get()
            ->filter(fn ($character) => $this->authority->activeMayorMandate($character)
                || $this->authority->activeCouncilMandate($character))
            ->pluck('pseudo')->values()->all();
    }

    /**
     * La seule suppression de la purge. Refuse tout compte dont l'avis n'est pas enregistré, ou
     * enregistré depuis moins que le délai promis — quel que soit l'appelant.
     */
    public function delete(User $user): void
    {
        $minDays = $this->days($user->hasVerifiedEmail() ? 'inactivity_notice_days' : 'unverified_min_notice_days');
        $notice = $user->deletion_notice_sent_at;

        if ($notice === null || $notice->greaterThan(Carbon::now()->subDays($minDays))) {
            throw new LogicException("Compte {$user->id} : aucune suppression sans avis enregistré depuis au moins {$minDays} jours.");
        }
        if ($user->hasVerifiedEmail() && ($user->last_seen_at === null
            || $user->last_seen_at->greaterThan(Carbon::now()->subDays($this->days('inactivity_days'))))) {
            throw new LogicException("Compte {$user->id} : pas inactif depuis {$this->days('inactivity_days')} jours.");
        }

        $this->deletion->deleteUser($user);
    }

    /** Enregistre l'avis : à appeler une fois l'email parti, jamais avant. */
    public function recordNotice(User $user): void
    {
        // Hors Eloquent, comme LastSeen : updated_at ne mesure pas l'activité du système.
        $values = ['deletion_notice_sent_at' => Carbon::now()];
        $user->newQuery()->toBase()->where($user->getKeyName(), $user->getKey())->update($values);
        $user->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }

    /**
     * Durées de config/accounts.php, ou une exception — jamais 0 par défaut.
     *
     * 🔴 Incident du 04/10/2026 (prod) : la config était en cache AVANT l'arrivée de
     * config/accounts.php ; chaque durée valait null, donc 0 jour, et TOUT compte vérifié devenait
     * « à prévenir, suppression aujourd'hui ». Seule la simulation imposée (enforce null) a évité
     * l'envoi. Une durée absente ou nulle arrête désormais la purge (fail closed).
     */
    private function days(string $key): int
    {
        $value = config("accounts.{$key}");

        if (! is_int($value) || $value <= 0) {
            throw new RuntimeException(__('accounts.command.misconfigured', ['key' => "accounts.{$key}"]));
        }

        return $value;
    }

    /** Vérifie toute la configuration avant le moindre geste : tout ou rien. */
    public function assertConfigured(): void
    {
        foreach (['inactivity_days', 'inactivity_notice_days', 'unverified_days',
            'unverified_reminder_after_days', 'unverified_min_notice_days'] as $key) {
            $this->days($key);
        }
        if (! is_bool(config('accounts.enforce'))) {
            throw new RuntimeException(__('accounts.command.misconfigured', ['key' => 'accounts.enforce']));
        }
        if ($this->days('inactivity_notice_days') >= $this->days('inactivity_days')
            || $this->days('unverified_reminder_after_days') >= $this->days('unverified_days')) {
            throw new RuntimeException(__('accounts.command.misconfigured', ['key' => 'accounts.*_days (ordre)']));
        }
    }
}
