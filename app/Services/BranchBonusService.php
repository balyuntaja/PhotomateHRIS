<?php

namespace App\Services;

use App\Models\Cabang;
use App\Models\SessionReport;
use Carbon\Carbon;

class BranchBonusService
{
    public const TARGET_SESSIONS = 30; // 30 Sesi (transaksi sesi foto)
    public const TOTAL_BONUS_AMOUNT = 50000;
    public const ELIGIBLE_BRANCH_NAME = 'Newspaper Janus';
    public const EXCLUDED_BRANCH_NAME = 'Photomate Express';

    /**
     * Check if the specified cabang is eligible for the bonus (Newspaper Janus ONLY).
     */
    public function isEligibleBranch(Cabang|string|null $cabang, ?string $cabangId = null): bool
    {
        if (!$cabang && $cabangId) {
            $cabang = Cabang::find($cabangId);
        }

        if (is_string($cabang)) {
            $cabang = Cabang::find($cabang);
        }

        if (!$cabang) {
            return false;
        }

        $namaCabang = strtolower(trim($cabang->nama_cabang));

        // Photomate Express is explicitly NOT eligible
        if (str_contains($namaCabang, 'express')) {
            return false;
        }

        // Only Newspaper Janus is eligible
        return str_contains($namaCabang, 'janus') || str_contains($namaCabang, 'newspaper');
    }

    /**
     * Calculate daily bonus summary from total sessions (transaksi), crew count, and cabang.
     */
    public function calculateDailyBonus(
        int $totalSessions,
        int $crewCount,
        Cabang|string|null $cabang = null,
        ?string $cabangId = null,
        int $totalSheets = 0
    ): array {
        $isEligible = $this->isEligibleBranch($cabang, $cabangId);
        $target = self::TARGET_SESSIONS;
        $targetReached = $isEligible && ($totalSessions >= $target);
        $totalBonus = $targetReached ? self::TOTAL_BONUS_AMOUNT : 0;
        $remainingSessions = max(0, $target - $totalSessions);

        $bonusPerCrewExact = ($targetReached && $crewCount > 0)
            ? (self::TOTAL_BONUS_AMOUNT / $crewCount)
            : 0;

        $bonusPerCrewRounded = (int) round($bonusPerCrewExact);

        return [
            'is_eligible_branch' => $isEligible,
            'target_sessions' => $target,
            'total_sessions' => $totalSessions, // Jumlah Sesi (transaksi)
            'total_sheets' => $totalSheets,     // Jumlah Lembar (cetakan)
            'target_reached' => $targetReached,
            'remaining_sessions' => $remainingSessions,
            'total_bonus' => $totalBonus,
            'crew_count' => $crewCount,
            'bonus_per_crew' => $bonusPerCrewRounded,
            'bonus_per_crew_exact' => $bonusPerCrewExact,
        ];
    }

    /**
     * Calculate bonus for a given SessionReport record, accounting for all sessions on that day.
     * Target 30 sesi dihitung berdasarkan total transaksi / sesi pemotretan yang dikerjakan.
     */
    public function calculateBonusForReport(SessionReport $report): array
    {
        $isEligible = $this->isEligibleBranch($report->cabang);

        if (!$isEligible) {
            return [
                'is_eligible_branch' => false,
                'target_sessions' => self::TARGET_SESSIONS,
                'total_sessions' => (int) $report->total_transactions,
                'total_sheets' => (int) $report->total_sessions,
                'day_total_sessions' => (int) $report->total_transactions,
                'target_reached' => false,
                'remaining_sessions' => self::TARGET_SESSIONS,
                'total_bonus' => 0,
                'crew_count' => $report->crews()->count(),
                'bonus_per_crew' => 0,
                'bonus_per_crew_exact' => 0.0,
                'crew_breakdown' => [],
            ];
        }

        // Calculate total sessions (transaksi) for Newspaper Janus on this report date
        $reportDate = $report->report_date ? Carbon::parse($report->report_date)->toDateString() : null;
        $dayTotalSessions = (int) $report->total_transactions;
        $dayTotalSheets = (int) $report->total_sessions;

        if ($reportDate && $report->cabang_id) {
            $otherReportsQuery = SessionReport::where('cabang_id', $report->cabang_id)
                ->whereDate('report_date', $reportDate);

            if ($report->exists) {
                $otherReportsQuery->where('id', '!=', $report->id);
            }

            $otherReports = $otherReportsQuery
                ->whereIn('status', [
                    SessionReport::STATUS_APPROVED,
                    SessionReport::STATUS_WAITING_APPROVAL,
                    SessionReport::STATUS_DRAFT,
                ])
                ->get(['total_transactions', 'total_sessions']);

            $dayTotalSessions += (int) $otherReports->sum('total_transactions');
            $dayTotalSheets += (int) $otherReports->sum('total_sessions');
        }

        $targetReached = $dayTotalSessions >= self::TARGET_SESSIONS;
        $totalBonus = $targetReached ? self::TOTAL_BONUS_AMOUNT : 0;
        $crews = $report->crews;
        $crewCount = $crews->count();

        $bonusPerCrewExact = ($targetReached && $crewCount > 0)
            ? (self::TOTAL_BONUS_AMOUNT / $crewCount)
            : 0;

        $bonusPerCrewRounded = (int) round($bonusPerCrewExact);

        $crewBreakdown = [];
        foreach ($crews as $crew) {
            $crewBreakdown[] = [
                'karyawan_id' => $crew->karyawan_id,
                'nama_lengkap' => $crew->nama_lengkap,
                'bonus' => $bonusPerCrewRounded,
                'bonus_exact' => $bonusPerCrewExact,
            ];
        }

        return [
            'is_eligible_branch' => true,
            'target_sessions' => self::TARGET_SESSIONS,
            'total_sessions' => (int) $report->total_transactions, // Sesi pada report ini
            'total_sheets' => (int) $report->total_sessions,         // Lembar pada report ini
            'day_total_sessions' => $dayTotalSessions,               // Sesi harian cabang
            'day_total_sheets' => $dayTotalSheets,                   // Lembar harian cabang
            'target_reached' => $targetReached,
            'remaining_sessions' => max(0, self::TARGET_SESSIONS - $dayTotalSessions),
            'total_bonus' => $totalBonus,
            'crew_count' => $crewCount,
            'bonus_per_crew' => $bonusPerCrewRounded,
            'bonus_per_crew_exact' => $bonusPerCrewExact,
            'crew_breakdown' => $crewBreakdown,
        ];
    }
}
