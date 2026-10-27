<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Décode le code — l'activité d'un organisateur (fil admin/echanges/animation-coffres-scelles,
     * tour 12). DEUX MODES dans la même table :
     *
     *  - PRIVÉ : `character_id` renseigné. ⚠️ **Catégorie A, cascadeOnDelete** (D21, Greg 28/10/2026) :
     *    quand le personnage ou son compte disparaît, l'activité, ses coffres, ses propositions et sa page
     *    publique disparaissent avec lui. (Le lot 1 l'avait en catégorie C ; cette décision l'a inversée.)
     *  - SANS COMPTE : `character_id` nul, `password_hash` renseigné — l'auteur se prouve par le slug (public)
     *    et son mot de passe. Aucune clé vers un compte : hors du périmètre de UserDataLifecycleTest.
     *
     * `slug` est la clé de recherche et d'unicité (lettres et tirets, 3 à 20 caractères, jamais modifié, D14) ;
     * le nom d'affichage n'est plus unique à lui seul. `province_id`, `city_id` et `kingdom_id` sont
     * mutuellement exclusifs : règle tenue par le Service (pas de CHECK SQL, MySQL 5.7 / SQLite).
     *
     * Données personnelles du mode sans compte, toutes EFFACÉES À LA CLÔTURE (D16, D30) : `creator_ip_hash`
     * (empreinte HMAC, jamais l'adresse), `organizer_email` (chiffré MODULE_DATA_KEY), `password_hash`, le jeton
     * de réinitialisation. `closing_snapshot` est la photo figée que la page publique lit pendant 5 ans (D7, D18).
     */
    public function up(): void
    {
        Schema::create('anim_decode_activities', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 20)->unique();
            $table->string('name', 190);
            $table->foreignId('character_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_anonymous')->default(false); // sans compte ; explicite, `password_hash` étant effacé à la clôture
            $table->string('organizer_name', 30)->nullable();
            $table->string('password_hash')->nullable();
            $table->string('creator_ip_hash', 64)->nullable()->index();
            $table->text('organizer_email')->nullable(); // chiffré
            $table->timestamp('organizer_email_verified_at')->nullable();
            $table->string('reset_token_hash', 64)->nullable();
            $table->timestamp('reset_token_expires_at')->nullable();
            $table->string('reset_mode', 10)->nullable(); // email | admin — comment le jeton a été émis
            $table->timestamp('password_reset_at')->nullable();
            $table->timestamp('reset_requested_at')->nullable(); // l'auteur a demandé un lien à l'administrateur (auteur sans email) ; effacé à l'émission du lien ou à la clôture
            $table->foreignId('activity_type_id')->constrained('anim_activity_types');
            $table->foreignId('kingdom_id')->nullable()->constrained('rk_kingdoms')->nullOnDelete();
            $table->foreignId('province_id')->nullable()->constrained('rk_provinces')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('rk_cities')->nullOnDelete();
            $table->enum('status', ['new', 'launched', 'relaunch', 'closed'])->default('new');
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('closing_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anim_decode_activities');
    }
};
