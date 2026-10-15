<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registre des mines, lot 2 — prix et taux horaire d'une province, PAR PÉRIODE (brief
     * admin/content/brief-prix-registre.md ; décisions de Greg du 14/10/2026). Un jeu de prix entre en
     * vigueur à `effective_from` et le reste jusqu'à ce qu'un jeu postérieur le remplace : pas de
     * `effective_to`. Les bilans se recalculent JOUR PAR JOUR avec le jeu en vigueur ce jour-là.
     *
     * ⚠️ Donnée COMMUNAUTAIRE de la province (catégorie C, donnees-utilisateur.md) : elle sert tous les
     * bilans, elle n'appartient pas à son auteur. Le lien vers l'auteur est un PERSONNAGE (comme
     * `mine_reports.character_id`, jamais le compte : le site nomme des pseudos), en nullOnDelete —
     * la base coupe le lien, le prix reste. Écart assumé avec le brief, qui visait `users` : un compte
     * n'a pas de pseudo à afficher, et les notifications nomment l'auteur.
     *
     * Pas de suppression applicative : un prix faux se corrige, il ne s'efface pas.
     */
    public function up(): void
    {
        Schema::create('province_mine_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained('rk_provinces');
            $table->date('effective_from');
            // Écus du jeu, deux décimales (Greg, 15/10/2026).
            $table->decimal('price_or', 10, 2);
            $table->decimal('price_fer', 10, 2);
            $table->decimal('price_pierre', 10, 2);
            $table->decimal('price_argile', 10, 2);
            $table->decimal('price_sel', 10, 2);
            $table->decimal('hourly_rate', 10, 2);
            $table->foreignId('created_by_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->foreignId('updated_by_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->timestamps();

            // Un seul jeu de prix par province et par date d'effet.
            $table->unique(['province_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('province_mine_prices');
    }
};
