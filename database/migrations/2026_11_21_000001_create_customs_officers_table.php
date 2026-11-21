<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Douaniers (module Sécurité, « Registre de douane » ; brief admin/content/brief-module-securite.md §4, fil
     * admin/echanges/module-securite/, réponses de Greg 02-greg-reponses.md Q3).
     *
     * Un douanier n'existe PAS en jeu : c'est un rôle de l'Office, que le joueur DEMANDE (opt-in) et que le trio de sécurité
     * valide — jamais une nomination d'office. Une ligne porte la demande ET le rôle, distingués par `status` comme les mandats,
     * mais dans une table séparée : pas de durée fixe, cascade de validation propre, inactivité comptée sur la saisie d'un bilan.
     *
     * Catégorie A (§5quinquies) : `character_id` en cascadeOnDelete — un rôle sans titulaire n'a aucune valeur. Les colonnes
     * `decided_by_character_id` et `revoked_by_character_id` passent à NULL par la base (nullOnDelete) : la décision reste, son
     * auteur n'est plus nommé.
     *
     * `active` vaut 1 tant que la ligne est en attente ou en vigueur, NULL sinon : l'index unique (character_id, active) garantit
     * UN SEUL rôle ou demande en cours par personnage (NULL ignoré par l'index, comme mine_reports).
     */
    public function up(): void
    {
        Schema::create('customs_officers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            // Province où s'exerce le rôle = province de la ville de rattachement, fixée à la demande (§5ter : vraie FK).
            $table->foreignId('province_id')->constrained('rk_provinces')->cascadeOnDelete();
            // Ville de rattachement : pré-remplit le sélecteur de ville ; le douanier saisit pour toute ville de sa province.
            $table->foreignId('city_id')->constrained('rk_cities')->cascadeOnDelete();
            $table->string('status')->index();                    // pending | approved | rejected | revoked
            $table->unsignedTinyInteger('active')->nullable();    // 1 = en attente ou en vigueur, NULL sinon
            $table->foreignId('decided_by_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->unsignedTinyInteger('decision_level')->nullable(); // 1 prévôt/connétable, 2 repli, 3 administrateur
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('in_office_from')->nullable();      // début de l'accès (= approbation)
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->string('revocation_reason')->nullable();      // self | primary | fallback | admin | inactivity
            // Seule la saisie d'un bilan remet le compteur d'inactivité à zéro (brief §4.2).
            $table->timestamp('last_report_at')->nullable();
            $table->timestamp('inactivity_notice_sent_at')->nullable(); // avis à 7 jours, sans trio seulement
            $table->timestamps();

            $table->unique(['character_id', 'active']);
            $table->index(['province_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customs_officers');
    }
};
