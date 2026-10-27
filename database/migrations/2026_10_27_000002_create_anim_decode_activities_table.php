<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les Coffres Scellés — l'activité d'un organisateur.
     *
     * ⚠️ `character_id` en nullOnDelete, catégorie C (donnees-utilisateur.md) : si l'organisateur
     * supprime son compte, l'activité et tout ce que les autres joueurs y ont inscrit RESTENT ; seul
     * le nom de l'organisateur disparaît. Une cascade effacerait la participation d'autrui pour un
     * acte qui ne leur appartient pas. Ne pas « harmoniser » en cascade.
     *
     * `province_id` et `city_id` sont mutuellement exclusifs : règle tenue par le Service
     * (DecodeWorkflow), pas par un CHECK SQL (compatibilité MySQL 5.7 / SQLite).
     */
    public function up(): void
    {
        Schema::create('anim_decode_activities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 190)->unique();
            $table->text('description')->nullable();
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('activity_type_id')->constrained('anim_activity_types');
            $table->foreignId('province_id')->nullable()->constrained('rk_provinces')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('rk_cities')->nullOnDelete();
            $table->enum('status', ['new', 'launched', 'relaunch', 'closed'])->default('new');
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anim_decode_activities');
    }
};
