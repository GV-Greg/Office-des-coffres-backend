<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trace des envois de `policy:notify` : une ligne par entrée du journal envoyée, pour qu'aucune
     * ne parte deux fois (fil admin/echanges/politique-promesses, Q6).
     *
     * ⚠️ Cette table est HORS de la taxinomie de admin/strategies/donnees-utilisateur.md tant
     * qu'elle ne porte aucun lien vers un compte : un identifiant d'entrée, une date, des nombres.
     * Ce n'est pas une donnée personnelle, c'est une trace d'exploitation.
     * 🔴 Ajouter une colonne par destinataire (« savoir qui a reçu quoi ») rouvre la question : la
     * table entre alors dans la taxinomie et doit être catégorisée (UserDataLifecycleTest).
     */
    public function up(): void
    {
        Schema::create('policy_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('entry_id', 100)->unique();
            $table->unsignedInteger('recipients');
            $table->unsignedInteger('failures');
            $table->timestamp('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_notifications');
    }
};
