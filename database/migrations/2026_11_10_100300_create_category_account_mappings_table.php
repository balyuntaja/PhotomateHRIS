<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_account_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_category_id')->nullable()
                ->constrained('financial_categories')->cascadeOnDelete();
            $table->foreignId('financial_parent_category_id')->nullable()
                ->constrained('financial_parent_categories')->cascadeOnDelete();
            $table->string('transaction_type', 20);
            $table->foreignId('debit_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('credit_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('financial_category_id');
            $table->unique('financial_parent_category_id');
            $table->index(['transaction_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_account_mappings');
    }
};
