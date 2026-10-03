<?php

namespace App\Services;

use App\Models\Character;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * LA porte unique de suppression d'un compte ou d'un personnage (fil mandats-historique, 12).
 *
 * Elle archive d'abord l'historique des postes (catégorie D, décision de Greg du 03/10/2026 : il
 * survit à la suppression), puis supprime. La cascade SQL (`users` → `characters` → mandats) ne
 * déclenche AUCUN évènement Eloquent sur les personnages : un `$user->delete()` écrit ailleurs
 * effacerait l'historique en silence.
 *
 * ⚠️ Garde-fou : tests/Feature/Enforcement/AccountDeletionTest.php échoue sur toute suppression de
 * User ou de Character écrite hors de ce fichier — la purge future des comptes inactifs comprise.
 */
class AccountDeletion
{
    public function __construct(private readonly OfficeHistory $history) {}

    /**
     * Archive puis supprime un compte. `$alsoInTransaction` : le nettoyage propre à l'appelant
     * (jetons OAuth…), exécuté dans la même transaction, avant la suppression.
     */
    public function deleteUser(User $user, ?callable $alsoInTransaction = null): void
    {
        DB::transaction(function () use ($user, $alsoInTransaction) {
            foreach ($user->characters()->get() as $character) {
                $this->history->archiveCharacter($character);
            }
            if ($alsoInTransaction !== null) {
                $alsoInTransaction($user);
            }
            $user->delete();
        });
    }

    /** Archive puis supprime un personnage (aucun écran ne le propose encore). */
    public function deleteCharacter(Character $character): void
    {
        DB::transaction(function () use ($character) {
            $this->history->archiveCharacter($character);
            $character->delete();
        });
    }
}
