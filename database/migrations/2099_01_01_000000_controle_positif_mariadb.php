<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CONTRÔLE POSITIF (brief admin/content/brief-mariadb-dev.md §7) — branche jetable, JAMAIS mergée.
// SQLite accepte un VARCHAR(20000) ; MariaDB le refuse (1074). Le job `migrations` doit rougir.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controle_positif', function (Blueprint $table) {
            $table->id();
            $table->string('trop_long', 20000);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controle_positif');
    }
};
