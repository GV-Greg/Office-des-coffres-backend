<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Livre des naissances (27/11/2026) : les espèces élevées dans une province, les prix de la nourriture du cheptel par date
     * d'effet, et les naissances inscrites. Données COMMUNAUTAIRES de la province (catégorie C) : elles restent au successeur ;
     * le lien vers le personnage auteur passe à NULL par la base. Axes en clair, contenu d'une naissance chiffré.
     */
    public function up(): void
    {
        // Aucun lien vers un personnage : la liste des espèces ne dit pas qui l'a réglée.
        Schema::create('province_livestock_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->unique()->constrained('rk_provinces');
            $table->json('species');
            $table->timestamps();
        });

        // Écus du jeu pour une unité de nourriture ; vide = pas de prix. Pas de suppression applicative.
        Schema::create('province_feed_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained('rk_provinces');
            $table->date('effective_from');
            $table->decimal('price_maize', 10, 2)->nullable();
            $table->decimal('price_wheat', 10, 2)->nullable();
            $table->decimal('price_marjoram', 10, 2)->nullable();
            $table->decimal('price_elderberry', 10, 2)->nullable();
            $table->foreignId('created_by_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->foreignId('updated_by_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->timestamps();

            $table->unique(['province_id', 'effective_from']);
        });

        // Une ligne par naissance inscrite (une opération du jeu). Un retrait ne supprime rien.
        Schema::create('livestock_births', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained('rk_provinces');
            $table->date('born_on');
            $table->foreignId('character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->text('payload'); // chiffré : espèce, nombre, nourriture, quantité prise et perdue
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->timestamps();

            $table->index(['province_id', 'born_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livestock_births');
        Schema::dropIfExists('province_feed_prices');
        Schema::dropIfExists('province_livestock_settings');
    }
};
