<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_remediation_plans', function (Blueprint $table) {
            $table->json('approved_plan_snapshot')->nullable()->after('approval_note');
            $table->json('approved_transaction_snapshot')->nullable()->after('approved_plan_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_remediation_plans', function (Blueprint $table) {
            $table->dropColumn(['approved_plan_snapshot', 'approved_transaction_snapshot']);
        });
    }
};
