<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historique des postes d'un conseil (Q17) : une ligne = une période de détention, aucune ligne
     * pour une période sans poste. `ended_at` est TOUJOURS renseigné : il naît égal au holds_until
     * du mandat avec end_reason = fin_du_mandat, et suit chaque mouvement de holds_until tant que la
     * période est en cours (Q19-C) — écrit par la même méthode que holds_until.
     *
     * `dateTime` et non `timestamp` : MySQL 5.7 (dev) refuse une seconde colonne `timestamp` NOT NULL
     * sans valeur par défaut (erreur 1067), ce que SQLite, utilisé par les tests, accepte sans rien
     * dire. Les instants y sont en UTC, comme partout (config app.timezone).
     */
    public function up(): void
    {
        Schema::create('council_office_periods', function (Blueprint $table) {
            $table->id();
            // Catégorie A par transitivité : part avec le mandat, qui part avec le personnage.
            $table->foreignId('council_mandate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('council_office_id')->constrained('council_offices');
            $table->dateTime('started_at');
            $table->dateTime('ended_at');
            // fin_du_mandat | reattribue | retire_par_le_dirigeant | declaration_erronee | autre
            // | declare_par_le_joueur — code en texte (Q21), distinct des motifs d'email (Q16).
            $table->string('end_reason');
            $table->timestamps();
            $table->index(['council_office_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('council_office_periods');
    }
};
