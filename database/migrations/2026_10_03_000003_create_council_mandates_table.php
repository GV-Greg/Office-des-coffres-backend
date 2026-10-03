<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mandats de conseiller comtal (niveau province).
     *
     * Une table par niveau, avec une vraie FK (§5ter), jamais de propriétaire polymorphe. Une ligne
     * porte la demande ET le mandat, distingués par `status`. Arbitrages : admin/echanges/mandats-lot1/.
     * Une table par migration : MySQL n'annule pas un CREATE TABLE, un échec au milieu d'une migration
     * à plusieurs tables laissait la base à moitié créée (constaté en dev le 03/10/2026).
     *
     * ⚠️ `character_id` en cascadeOnDelete, catégorie A. Ne pas « harmoniser » en nullOnDelete :
     * une ligne de mandat survivante relierait un lieu et des dates à un personnage, et
     * ré-identifierait les entrées de module anonymisées (§2-C). La cascade est portante.
     */
    public function up(): void
    {
        Schema::create('council_mandates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('province_id')->constrained('rk_provinces')->cascadeOnDelete();
            // PAS de council_office_id : le poste détenu est la période en vigueur de
            // council_office_periods (Q17). Ici, seulement une DEMANDE de poste : titre d'une
            // première inscription, ou déclaration du joueur. « En attente » se lit sur
            // office_requested_at, car pending_office_id NULL est une cible légitime (« devenir
            // sans portefeuille »).
            $table->foreignId('pending_office_id')->nullable()->constrained('council_offices');
            $table->timestamp('office_requested_at')->nullable();
            $table->string('status')->index();                   // pending | approved | rejected | revoked
            $table->date('declared_started_at');                  // saisie du joueur, JAMAIS modifiée (Q6)
            $table->date('started_at');                           // date retenue (corrigeable par l'admin)
            $table->timestamp('valid_until')->nullable();         // fin NOMINALE, calculée à l'approbation
            $table->timestamp('in_office_from')->nullable();      // début EFFECTIF de l'autorité
            $table->timestamp('holds_until')->nullable();         // fin EFFECTIVE, quelle qu'en soit la cause (Q1)
            // Qui a fixé holds_until : nominal | extension | handover | revocation (Q25). Écrit par la
            // même méthode que holds_until ; dit à l'écran pourquoi une correction ne l'a pas déplacé.
            $table->string('holds_until_set_by')->nullable();
            $table->string('announcement_url', 500);              // preuve fournie par le joueur
            $table->foreignId('renews_id')->nullable()->constrained('council_mandates')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();          // moment du geste de révocation (trace)
            $table->text('note')->nullable();                     // trace INTERNE, jamais envoyée ni exportée
            $table->string('decision_reason')->nullable();        // code du motif, en texte, sans FK (Q7)
            $table->text('decision_message')->nullable();         // commentaire libre envoyé au joueur
            $table->string('decision_message_locale', 2)->nullable(); // langue du commentaire : fr | en
            $table->timestamp('reminder_sent_at')->nullable();    // lot 3
            $table->timestamp('verified_at')->nullable();         // file de vérification des mandats terminés
            $table->timestamps();
            $table->index(['province_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('council_mandates');
    }
};
