<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Réglages de sécurité d'une PROVINCE (module Sécurité), écrits par le prévôt des maréchaux ou le connétable (ou, en repli, par
     * le niveau 2). Pour l'instant : la durée d'inactivité du douanier. Catégorie C : donnée de la province, `updated_by_character_id`
     * passe à NULL par la base (nullOnDelete), le réglage reste pour le successeur. Une table par niveau, vraie FK (§5ter).
     */
    public function up(): void
    {
        Schema::create('province_security_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->unique()->constrained('rk_provinces')->cascadeOnDelete();
            $table->unsignedSmallInteger('customs_inactivity_days')->nullable(); // NULL = durée par défaut (CustomsOfficer::DEFAULT_INACTIVITY_DAYS)
            $table->foreignId('updated_by_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('province_security_settings');
    }
};
