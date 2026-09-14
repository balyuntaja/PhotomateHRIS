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
        // 1. Pricing Settings
        Schema::create('pricing_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Standard Pricing');
            $table->decimal('base_price', 15, 2)->default(30000);
            $table->decimal('additional_session_price', 15, 2)->default(15000);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Session Reports
        Schema::create('session_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_number', 50)->unique();
            $table->date('report_date')->index();
            $table->string('status', 30)->default('DRAFT')->index(); // DRAFT, WAITING_APPROVAL, REVISION, APPROVED, REJECTED
            $table->text('event_note')->nullable();

            // Optional relationships to existing modules (Invoices / Booking & Events)
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();

            // Cached summary fields (recalculated on every save)
            $table->unsignedInteger('total_sessions')->default(0);
            $table->unsignedInteger('total_transactions')->default(0);
            $table->decimal('total_cash_amount', 15, 2)->default(0);
            $table->unsignedInteger('total_cash_sessions')->default(0);
            $table->decimal('total_qris_amount', 15, 2)->default(0);
            $table->unsignedInteger('total_qris_sessions')->default(0);
            $table->decimal('grand_total_amount', 15, 2)->default(0);

            // Validation logic status
            $table->string('validation_status', 20)->default('VALID'); // VALID, HAS_ISSUE
            $table->json('validation_issues')->nullable();

            // Workflow audit fields
            $table->string('submitted_by', 5)->nullable()->index();
            $table->timestamp('submitted_at')->nullable();
            $table->string('approved_by', 5)->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->text('revision_note')->nullable();

            $table->timestamps();

            $table->foreign('submitted_by')->references('karyawan_id')->on('karyawan')->nullOnDelete();
            $table->foreign('approved_by')->references('karyawan_id')->on('karyawan')->nullOnDelete();
        });

        // 3. Session Report Crews (Many-to-Many between session_reports and karyawan)
        Schema::create('session_report_crews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_report_id')->constrained('session_reports')->cascadeOnDelete();
            $table->string('karyawan_id', 5);
            $table->timestamps();

            $table->foreign('karyawan_id')->references('karyawan_id')->on('karyawan')->cascadeOnDelete();
            $table->unique(['session_report_id', 'karyawan_id']);
        });

        // 4. Session Transactions
        Schema::create('session_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_report_id')->constrained('session_reports')->cascadeOnDelete();
            $table->string('payment_method', 20)->default('CASH')->index(); // CASH, QRIS
            $table->unsignedInteger('session_count')->default(1);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        // 5. Approval Logs
        Schema::create('approval_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_report_id')->constrained('session_reports')->cascadeOnDelete();
            $table->string('user_id', 5);
            $table->string('action', 50); // DRAFT_CREATED, DRAFT_UPDATED, SUBMITTED, REVISION_REQUESTED, RESUBMITTED, APPROVED, REJECTED
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('karyawan_id')->on('karyawan')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_logs');
        Schema::dropIfExists('session_transactions');
        Schema::dropIfExists('session_report_crews');
        Schema::dropIfExists('session_reports');
        Schema::dropIfExists('pricing_settings');
    }
};
