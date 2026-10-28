<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module Animation — types d'activité (brief admin/modules/animation/brief-coffres-scelles.md ;
     * fil admin/echanges/animation-coffres-scelles). Référentiel sans lien vers un compte : `key` est
     * la clé de traduction (`decode` aujourd'hui, `grid` et `chicken_race` plus tard), `is_active`
     * permet à l'administration de masquer un type sans le supprimer.
     */
    public function up(): void
    {
        Schema::create('anim_activity_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anim_activity_types');
    }
};
