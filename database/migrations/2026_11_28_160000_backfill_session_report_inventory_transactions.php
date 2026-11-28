<?php

use App\Services\SessionReportInventoryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('inventory_items')
            || ! Schema::hasTable('inventory_stocks')
            || ! Schema::hasTable('inventory_transactions')
            || ! Schema::hasTable('session_reports')
            || ! Schema::hasColumn('inventory_transactions', 'session_report_id')
        ) {
            return;
        }

        // Sinkronkan seluruh rekap sesi Express & Newspaper Janus berstatus Approved
        // dengan Tanggal Sesi mulai 7 November 2026 yang sudah ada sebelum fitur ini dirilis.
        app(SessionReportInventoryService::class)->syncEligibleReports();
    }

    public function down(): void
    {
        // Data riwayat transaksi stok dipertahankan demi jejak audit.
    }
};
