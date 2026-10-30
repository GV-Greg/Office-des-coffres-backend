<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les coffres scellés d'une activité : une combinaison secrète de 5 chiffres chacun.
     *
     * `code` est CHIFFRÉ par EncryptedModuleData (MODULE_DATA_KEY), jamais par APP_KEY : n'importe quel
     * joueur peut créer un secret que d'autres tentent de deviner, et `key:generate` rendrait un
     * chiffrement par APP_KEY illisible. Axes (activité, statut) en clair. Le numéro d'un coffre n'est
     * pas stocké : c'est son rang dans l'activité.
     */
    public function up(): void
    {
        Schema::create('anim_decode_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decode_activity_id')->constrained('anim_decode_activities')->cascadeOnDelete();
            $table->text('code'); // chiffré
            $table->boolean('status')->default(false); // false = en cours, true = percé
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anim_decode_secrets');
    }
};
