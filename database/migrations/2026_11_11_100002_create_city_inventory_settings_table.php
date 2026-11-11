<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Réglages d'inventaire de la ville (catégories personnalisées et masquées, ordre, couleur des titres,
     * assignations item → catégorie) : partagés par les titulaires du poste (maire). Greg, 11/11/2026.
     *
     * Catégorie C (donnees-utilisateur.md §2, §5ter) : donnée produite au titre d'un POSTE, donc rattachée à la ville
     * par une vraie clé étrangère (jamais un propriétaire polymorphe), pas à l'auteur. Quand le titulaire change,
     * son successeur retrouve les réglages ; quand l'auteur disparaît, `updated_by_character_id` passe à NULL par la
     * base (nullOnDelete) et les réglages restent. Un niveau par table.
     * `version` : contrôle d'écriture (409 si un autre titulaire a écrit entre-temps).
     * Réglages non chiffrés (étiquettes et noms d'objets) ; le texte collé, lui, est chiffré (voir character_inventory_settings).
     */
    public function up(): void
    {
        Schema::create('city_inventory_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->unique()->constrained('rk_cities');
            $table->json('data');
            $table->unsignedInteger('version')->default(1);
            // Dernier inventaire collé, CHIFFRÉ (EncryptedModuleData) : un seul exemplaire, REMPLACÉ à chaque collage —
            // pas d'historique à ce niveau (Cowork et Greg, 11/11/2026, fil inventaire-bilans). Axes en clair, payload chiffré.
            $table->text('inventory')->nullable();
            $table->timestamp('inventory_saved_at')->nullable();
            $table->foreignId('updated_by_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_inventory_settings');
    }
};
