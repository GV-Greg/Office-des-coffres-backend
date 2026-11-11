<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grille des lots — l'activité d'un organisateur. DEUX MODES dans la même table :
     *
     *  - PRIVÉ : `character_id` renseigné. ⚠️ **Catégorie A, cascadeOnDelete** (décision de Greg, 11/11/2026, comme
     *    Décode le code) : la suppression du personnage ou du compte emporte la grille privée et ses lots. Les noms
     *    des gagnants sont du texte libre, sans lien vers un compte.
     *  - SANS COMPTE : `character_id` nul, `password_hash` renseigné.
     *
     * Automate d'état : new → incomplete → filled → drawed → confirmed → closed.
     * `slug` est la clé de recherche et d'URL publique (`/public/activite/grille/{slug}`, visible depuis drawed).
     * `width × height` = capacité de la grille ; remplie à 100 % avant tout tirage.
     * Données personnelles du mode sans compte effacées à la clôture : mêmes colonnes que `anim_decode_activities`.
     */
    public function up(): void
    {
        Schema::create('anim_rewards_grids', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 20)->unique();
            $table->string('name', 190);
            $table->foreignId('character_id')->nullable()->constrained()->cascadeOnDelete(); // catégorie A
            $table->boolean('is_anonymous')->default(false);
            $table->string('organizer_name', 30)->nullable();
            $table->string('password_hash')->nullable();
            $table->string('creator_ip_hash', 64)->nullable()->index();
            $table->text('organizer_email')->nullable(); // chiffré
            $table->timestamp('organizer_email_verified_at')->nullable();
            $table->string('reset_token_hash', 64)->nullable();
            $table->timestamp('reset_token_expires_at')->nullable();
            $table->string('reset_mode', 10)->nullable();
            $table->timestamp('password_reset_at')->nullable();
            $table->timestamp('reset_requested_at')->nullable();
            $table->foreignId('activity_type_id')->constrained('anim_activity_types');
            $table->foreignId('kingdom_id')->nullable()->constrained('rk_kingdoms')->nullOnDelete();
            $table->foreignId('province_id')->nullable()->constrained('rk_provinces')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('rk_cities')->nullOnDelete();
            $table->tinyInteger('width')->unsigned();
            $table->tinyInteger('height')->unsigned();
            $table->enum('status', ['new', 'incomplete', 'filled', 'drawed', 'confirmed', 'closed'])->default('new');
            $table->timestamp('drawn_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('closing_snapshot')->nullable(); // photo figée (lieu, organisateur) — les lots restent en base 5 ans
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anim_rewards_grids');
    }
};
