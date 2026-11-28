<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('express_events') && ! Schema::hasColumn('express_events', 'operational_type')) {
            Schema::table('express_events', function (Blueprint $table) {
                $table->string('operational_type', 30)
                    ->nullable()
                    ->after('event_name')
                    ->index()
                    ->comment('sharing_profit, self_run, sewa, express');
            });
        }

        if (! Schema::hasTable('express_event_invoices')) {
            Schema::create('express_event_invoices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('express_event_id')->constrained('express_events')->cascadeOnDelete();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->string('linked_by', 5)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique('invoice_id', 'express_event_invoices_invoice_unique');
                $table->index('express_event_id');
                $table->foreign('linked_by')->references('karyawan_id')->on('karyawan')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('express_event_invoices');

        if (Schema::hasTable('express_events') && Schema::hasColumn('express_events', 'operational_type')) {
            Schema::table('express_events', function (Blueprint $table) {
                $table->dropIndex(['operational_type']);
                $table->dropColumn('operational_type');
            });
        }
    }
};
