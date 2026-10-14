<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('luminash_import_rows', function (Blueprint $table) {
            $table->foreignId('session_report_id')
                ->nullable()
                ->after('financial_transaction_id')
                ->constrained('session_reports')
                ->nullOnDelete();
        });

        Schema::table('luminash_import_batches', function (Blueprint $table) {
            $table->unsignedInteger('session_report_count')->default(0)->after('invalid_count');
        });
    }

    public function down(): void
    {
        Schema::table('luminash_import_rows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('session_report_id');
        });

        Schema::table('luminash_import_batches', function (Blueprint $table) {
            $table->dropColumn('session_report_count');
        });
    }
};
