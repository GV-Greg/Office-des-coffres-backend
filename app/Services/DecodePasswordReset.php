<?php

namespace App\Services;

use App\Exceptions\MandateRefusal;
use App\Models\AnimDecodeActivity;
use App\Support\DecodeLinks;
use App\Support\DecodePassword;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Réinitialisation du mot de passe d'une activité SANS COMPTE (D5, D11, D35, D36, D37). L'auteur ne change jamais son
 * mot de passe librement : il passe toujours par un LIEN À USAGE UNIQUE (24 h) qui lui laisse CHOISIR le nouveau. Aucun
 * mot de passe ne circule — ni par email, ni par l'administrateur, qui ne le connaît jamais.
 *
 *  - avec un email CONFIRMÉ : l'auteur déclenche lui-même l'envoi (`requestByAuthor`), le lien part à l'adresse ;
 *  - sans email : l'administrateur émet le lien (`issueForAdmin`) et le remet AU PSEUDO DU PERSONNAGE VIA LE JEU — le site
 *    n'envoie rien (D36, D38 : l'envoi au personnage est la vérification d'identité).
 *
 * Le jeton est stocké HACHÉ sur l'activité (sha256), jamais en clair ; un nouveau jeton annule le précédent.
 */
class DecodePasswordReset
{
    public function __construct(private readonly DecodeMailer $mailer) {}

    /**
     * L'auteur demande le lien. Avec un email CONFIRMÉ, le lien part à l'adresse ; SANS email confirmé, la demande est notée sur l'activité
     * (`reset_requested_at`) et l'administration la lit dans `/anim` pour faire porter le lien au pseudo du personnage, dans le jeu (D36).
     * Sans effet visible si l'activité n'existe pas ou est close : la réponse est la même dans tous les cas.
     */
    public function requestByAuthor(string $slug): void
    {
        $activity = $this->find($slug);
        if (! $activity) {
            return;
        }

        if (! $activity->hasVerifiedEmail()) {
            $activity->forceFill(['reset_requested_at' => Carbon::now()])->save();

            return;
        }

        $token = $this->issue($activity, 'email');
        $this->mailer->sendResetLink($activity, $token);
    }

    /**
     * L'administrateur émet le lien (auteur sans email). Retourne l'adresse du lien et son échéance : à montrer UNE SEULE FOIS.
     *
     * @return array{url: string, expires_at: Carbon}
     *
     * @throws MandateRefusal
     */
    public function issueForAdmin(AnimDecodeActivity $activity): array
    {
        if (! $activity->is_anonymous || $activity->isClosed()) {
            throw MandateRefusal::make('activity', 'anim.api.reset_unavailable');
        }

        $token = $this->issue($activity, 'admin');

        return ['url' => DecodeLinks::reset($activity->slug, $token), 'expires_at' => $activity->reset_token_expires_at];
    }

    /**
     * L'auteur choisit son nouveau mot de passe avec le lien.
     *
     * @throws MandateRefusal
     */
    public function complete(string $slug, string $token, string $password): void
    {
        $activity = $this->find($slug);
        $valid = $activity
            && $activity->reset_token_hash
            && $activity->reset_token_expires_at
            && $activity->reset_token_expires_at->isFuture()
            && hash_equals($activity->reset_token_hash, hash('sha256', $token));

        // Un seul message pour « inconnu », « expiré », « déjà employé » et « faux ».
        if (! $valid) {
            throw MandateRefusal::make('token', 'anim.api.reset_invalid');
        }

        DecodePassword::assertValid($password, $activity->slug, $activity->organizer_name);

        $activity->forceFill([
            'password_hash' => Hash::make($password),
            'reset_token_hash' => null,
            'reset_token_expires_at' => null,
            'password_reset_at' => Carbon::now(),
            'reset_requested_at' => null,
        ])->save();

        // D37 : chaque changement est signalé à l'auteur (par email s'il en a un ; sinon le message en jeu l'a porté).
        $this->mailer->sendPasswordChanged($activity);
    }

    private function find(string $slug): ?AnimDecodeActivity
    {
        return AnimDecodeActivity::query()
            ->where('slug', $slug)
            ->where('is_anonymous', true)
            ->where('status', '!=', AnimDecodeActivity::CLOSED)
            ->first();
    }

    private function issue(AnimDecodeActivity $activity, string $mode): string
    {
        $token = bin2hex(random_bytes(32));
        $activity->forceFill([
            'reset_token_hash' => hash('sha256', $token),
            'reset_token_expires_at' => Carbon::now()->addHours(DecodeLinks::RESET_HOURS),
            'reset_mode' => $mode,
            'reset_requested_at' => null, // une demande à l'administrateur est traitée dès qu'un lien est émis
        ])->save();

        return $token;
    }
}
