<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('luminash_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('file_name');
            $table->string('file_path')->nullable();
            $table->string('cabang_id', 5)->nullable();
            $table->string('imported_by', 5)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('excluded_incomplete_count')->default(0);
            $table->unsignedInteger('excluded_voucher_count')->default(0);
            $table->unsignedInteger('excluded_unknown_status_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedBigInteger('total_imported_amount')->default(0);
            $table->timestamps();

            $table->foreign('cabang_id')->references('cabang_id')->on('cabang')->nullOnDelete();
            $table->foreign('imported_by')->references('karyawan_id')->on('karyawan')->nullOnDelete();
        });

        Schema::create('luminash_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('luminash_import_batches')->cascadeOnDelete();
            $table->string('order_id', 50)->index();
            $table->dateTime('transaction_at')->nullable();
            $table->string('booth_name', 100)->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->unsignedBigInteger('amount')->nullable();
            $table->unsignedSmallInteger('print_count')->nullable();
            $table->unsignedSmallInteger('extra_prints')->nullable();
            $table->string('source_status', 30)->nullable();
            $table->string('frame_name', 100)->nullable();
            $table->string('voucher_code', 50)->nullable();
            $table->string('result', 30)->index();
            $table->string('reason')->nullable();
            $table->foreignId('financial_transaction_id')->nullable()->constrained('financial_transactions')->nullOnDelete();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('luminash_import_rows');
        Schema::dropIfExists('luminash_import_batches');
    }
};
