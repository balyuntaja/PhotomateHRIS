<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('express_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_name', 150)->index();
            $table->date('start_date')->index();
            $table->date('end_date')->index();
            $table->unsignedBigInteger('hpp_rate_per_sheet')->nullable()->comment('Tarif HPP per lembar IDR (bisa 0)');
            $table->string('reconciliation_status', 30)->default('unallocated')->index()
                ->comment('unallocated, partial, fully_allocated, needs_review, reconciled');
            $table->string('hpp_status', 30)->default('pending')->index()
                ->comment('pending, calculated, confirmed, posted, reversed');
            $table->text('notes')->nullable();
            $table->string('created_by', 5)->nullable();
            $table->string('confirmed_by', 5)->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('finalized_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('karyawan_id')->on('karyawan')->nullOnDelete();
            $table->foreign('confirmed_by')->references('karyawan_id')->on('karyawan')->nullOnDelete();
            $table->index(['start_date', 'end_date']);
        });

        Schema::create('express_event_recaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('express_event_id')->constrained('express_events')->cascadeOnDelete();
            $table->foreignId('session_report_id')->constrained('session_reports')->restrictOnDelete();
            $table->string('status', 20)->default('active')->index()->comment('active, removed');
            $table->timestamps();

            $table->unique(['express_event_id', 'session_report_id']);
            $table->index('session_report_id');
        });

        Schema::create('express_event_recap_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('express_event_recap_id')->constrained('express_event_recaps')->cascadeOnDelete();
            $table->integer('allocated_sessions')->default(0)->comment('Jumlah sesi yang dialokasikan');
            $table->integer('allocated_sheets')->default(0)->comment('Jumlah lembar yang dialokasikan');
            $table->unsignedBigInteger('allocated_revenue')->default(0)->comment('Nominal omzet IDR yang dialokasikan');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('express_event_recap_id');
        });

        Schema::create('express_event_hpp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('express_event_id')->constrained('express_events')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('hpp_rate_per_sheet')->default(0);
            $table->integer('total_sessions')->default(0);
            $table->integer('total_sheets')->default(0);
            $table->unsignedBigInteger('total_revenue')->default(0);
            $table->unsignedBigInteger('total_hpp')->default(0);
            $table->bigInteger('gross_profit')->default(0);
            $table->string('calculation_status', 30)->default('pending')->index()
                ->comment('pending, calculated, confirmed, posted, reversed');
            $table->dateTime('calculated_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->string('confirmed_by', 5)->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->timestamps();

            $table->foreign('confirmed_by')->references('karyawan_id')->on('karyawan')->nullOnDelete();
            $table->index(['express_event_id', 'version']);
        });

        Schema::create('express_event_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('express_event_id')->constrained('express_events')->cascadeOnDelete();
            $table->string('user_id', 5)->nullable();
            $table->string('action', 50)->comment('CREATED, UPDATED, ALLOCATED, HPP_CALCULATED, CONFIRMED, REOPENED, RECONCILED');
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('karyawan_id')->on('karyawan')->nullOnDelete();
            $table->index(['express_event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('express_event_audit_logs');
        Schema::dropIfExists('express_event_hpp');
        Schema::dropIfExists('express_event_recap_allocations');
        Schema::dropIfExists('express_event_recaps');
        Schema::dropIfExists('express_events');
    }
};
