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
     * Calculate session count from nominal price.
     * Business rules:
     * - Rp 30.000 -> 1 sesi
     * - Rp 45.000 -> 2 sesi
     * - Rp 67.500 / Rp 70.000 -> 3 sesi
     * - Setelah 3 sesi, pola kenaikan Rp 22.500 per sesi dari harga Rp 45.000.
     *   (Rp 90.000 -> 4 sesi, Rp 112.500 -> 5 sesi, dst.)
     */
    public function calculateSessionsFromPrice(int|float $amount): int
    {
        $amount = (int) round($amount);

        if ($amount <= 0) {
            return 0;
        }

        if ($amount === 30000) {
            return 1;
        }

        if ($amount === 45000) {
            return 2;
        }

        if ($amount === 67500 || $amount === 70000) {
            return 3;
        }

        if ($amount < 45000) {
            return 1;
        }

        // For amounts >= 45.000: 2 base sessions + 1 session per 22.500 addition
        return 2 + (int) round(($amount - 45000) / 22500);
    }

    /**
     * Calculate nominal price for a given session count.
     * Kept for backwards compatibility and fallback calculation.
     * Formula:
     * - 1 session = Rp 30.000
     * - 2 sessions = Rp 45.000
     * - 3 sessions = Rp 67.500
     * - Additional sessions: 45.000 + ((sessionCount - 2) * 22.500)
     */
    public function calculateSessionPrice(int $sessionCount, $date = null): int
    {
        if ($sessionCount < 1) {
            throw new InvalidArgumentException('Jumlah sesi minimal harus 1.');
        }

        if ($sessionCount === 1) {
            return 30000;
        }

        return 45000 + (($sessionCount - 2) * 22500);
    }

    /**
     * Recalculate transaction sessions and report totals from backend source of truth.
     * Primary input is transaction amount (harga), which determines session count.
     */
    public function recalculateReport(SessionReport $report): void
    {
        // Wedding Photomate: total diambil dari input manual Jumlah Sesi & Jumlah Lembar
        if ($report->isWeddingPhotomate()) {
            $this->recalculateWeddingReport($report);

            return;
        }

        $transactions = $report->transactions()->get();

        $totalSessions = 0;
        $totalTransactions = $transactions->count();
        $totalCashAmount = 0;
        $totalCashSessions = 0;
        $totalQrisAmount = 0;
        $totalQrisSessions = 0;
        $issues = [];

        foreach ($transactions as $idx => $trx) {
            $amount = (int) round((float) $trx->amount);

            // Legacy fallback if amount was not set but session_count was
            if ($amount <= 0 && (int) $trx->session_count > 0) {
                $amount = $this->calculateSessionPrice((int) $trx->session_count, $report->report_date);
                $trx->amount = $amount;
            }

            if ($amount <= 0) {
                $issues[] = "Transaksi #" . ($idx + 1) . ": Nominal harga wajib diisi.";
                $sessionCount = 0;
            } else {
                $sessionCount = $this->calculateSessionsFromPrice($amount);
                if ($sessionCount < 1) {
                    $issues[] = "Transaksi #" . ($idx + 1) . ": Nominal Rp " . number_format($amount, 0, ',', '.') . " tidak valid untuk sesi.";
                }
            }

            // Always synchronize session_count from amount mapping
            if ($trx->session_count !== $sessionCount) {
                $trx->session_count = $sessionCount;
                $trx->saveQuietly();
            }

            $totalSessions += $sessionCount;

            if (strtoupper($trx->payment_method) === 'QRIS') {
                $totalQrisSessions += $sessionCount;
                $totalQrisAmount += $amount;
            } else {
                $totalCashSessions += $sessionCount;
                $totalCashAmount += $amount;
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

        // Bersihkan input manual Wedding bila cabang bukan Wedding Photomate
        $report->wedding_total_sessions = null;
        $report->wedding_total_sheets = null;

        // Calculate Newspaper Janus branch bonus
        $bonusService = app(\App\Services\BranchBonusService::class);
        $bonusData = $bonusService->calculateBonusForReport($report);
        $report->bonus_amount = $bonusData['total_bonus'];

        $report->saveQuietly();
    }

    /**
     * Rekap cabang Wedding Photomate: total diambil langsung dari input manual
     * Jumlah Sesi (total_transactions) dan Jumlah Lembar (total_sessions).
     */
    protected function recalculateWeddingReport(SessionReport $report): void
    {
        $totalSesi = (int) ($report->wedding_total_sessions ?? 0);
        $totalLembar = (int) ($report->wedding_total_sheets ?? 0);

        $issues = [];

        if ($totalSesi < 1) {
            $issues[] = 'Jumlah Sesi wajib diisi minimal 1.';
        }
        if ($totalLembar < 1) {
            $issues[] = 'Jumlah Lembar wajib diisi minimal 1.';
        }
        if (!$report->crews()->exists()) {
            $issues[] = 'Rekap sesi wajib memiliki minimal satu crew.';
        }

        $report->total_transactions = $totalSesi;
        $report->total_sessions = $totalLembar;
        $report->total_cash_amount = 0;
        $report->total_cash_sessions = 0;
        $report->total_qris_amount = 0;
        $report->total_qris_sessions = 0;
        $report->grand_total_amount = 0;
        $report->bonus_amount = 0;
        $report->validation_status = empty($issues) ? 'VALID' : 'HAS_ISSUE';
        $report->validation_issues = $issues;

        $report->saveQuietly();
    }
}
