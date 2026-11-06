<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Référentiel des icônes d'items (brief admin/content/brief-icones-items.md). Associe un
     * nom d'item normalisé à l'ID numérique de l'icône sur le CDN de Celsius Online. L'URL
     * complète est construite à la volée par ItemIcon::iconUrl() — aucun fichier image stocké.
     *
     * Table hors taxinomie données-utilisateur : aucun lien vers un compte ou un personnage.
     */
    public function up(): void
    {
        Schema::create('item_icons', function (Blueprint $table) {
            $table->id();
            // Nom normalisé tel qu'il apparaît dans les inventaires parsés. Unique insensible à
            // la casse via la collation utf8mb4_unicode_ci de MariaDB (pas besoin de LOWER()).
            $table->string('item_name')->unique();
            // ID numérique côté Celsius (ex. 50 → item50.png). Unique : deux noms différents
            // peuvent en théorie partager une icône, mais on l'interdit pour détecter les doublons.
            $table->unsignedInteger('game_item_id')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_icons');
    }
};
