<?php

namespace App\Services;

use App\Exceptions\MandateRefusal;
use App\Models\AnimRewardsGrid;
use App\Support\AnimPassword;
use App\Support\RewardsGridLinks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Réinitialisation du mot de passe d'une grille SANS COMPTE. L'auteur ne change jamais son mot de passe librement :
 * il passe toujours par un LIEN À USAGE UNIQUE (24 h) qui lui laisse CHOISIR le nouveau. Aucun mot de passe ne
 * circule — ni par email, ni par l'administrateur du site, qui ne le connaît jamais.
 *
 *  - avec un email CONFIRMÉ : l'auteur déclenche lui-même l'envoi (`requestByAuthor`), TODO email (hors scope).
 *    Pour l'instant `requestByAuthor` marque `reset_requested_at` comme pour le chemin sans email.
 *  - sans email : l'administrateur du site émet le lien (`issueForAdmin`) et le remet AU PSEUDO DU PERSONNAGE VIA LE
 *    JEU — le site n'envoie rien.
 *
 * Le jeton est stocké HACHÉ sur la grille (sha256), jamais en clair ; un nouveau jeton annule le précédent.
 * ⚠️ Aucun email : « Hors scope: Notifications email » pour la Grille des lots pour l'instant.
 */
class RewardsGridPasswordReset
{
    /**
     * L'auteur demande le lien. SANS email (aucun email dans ce module), la demande est notée sur la grille
     * (`reset_requested_at`) et l'administrateur du site la lit dans `/anim` pour faire porter le lien au pseudo du
     * personnage dans le jeu. Sans effet visible si la grille n'existe pas ou est close.
     */
    public function requestByAuthor(string $slug): void
    {
        $grid = $this->find($slug);
        if (! $grid) {
            return;
        }

        // Pas d'email dans ce module : toujours le chemin « noter la demande ».
        $grid->forceFill(['reset_requested_at' => Carbon::now()])->save();
    }

    /**
     * L'administrateur du site émet le lien. Retourne l'adresse du lien et son échéance : à montrer UNE SEULE FOIS.
     *
     * @return array{url: string, expires_at: Carbon}
     *
     * @throws MandateRefusal
     */
    public function issueForAdmin(AnimRewardsGrid $grid): array
    {
        if (! $grid->is_anonymous || $grid->isClosed()) {
            throw MandateRefusal::make('activity', 'anim.api.reset_unavailable');
        }

        $token = $this->issue($grid);

        return ['url' => RewardsGridLinks::reset($grid->slug, $token), 'expires_at' => $grid->reset_token_expires_at];
    }

    /**
     * L'auteur choisit son nouveau mot de passe avec le lien.
     *
     * @throws MandateRefusal
     */
    public function complete(string $slug, string $token, string $password): void
    {
        $grid = $this->find($slug);
        $valid = $grid
            && $grid->reset_token_hash
            && $grid->reset_token_expires_at
            && $grid->reset_token_expires_at->isFuture()
            && hash_equals($grid->reset_token_hash, hash('sha256', $token));

        // Un seul message pour « inconnu », « expiré », « déjà employé » et « faux ».
        if (! $valid) {
            throw MandateRefusal::make('token', 'anim.api.reset_invalid');
        }

        AnimPassword::assertValid($password, $grid->slug, $grid->organizer_name);

        $grid->forceFill([
            'password_hash' => Hash::make($password),
            'reset_token_hash' => null,
            'reset_token_expires_at' => null,
            'password_reset_at' => Carbon::now(),
            'reset_requested_at' => null,
        ])->save();
    }

    private function find(string $slug): ?AnimRewardsGrid
    {
        return AnimRewardsGrid::query()
            ->where('slug', $slug)
            ->where('is_anonymous', true)
            ->where('status', '!=', AnimRewardsGrid::CLOSED)
            ->first();
    }

    private function issue(AnimRewardsGrid $grid): string
    {
        $token = bin2hex(random_bytes(32));
        $grid->forceFill([
            'reset_token_hash' => hash('sha256', $token),
            'reset_token_expires_at' => Carbon::now()->addHours(RewardsGridLinks::RESET_HOURS),
            'reset_mode' => 'admin',
            'reset_requested_at' => null,
        ])->save();

        return $token;
    }
}
