<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->string('submitted_by', 5)->nullable()->after('updated_by');
            $table->string('submitted_by_name', 50)->nullable()->after('submitted_by');
            $table->foreign('submitted_by')->references('karyawan_id')->on('karyawan')->nullOnDelete();
            $table->index('submitted_by');
        });
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->dropForeign(['submitted_by']);
            $table->dropIndex(['submitted_by']);
            $table->dropColumn(['submitted_by', 'submitted_by_name']);
        });
    }
};
