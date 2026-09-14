<?php

namespace App\Services;

use App\Models\PricingSetting;
use App\Models\SessionReport;
use App\Models\SessionTransaction;
use Carbon\Carbon;
use InvalidArgumentException;

class PricingService
{
    /**
     * Calculate nominal price for a given session count.
     * Business rule:
     * - Minimum 1 session = Rp 30.000
     * - Each additional session = Rp 15.000
     * Formula: basePrice + ((sessionCount - 1) * additionalPrice)
     */
    public function calculateSessionPrice(int $sessionCount, $date = null): int
    {
        if ($sessionCount < 1) {
            throw new InvalidArgumentException('Jumlah sesi minimal harus 1.');
        }

        $carbonDate = $date ? Carbon::parse($date) : now();
        $pricing = PricingSetting::getActiveForDate($carbonDate);

        $basePrice = $pricing ? (int) $pricing->base_price : 30000;
        $additionalPrice = $pricing ? (int) $pricing->additional_session_price : 15000;

        return $basePrice + (($sessionCount - 1) * $additionalPrice);
    }

    /**
     * Recalculate transaction amounts and report totals from backend source of truth.
     */
    public function recalculateReport(SessionReport $report): void
    {
        $transactions = $report->transactions()->get();

        $totalSessions = 0;
        $totalTransactions = $transactions->count();
        $totalCashAmount = 0;
        $totalCashSessions = 0;
        $totalQrisAmount = 0;
        $totalQrisSessions = 0;
        $issues = [];

        foreach ($transactions as $idx => $trx) {
            $sessionCount = (int) $trx->session_count;
            if ($sessionCount < 1) {
                $issues[] = "Transaksi #" . ($idx + 1) . ": Jumlah sesi tidak valid ($sessionCount).";
                $sessionCount = 1;
            }

            // Always recalculate from backend pricing engine
            $recalculatedAmount = $this->calculateSessionPrice($sessionCount, $report->report_date);
            if ($trx->amount !== $recalculatedAmount) {
                $trx->amount = $recalculatedAmount;
                $trx->saveQuietly();
            }

            $totalSessions += $sessionCount;

            if (strtoupper($trx->payment_method) === 'QRIS') {
                $totalQrisSessions += $sessionCount;
                $totalQrisAmount += $recalculatedAmount;
            } else {
                $totalCashSessions += $sessionCount;
                $totalCashAmount += $recalculatedAmount;
            }
        }

        $grandTotalAmount = $totalCashAmount + $totalQrisAmount;

        // Validation status check
        if ($totalTransactions === 0) {
            $issues[] = "Rekap sesi wajib memiliki minimal satu transaksi.";
        }
        if ($totalSessions === 0) {
            $issues[] = "Total sesi tidak boleh 0.";
        }
        if (!$report->crews()->exists()) {
            $issues[] = "Rekap sesi wajib memiliki minimal satu crew.";
        }

        $report->total_sessions = $totalSessions;
        $report->total_transactions = $totalTransactions;
        $report->total_cash_amount = $totalCashAmount;
        $report->total_cash_sessions = $totalCashSessions;
        $report->total_qris_amount = $totalQrisAmount;
        $report->total_qris_sessions = $totalQrisSessions;
        $report->grand_total_amount = $grandTotalAmount;
        $report->validation_status = empty($issues) ? 'VALID' : 'HAS_ISSUE';
        $report->validation_issues = $issues;
        $report->saveQuietly();
    }
}
