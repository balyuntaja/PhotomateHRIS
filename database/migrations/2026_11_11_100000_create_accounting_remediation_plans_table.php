<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_remediation_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_transaction_id')
                ->constrained('financial_transactions')
                ->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('original_category_id')->nullable()
                ->constrained('financial_categories')->nullOnDelete();
            $table->foreignId('proposed_category_id')->nullable()
                ->constrained('financial_categories')->nullOnDelete();
            $table->string('proposed_category_name', 100)->nullable();

            $table->foreignId('original_account_id')->nullable()
                ->constrained('financial_accounts')->nullOnDelete();
            $table->foreignId('proposed_account_id')->nullable()
                ->constrained('financial_accounts')->nullOnDelete();

            $table->string('proposed_coa_code', 20)->nullable();
            $table->string('accounting_treatment', 30)->nullable();
            $table->text('reason')->nullable();
            $table->text('evidence')->nullable();
            $table->boolean('requires_mapping_change')->default(false);

            $table->string('status', 30)->default('NEED_BUSINESS_CONFIRMATION')->index();
            $table->string('approved_by', 5)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->string('applied_by', 5)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->json('apply_result')->nullable();
            $table->string('created_by', 5)->nullable();

            $table->timestamps();

            $table->unique(['financial_transaction_id', 'version'], 'remediation_plan_transaction_version_unique');
            $table->index(['status', 'financial_transaction_id'], 'remediation_plan_status_transaction_index');

            $table->foreign('approved_by')->references('karyawan_id')->on('karyawan')->nullOnDelete();
            $table->foreign('applied_by')->references('karyawan_id')->on('karyawan')->nullOnDelete();
            $table->foreign('created_by')->references('karyawan_id')->on('karyawan')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_remediation_plans');
    }
};
