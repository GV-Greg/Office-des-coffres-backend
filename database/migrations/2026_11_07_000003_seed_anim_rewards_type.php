<?php

use Database\Seeders\AnimActivityTypeSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Sème le type « rewards » (Grille des lots) — idempotent via firstOrCreate. */
    public function up(): void
    {
        (new AnimActivityTypeSeeder)->run();
    }

    public function down(): void {}
};
