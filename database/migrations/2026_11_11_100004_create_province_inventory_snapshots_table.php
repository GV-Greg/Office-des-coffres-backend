<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inventaire de la province — HISTORIQUE des collages du commissaire au commerce, pour ses bilans (Cowork et
     * Greg, 11/11/2026, fil admin/echanges/inventaire-bilans : « B pour la province, C ailleurs »). Calquée sur
     * `mine_reports` : la mémoire de la province, transmise d'un commissaire au suivant.
     *
     * AXES EN CLAIR (province, jour, poste, auteur), PAYLOAD CHIFFRÉ (le texte collé, EncryptedModuleData). Le
     * trésor et les stocks sont dans le payload : aucun montant en clair.
     *
     * ⚠️ `character_id` en nullOnDelete, catégorie C (donnees-utilisateur.md) : à la suppression du personnage,
     * l'exemplaire RESTE et seul le lien vers son auteur est coupé, par la base. Ne pas « harmoniser » en cascade.
     *
     * 🔴 Ajout seul : un exemplaire ne se supprime jamais, il se REMPLACE. « Un seul exemplaire actif par province
     * et par jour » est tenu PAR LA BASE (`active` = 1 en vigueur, NULL remplacé ; l'index unique ignore les NULL).
     * Jamais `false` au lieu de NULL : deux remplacés du même jour se heurteraient.
     *
     * Colonnes de la phase future (valorisation, trésorerie) : NON créées maintenant, la table peut les accueillir.
     */
    public function up(): void
    {
        Schema::create('province_inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained('rk_provinces');
            $table->date('taken_on');                           // jour de Paris où l'on colle (le texte du jeu ne porte aucune date)
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->string('office_key');                       // commissaire_commerce — estampille du poste à l'écriture
            $table->text('payload');                            // chiffré : le texte de l'inventaire collé
            $table->boolean('active')->nullable()->default(true); // 1 = en vigueur, NULL = remplacé
            $table->foreignId('replaced_by_id')->nullable()->constrained('province_inventory_snapshots');
            $table->timestamp('replaced_at')->nullable();
            $table->timestamps();
            $table->unique(['province_id', 'taken_on', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('province_inventory_snapshots');
    }
};
