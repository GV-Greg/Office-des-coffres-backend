<?php

namespace App\Services;

use App\Models\Character;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\AuthCode;
use Laravel\Passport\DeviceCode;
use Laravel\Passport\RefreshToken;

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
     * Archive, efface les traces sans clé étrangère, puis supprime un compte — pour TOUS les
     * chemins (API, admin, profil Breeze, purge des comptes inactifs). Jusqu'au 04/10/2026, seul le
     * chemin API effaçait les jetons OAuth et password_reset_tokens : les deux autres laissaient
     * survivre une adresse email à la suppression du compte.
     */
    public function deleteUser(User $user): void
    {
        DB::transaction(function () use ($user) {
            foreach ($user->characters()->get() as $character) {
                $this->history->archiveCharacter($character);
            }
            $this->deleteTracesWithoutForeignKey($user);
            $user->delete();
        });
    }

    /**
     * Catégorie B de admin/strategies/donnees-utilisateur.md : ces tables ne portent pas de FK vers
     * `users`, la cascade ne les vide pas.
     */
    private function deleteTracesWithoutForeignKey(User $user): void
    {
        // Tables OAuth de Passport : sans ce nettoyage, les jetons survivraient au compte qu'ils
        // désignent.
        $accessTokenIds = $user->tokens()->pluck('id');
        RefreshToken::whereIn('access_token_id', $accessTokenIds)->delete();
        $user->tokens()->delete();

        // Le projet n'utilise ni le code d'autorisation ni le device flow, mais Passport expose leurs
        // routes par défaut (`oauth/authorize`, `oauth/device/code`) : rien ne garantit que ces
        // tables restent vides, et leur user_id n'est qu'une colonne indexée.
        AuthCode::where('user_id', $user->id)->delete();
        DeviceCode::where('user_id', $user->id)->delete();

        // Clé par email, sans FK vers users : c'est la table qui laissait survivre une adresse
        // email à un effacement art. 17 (écart constaté en prod le 19/09/2026).
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        // Les personnages partent en cascade (FK ON DELETE CASCADE sur characters.user_id) ;
        // model_has_roles et model_has_permissions sont détachées par le hook `deleting` des
        // traits HasRoles / HasPermissions de Spatie (vérifié, pas supposé — voir les tests).
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
