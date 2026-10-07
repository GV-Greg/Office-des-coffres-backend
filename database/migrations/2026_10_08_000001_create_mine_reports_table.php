<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registre des mines (privé) — relevés d'une province, tenus par son commissaire aux mines ou
     * son bailli. Brief admin/content/brief-registre-mines.md §2, §3, §5 ; fil
     * admin/echanges/registre-mines (PR 1a : la table ne stocke rien tant que l'API d'écriture,
     * PR 1b, n'existe pas — elle attend le texte de la politique en ligne et `policy:notify`).
     *
     * AXES EN CLAIR (filtrables, triables), PAYLOAD CHIFFRÉ (collage brut, relevé analysé, prix,
     * taux) par EncryptedModuleData — jamais l'inverse (§2).
     *
     * ⚠️ `character_id` en nullOnDelete, catégorie C (donnees-utilisateur.md) : à la suppression du
     * personnage, le relevé RESTE — c'est la mémoire de la province — et seul le lien vers son
     * auteur est coupé, par la base, sans événement Eloquent. Ne pas « harmoniser » en cascade.
     * On dit « pseudonymisé », jamais « anonymisé » (§5).
     *
     * 🔴 Ajout seul (§3) : un relevé ne se supprime jamais, il se REMPLACE. « Un seul relevé actif
     * par province et par date » est tenu PAR LA BASE : `active` vaut 1 pour le relevé en vigueur et
     * NULL pour un relevé remplacé ; l'index unique (province_id, reported_at, active) ignore les
     * NULL (MariaDB comme SQLite), donc les remplacés s'accumulent et deux actifs se heurtent.
     * Jamais `false` au lieu de NULL : deux remplacés du même jour se heurteraient.
     */
    public function up(): void
    {
        Schema::create('mine_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained('rk_provinces');
            $table->date('reported_at');                        // date du relevé (jour du jeu, en réel)
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->string('office_key');                       // commissaire_mines | bailli — estampille du poste
            $table->text('payload');                            // chiffré : collage brut, relevé analysé, prix, taux
            $table->boolean('active')->nullable()->default(true); // 1 = en vigueur, NULL = remplacé
            $table->foreignId('replaced_by_id')->nullable()->constrained('mine_reports');
            $table->timestamp('replaced_at')->nullable();
            $table->timestamps();
            $table->unique(['province_id', 'reported_at', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mine_reports');
    }
};
