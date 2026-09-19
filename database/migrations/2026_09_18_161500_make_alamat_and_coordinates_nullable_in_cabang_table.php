<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cabang', function (Blueprint $table) {
            $table->string('alamat', 255)->nullable()->change();
            $table->double('latitude')->nullable()->change();
            $table->double('longitude')->nullable()->change();
            $table->integer('radius_lokasi')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cabang', function (Blueprint $table) {
            $table->string('alamat', 50)->nullable(false)->change();
            $table->double('latitude')->nullable(false)->change();
            $table->double('longitude')->nullable(false)->change();
            $table->integer('radius_lokasi')->nullable(false)->change();
        });
    }
};
