<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bilans de défense du Registre de douane (module Sécurité ; brief admin/content/brief-module-securite.md §6-§7 ; fil
     * admin/echanges/module-securite, décisions de Greg du 22/11/2026). UN bilan par ville et par jour du jeu.
     *
     * AXES EN CLAIR (ville, jour, auteur), PAYLOAD CHIFFRÉ (MODULE_DATA_KEY) : par section (guet, visions, armées/groupes/contrôle de la zone), le
     * brut collé (liste des villageois sans préambule, collage armées/groupes, lignes des visions du jour) ET le JSON analysé par le parseur
     * du frontend, avec `parser_version` et le total annoncé. Le serveur ne re-parse jamais (décision de Greg, comme les mines).
     *
     * ⚠️ `character_id` en nullOnDelete, catégorie C : à la suppression du personnage, le bilan RESTE (mémoire de la ville) et seul le lien
     * avec son auteur est coupé, par la base. « Le lien est supprimé », jamais « anonymisé » (politique §5). Ne pas « harmoniser » en cascade.
     *
     * 🔴 Ajout seul : un bilan ne se supprime jamais, il se REMPLACE (correction du prévôt ou du connétable) ou se COMPLÈTE (même auteur, même
     * jour). « Un seul bilan en vigueur par ville et par jour » est tenu PAR LA BASE : `active` = 1 en vigueur, NULL remplacé (l'index unique ignore
     * les NULL — jamais `false` au lieu de NULL).
     *
     * NOM RÉSERVÉ par SecurityNotConsultableTest : aucune surface d'administration ne référence cette table.
     */
    public function up(): void
    {
        Schema::create('customs_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('rk_cities');
            $table->date('reported_on');                          // jour du jeu du bilan (bascule 04:00 Paris)
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_role');                        // estampille à l'écriture : prevot_des_marechaux | connetable | capitaine | mayor | customs
            $table->text('payload');                              // chiffré : sections {raw, report, parser_version}
            $table->boolean('active')->nullable()->default(true); // 1 = en vigueur, NULL = remplacé
            $table->foreignId('replaced_by_id')->nullable()->constrained('customs_reports');
            $table->timestamp('replaced_at')->nullable();
            $table->timestamps();
            $table->unique(['city_id', 'reported_on', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customs_reports');
    }
};
