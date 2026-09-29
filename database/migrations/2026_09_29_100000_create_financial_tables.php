<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('group_name', 100)->nullable()->comment('Grup kategori, contoh: Operasional, SDM, Marketing');
            $table->string('transaction_type', 20)->index()->comment('income, expense');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['name', 'transaction_type']);
            $table->index(['transaction_type', 'is_active']);
        });

        Schema::create('financial_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_type', 20)->index()->comment('income, expense');
            $table->unsignedBigInteger('amount')->comment('Nominal IDR positif, efek ke saldo ditentukan jenis transaksi');
            $table->foreignId('category_id')->constrained('financial_categories')->restrictOnDelete();
            $table->string('cabang_id', 5)->nullable();
            $table->foreignId('payment_method_id')->nullable()->constrained('financial_payment_methods')->restrictOnDelete();
            $table->date('transaction_date')->index()->comment('Tanggal transaksi terjadi, berbeda dengan created_at');
            $table->text('description')->nullable();
            $table->string('evidence_path', 255)->nullable();
            $table->string('source_type', 50)->nullable()->comment('Disiapkan untuk integrasi Phase 2 (invoice, session_recap, dll)');
            $table->string('source_id', 50)->nullable()->comment('Disiapkan untuk integrasi Phase 2');
            $table->string('created_by', 5)->nullable();
            $table->string('updated_by', 5)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('cabang_id')->references('cabang_id')->on('cabang')->nullOnDelete();
            $table->foreign('created_by')->references('karyawan_id')->on('karyawan')->nullOnDelete();
            $table->foreign('updated_by')->references('karyawan_id')->on('karyawan')->nullOnDelete();

            $table->index(['transaction_date', 'transaction_type']);
            $table->index(['cabang_id', 'transaction_date']);
        });

        Schema::create('financial_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_transaction_id')->constrained('financial_transactions')->cascadeOnDelete();
            $table->string('user_id', 5)->nullable();
            $table->string('action', 20)->comment('CREATED, UPDATED, DELETED, RESTORED');
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('karyawan_id')->on('karyawan')->nullOnDelete();
            $table->index(['financial_transaction_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_audit_logs');
        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('financial_payment_methods');
        Schema::dropIfExists('financial_categories');
    }
};
