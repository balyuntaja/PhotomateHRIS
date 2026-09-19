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
            $table->decimal('bonus_amount', 15, 2)->default(0)->after('grand_total_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('session_reports', function (Blueprint $table) {
            $table->dropColumn('bonus_amount');
        });
    }
};
