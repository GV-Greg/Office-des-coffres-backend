<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lots d'une grille. Catégorie C (donnée communautaire de la province) : le lot reste quand l'organisateur
     * disparaît (`grid_id` CASCADE depuis `anim_rewards_grids.character_id nullOnDelete`).
     *
     * `place` est attribué au tirage (null avant) : un index partiel serait utile mais non portable SQLite/MariaDB.
     * `player` est le nom libre du personnage qui a choisi la case ; aucune clé vers un compte.
     * `is_taken` = lot remis au gagnant.
     */
    public function up(): void
    {
        Schema::create('anim_rewards_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grid_id')->constrained('anim_rewards_grids')->cascadeOnDelete();
            $table->string('name', 50);
            $table->smallInteger('place')->unsigned()->nullable(); // position 1..width*height, assignée au tirage
            $table->string('player', 40)->nullable(); // nom libre du personnage qui a choisi la case
            $table->boolean('is_taken')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anim_rewards_lists');
    }
};
