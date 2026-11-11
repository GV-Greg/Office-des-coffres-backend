<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Réglages d'inventaire de la province (catégories personnalisées et masquées, ordre, couleur des titres,
     * assignations item → catégorie) : gérés par le commissaire au commerce de la province, lus aussi par son dirigeant (Greg, 11/11/2026).
     *
     * Catégorie C (donnees-utilisateur.md §2, §5ter) : donnée produite au titre d'un POSTE, donc rattachée à la province
     * par une vraie clé étrangère (jamais un propriétaire polymorphe), pas à l'auteur. Quand le titulaire change,
     * son successeur retrouve les réglages ; quand l'auteur disparaît, `updated_by_character_id` passe à NULL par la
     * base (nullOnDelete) et les réglages restent. Un niveau par table.
     * `version` : contrôle d'écriture (409 si un autre titulaire a écrit entre-temps).
     * Non chiffré : des étiquettes et des noms d'objets (voir character_inventory_settings).
     */
    public function up(): void
    {
        Schema::create('province_inventory_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->unique()->constrained('rk_provinces');
            $table->json('data');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('province_inventory_settings');
    }
};
