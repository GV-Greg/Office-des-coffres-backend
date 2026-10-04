<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Seul écrivain de users.last_seen_at (brief-politique-promesses §2.2 ; fil politique-promesses, E1
 * et Q7).
 *
 * « Connexion » = toute requête authentifiée (décision de Greg du 04/10/2026), API comme panneau
 * Blade (Q2). Les deux erreurs ne se valent pas : garder un compte inactif un peu trop longtemps
 * est un défaut de ménage, supprimer celui d'un joueur actif est irréversible — on penche toujours
 * du côté de « actif ».
 *
 * Deux entrées :
 * - App\Http\Middleware\RecordLastSeen voit l'utilisateur authentifié de toute requête ;
 * - les routes publiques qui authentifient elles-mêmes (register, verifyEmail, login, refresh —
 *   hors du groupe auth:api) le désignent par markForRequest(). Sans elles, un client qui ne fait
 *   que rafraîchir son jeton ne compterait pas.
 * Dans les deux cas l'écriture a lieu dans terminate(), après l'envoi de la réponse.
 */
class LastSeen
{
    private const REQUEST_ATTRIBUTE = 'last_seen.user';

    /** Désigne le compte à marquer pour cette requête ; l'écriture se fait après la réponse. */
    public static function markForRequest(Request $request, User $user): void
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $user);
    }

    public static function markedFor(Request $request): ?User
    {
        return $request->attributes->get(self::REQUEST_ATTRIBUTE);
    }

    /**
     * Écrit le passage, au plus une fois par jour : gardé par une LECTURE des attributs déjà
     * chargés, pas par un update systématique. Un préavis en cours force l'écriture, pour qu'il
     * soit annulé dès le premier retour.
     *
     * Écriture hors Eloquent (toBase) : updated_at ne bouge pas, last_seen_at ne mesure que le
     * passage du joueur — et updated_at garde son sens.
     */
    public static function record(User $user): void
    {
        $now = Carbon::now();

        if ($user->last_seen_at?->greaterThanOrEqualTo($now->copy()->startOfDay())
            && $user->deletion_notice_sent_at === null) {
            return;
        }

        $values = ['last_seen_at' => $now, 'deletion_notice_sent_at' => null];

        $user->newQuery()->toBase()->where($user->getKeyName(), $user->getKey())->update($values);
        $user->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }
}
