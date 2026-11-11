<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->string('cash_flow_category', 30)->nullable()->after('report_group');
            $table->index('cash_flow_category', 'coa_cash_flow_category_index');
        });
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropIndex('coa_cash_flow_category_index');
            $table->dropColumn('cash_flow_category');
        });
    }
};
