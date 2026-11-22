<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Listes rouge et verte d'une PROVINCE (module Sécurité, Registre de douane ; fil admin/echanges/module-securite, Greg le 22/11/2026).
     * Une entrée = un pseudo de personnage (texte libre : le personnage peut n'avoir aucun compte sur l'Office) et une raison facultative.
     *
     * AXES EN CLAIR (province, couleur, jour d'ajout, auteur), PAYLOAD CHIFFRÉ {pseudo, reason} : la coloration compare en mémoire,
     * côté serveur (listes de quelques centaines d'entrées au plus) — jamais d'index sur un contenu chiffré.
     *
     * Couleur à partir de la date d'ajout (`added_on`, jour du jeu), tant que l'entrée est en liste. 🔴 Un retrait SUPPRIME la ligne (choix B de
     * Greg, 22/11/2026) : aucune trace d'un pseudo retiré ; seul `customs:remove-pseudo` efface en plus un pseudo des bilans.
     *
     * `added_by_character_id` en nullOnDelete, catégorie C : l'entrée reste pour la province, le lien avec l'auteur est coupé par la base.
     * Absent de l'export art. 20 (trace d'activité sans contenu seulement). NOM RÉSERVÉ par SecurityNotConsultableTest.
     */
    public function up(): void
    {
        Schema::create('customs_watchlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained('rk_provinces');
            $table->string('colour');                              // red | green
            $table->date('added_on');                              // jour du jeu de l'ajout : début de la coloration
            $table->text('payload');                               // chiffré : {pseudo, reason}
            $table->foreignId('added_by_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->timestamps();
            $table->index(['province_id', 'colour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customs_watchlist_entries');
    }
};
