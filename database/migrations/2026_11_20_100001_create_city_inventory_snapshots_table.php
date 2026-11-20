<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registre des stocks, niveau VILLE (Cowork et Greg, 20/11/2026, fil admin/echanges/registre-stocks, Q1-A : une table par
     * niveau, chacune avec sa vraie FK). Jumelle de `province_inventory_snapshots` : la mémoire de la ville, transmise d'un maire
     * au suivant — le dernier inventaire collé de `city_inventory_settings` reste un seul exemplaire remplacé, celui-ci en est
     * l'HISTORIQUE, inscrit par un geste volontaire du maire (« Inscrire au registre »).
     *
     * AXES EN CLAIR (ville, jour, poste, auteur), PAYLOAD CHIFFRÉ (le texte collé, EncryptedModuleData). Le trésor et les stocks
     * sont dans le payload : aucun montant en clair.
     *
     * ⚠️ `character_id` en nullOnDelete, catégorie C (donnees-utilisateur.md) : à la suppression du personnage, l'exemplaire
     * RESTE et seul le lien vers son auteur est coupé, par la base. Ne pas « harmoniser » en cascade.
     *
     * 🔴 Ajout seul : un exemplaire ne se supprime jamais, il se REMPLACE. « Un seul exemplaire actif par ville et par jour » est
     * tenu PAR LA BASE (`active` = 1 en vigueur, NULL remplacé ; l'index unique ignore les NULL). Jamais `false` au lieu de NULL :
     * deux remplacés du même jour se heurteraient.
     *
     * `office_key` : `mayor` (App\Models\MayorMandate::LEVEL) — estampille du poste à l'écriture, comme les mines.
     */
    public function up(): void
    {
        Schema::create('city_inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('rk_cities');
            $table->date('taken_on');                           // jour de Paris où l'on colle (le texte du jeu ne porte aucune date)
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->string('office_key');                       // mayor — estampille du poste à l'écriture
            $table->text('payload');                            // chiffré : le texte de l'inventaire collé
            $table->boolean('active')->nullable()->default(true); // 1 = en vigueur, NULL = remplacé
            $table->foreignId('replaced_by_id')->nullable()->constrained('city_inventory_snapshots');
            $table->timestamp('replaced_at')->nullable();
            $table->timestamps();
            $table->unique(['city_id', 'taken_on', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_inventory_snapshots');
    }
};
