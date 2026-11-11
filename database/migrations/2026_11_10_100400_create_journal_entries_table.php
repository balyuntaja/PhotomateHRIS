<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('journal_number', 30)->unique();
            $table->date('journal_date')->index();
            $table->text('description')->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->string('reference_id', 100)->nullable();
            $table->string('status', 10)->default('draft')->index();
            $table->foreignId('fiscal_period_id')->constrained('fiscal_periods')->restrictOnDelete();
            $table->decimal('total_debit', 18, 2)->default(0);
            $table->decimal('total_credit', 18, 2)->default(0);
            $table->timestamp('posted_at')->nullable();
            $table->string('created_by', 5)->nullable();
            $table->string('posted_by', 5)->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->foreignId('reversal_of_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->index(['status', 'journal_date']);

            $table->foreign('created_by')
                ->references('karyawan_id')
                ->on('karyawan')
                ->nullOnDelete();

            $table->foreign('posted_by')
                ->references('karyawan_id')
                ->on('karyawan')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
