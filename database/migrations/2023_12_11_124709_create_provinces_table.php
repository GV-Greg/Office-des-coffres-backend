<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rk_provinces', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kingdom_id');
            $table->string('province_name', 190);
            $table->timestamps();

            $table->foreign('kingdom_id')->references('id')->on('rk_kingdoms')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rk_provinces');
    }
};
