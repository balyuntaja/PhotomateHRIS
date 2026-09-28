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
        Schema::table('session_reports', function (Blueprint $table) {
            // Input manual khusus cabang Wedding Photomate (tanpa detail sesi per transaksi)
            $table->unsignedInteger('wedding_total_sessions')->nullable()->after('cabang_id');
            $table->unsignedInteger('wedding_total_sheets')->nullable()->after('wedding_total_sessions');

            // Evidence / bukti pendukung (opsional, berlaku untuk semua cabang)
            $table->json('evidence_files')->nullable()->after('revision_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('session_reports', function (Blueprint $table) {
            $table->dropColumn([
                'wedding_total_sessions',
                'wedding_total_sheets',
                'evidence_files',
            ]);
        });
    }
};
