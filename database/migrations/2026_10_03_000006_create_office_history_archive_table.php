<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ce qui reste de l'historique des postes quand un compte ou un personnage est supprimé
     * (fil admin/echanges/mandats-historique, Q6 → Greg : l'information est conservée ; Q16 → 12).
     *
     * ⚠️ Ce n'est PAS « l'historique » : tant qu'un personnage existe, le sien vit dans les tables
     * des mandats, justes par construction. Cette table ne reçoit que ce que la cascade va effacer,
     * écrit par UN SEUL chemin (App\Services\AccountDeletion), et ne bouge plus ensuite. La vue
     * fusionne les deux sources (App\Services\OfficeHistory), sans recouvrement possible.
     *
     * Catégorie D (conservation justifiée) : AUCUNE clé vers un personnage ni un compte — un pseudo
     * en texte, un lieu, un rôle, des dates. Lieu en id (pour lier) ET en texte (pour survivre).
     */
    public function up(): void
    {
        Schema::create('office_history_archive', function (Blueprint $table) {
            $table->id();
            $table->string('level', 10);                    // mayor | council
            $table->string('character_pseudo');
            $table->foreignId('province_id')->nullable()->constrained('rk_provinces')->nullOnDelete();
            $table->string('province_name');
            $table->foreignId('city_id')->nullable()->constrained('rk_cities')->nullOnDelete();
            $table->string('city_name')->nullable();       // maires seulement
            $table->string('office_key')->nullable();      // conseil : clé du titre, jamais un libellé
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('end_reason')->nullable();      // code (fin_du_mandat, reattribue, motif de révocation…)
            $table->dateTime('archived_at');
            $table->index(['province_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_history_archive');
    }
};
