<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Annonces du site (18/11/2026) : l'administrateur prévient les joueurs d'une maintenance à venir ;
     * le site les fait défiler en bandeau sur l'accueil de l'application.
     *
     * Texte FR et EN, échéance de la maintenance (facultative) et fenêtre de VISIBILITÉ du bandeau : de
     * `visible_from` à `visible_until`, ou à défaut jusqu'à la fin de la maintenance (à son début si seul
     * le début est connu). L'échéance est SOIT une date et une heure précises (`schedule_type` = exact),
     * SOIT une période en jours (`period`, « cette semaine » : du 1er jour à 00:00 au dernier à 23:59,
     * heure de Paris), SOIT absente. Toutes les dates sont stockées en UTC.
     * `is_active` est l'interrupteur manuel : retirer une annonce sans la supprimer.
     *
     * Table hors taxinomie données-utilisateur : texte écrit par l'administrateur, aucun lien vers un
     * compte ou un personnage (comme `item_icons`).
     */
    public function up(): void
    {
        Schema::create('site_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('message_fr', 500);
            $table->string('message_en', 500);
            $table->string('schedule_type', 10)->nullable();
            $table->dateTime('maintenance_starts_at')->nullable();
            $table->dateTime('maintenance_ends_at')->nullable();
            $table->dateTime('visible_from');
            $table->dateTime('visible_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'visible_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_announcements');
    }
};
