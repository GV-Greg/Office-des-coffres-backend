<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Référentiel des postes titrés d'un conseil comtal. Pas de libellé en base : les libellés
     * vivent dans lang/{fr,en}/mandates.php, relevés en jeu (jamais traduits). Rattachée à aucun
     * compte : rien à déclarer au garde-fou du cycle de vie.
     */
    public function up(): void
    {
        Schema::create('council_offices', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedTinyInteger('position');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('council_offices');
    }
};
