<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mesure de l'inactivité (admin/content/brief-politique-promesses.md §2.1 ; fil
     * admin/echanges/politique-promesses). Écrites par App\Support\LastSeen, seul écrivain.
     *
     * - last_seen_at : dernier passage authentifié, au plus une écriture par jour.
     * - deletion_notice_sent_at : préavis de suppression (inactivité) OU rappel de confirmation
     *   (compte non vérifié) — une seule colonne pour les deux chemins (§2.7). Remise à null à
     *   tout passage : le préavis est annulé, pas mis en pause (§2.3).
     *
     * ⚠️ Remplissage des lignes existantes à la date de la migration, JAMAIS à created_at : on
     * ignore quand ces comptes se sont connectés pour la dernière fois, et supprimer sur une
     * estimation, c'est supprimer sur une supposition. Le compteur part du jour où on sait compter.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('deletion_notice_sent_at')->nullable();
        });

        DB::table('users')->update(['last_seen_at' => Carbon::now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn(['last_seen_at', 'deletion_notice_sent_at']);
        });
    }
};
