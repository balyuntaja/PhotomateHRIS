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
        // 1. Pengaturan Tarif Standar HPP per Lembar untuk Booth (Janus, Golio)
        Schema::create('booth_session_hpp_settings', function (Blueprint $table) {
            $table->id();
            $table->string('booth_type', 30)->unique();
            $table->decimal('default_rate_per_sheet', 12, 2)->default(0);
            $table->string('updated_by', 36)->nullable();
            $table->timestamps();
        });

        // 2. Data HPP Rekap Sesi per Booth (Janus & Golio)
        Schema::create('booth_session_hpp', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_report_id')->unique();
            $table->string('booth_type', 30)->index();
            $table->string('cabang_id', 10)->nullable()->index();
            $table->date('report_date')->index();
            $table->integer('total_sheets')->default(0);
            $table->integer('total_sessions')->default(0);
            $table->decimal('revenue', 15, 2)->default(0);
            $table->decimal('hpp_rate_per_sheet', 12, 2)->nullable();
            $table->decimal('total_hpp', 15, 2)->default(0);
            $table->string('status', 20)->default('pending')->index(); // pending, calculated, confirmed, posted
            $table->string('reconciliation_status', 20)->default('balanced')->index(); // balanced, unbalanced
            $table->text('notes')->nullable();
            $table->string('confirmed_by', 36)->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->unsignedBigInteger('financial_transaction_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('session_report_id')
                ->references('id')
                ->on('session_reports')
                ->cascadeOnDelete();
        });

        // 3. Jejak Audit Perubahan HPP Booth
        Schema::create('booth_hpp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booth_session_hpp_id')->index();
            $table->string('user_id', 36)->nullable();
            $table->string('action', 50); // rate_updated, confirmed, reopened, posted, reversed
            $table->text('reason')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->foreign('booth_session_hpp_id')
                ->references('id')
                ->on('booth_session_hpp')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booth_hpp_audit_logs');
        Schema::dropIfExists('booth_session_hpp');
        Schema::dropIfExists('booth_session_hpp_settings');
    }
};
