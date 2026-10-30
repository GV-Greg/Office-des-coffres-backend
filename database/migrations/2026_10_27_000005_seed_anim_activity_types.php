<?php

use Database\Seeders\AnimActivityTypeSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Sème le type `decode` en appelant le seeder IDEMPOTENT — même couplage voulu que
     * seed_council_offices : le déploiement ne lance ni migration ni seeder, et sans ce type
     * personne ne pourrait créer d'activité. Ne pas « nettoyer ».
     */
    public function up(): void
    {
        (new AnimActivityTypeSeeder)->run();
    }

    public function down(): void
    {
        // Rien : la table disparaît avec sa propre migration.
    }
};
