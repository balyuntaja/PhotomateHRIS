<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('session_reports', function (Blueprint $table) {
            $table->unsignedInteger('total_cash_transactions')->default(0)->after('total_cash_sessions');
            $table->unsignedInteger('total_qris_transactions')->default(0)->after('total_qris_sessions');
        });

        // Backfill jumlah transaksi per metode untuk rekap yang sudah ada
        DB::table('session_reports')
            ->select('id')
            ->orderBy('id')
            ->chunk(100, function ($reports) {
                foreach ($reports as $report) {
                    $cashTransactions = DB::table('session_transactions')
                        ->where('session_report_id', $report->id)
                        ->whereRaw("UPPER(payment_method) <> 'QRIS'")
                        ->count();

                    $qrisTransactions = DB::table('session_transactions')
                        ->where('session_report_id', $report->id)
                        ->whereRaw("UPPER(payment_method) = 'QRIS'")
                        ->count();

                    DB::table('session_reports')->where('id', $report->id)->update([
                        'total_cash_transactions' => $cashTransactions,
                        'total_qris_transactions' => $qrisTransactions,
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('session_reports', function (Blueprint $table) {
            $table->dropColumn([
                'total_cash_transactions',
                'total_qris_transactions',
            ]);
        });
    }
};
