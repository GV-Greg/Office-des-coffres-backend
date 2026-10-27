<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les propositions saisies par l'organisateur (reçues par le forum, la gazette, en jeu).
     *
     * `character_name` est le NOM DU PERSONNAGE qui a proposé, en texte libre, sans clé vers un compte
     * (décision de Greg, 18/10/2026 ; vocabulaire « personnage » du 28/10, D34) : la table reste hors du
     * périmètre de UserDataLifecycleTest. `created_at` seul — une proposition ne se modifie pas — et il date
     * aussi la dernière action de l'activité (fermeture à 30 jours). Une activité SANS COMPTE efface ses
     * propositions à la clôture (D18) ; une activité privée les garde jusqu'à sa suppression (D20).
     */
    public function up(): void
    {
        Schema::create('anim_decode_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('secret_id')->constrained('anim_decode_secrets')->cascadeOnDelete();
            $table->string('combination', 5);
            $table->string('character_name', 30);
            $table->unsignedTinyInteger('points');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anim_decode_proposals');
    }
};
