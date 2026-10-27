<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les propositions saisies par l'organisateur (reçues par le forum, la gazette, en jeu).
     *
     * `player` est du TEXTE LIBRE, sans clé vers un compte (décision de Greg, 18/10/2026) : la table
     * reste hors du périmètre de UserDataLifecycleTest. `created_at` seul — une proposition ne se
     * modifie pas — et il date aussi la dernière action de l'activité (fermeture à 30 jours).
     */
    public function up(): void
    {
        Schema::create('anim_decode_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('secret_id')->constrained('anim_decode_secrets')->cascadeOnDelete();
            $table->string('combination', 5);
            $table->string('player', 30);
            $table->unsignedTinyInteger('points');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anim_decode_proposals');
    }
};
