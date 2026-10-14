<?php

namespace App\Services;

use App\Models\Cabang;
use App\Models\Karyawan;
use App\Models\SessionReport;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BranchBonusService
{
    public const TARGET_SESSIONS = 30; // 30 Sesi (transaksi sesi foto)
    public const TOTAL_BONUS_AMOUNT = 50000;
    public const ELIGIBLE_BRANCH_NAME = 'Newspaper Janus';
    public const EXCLUDED_BRANCH_NAME = 'Photomate Express';

    /**
     * Status rekap yang boleh menyumbang total sesi harian.
     * Approval adalah syarat pencatatan operasional, sehingga rekap DRAFT,
     * WAITING_APPROVAL, dan REVISION tidak dihitung sebagai capaian harian.
     */
    public const COUNTED_STATUSES = [SessionReport::STATUS_APPROVED];

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
     * Rekap yang memenuhi syarat bonus pada cabang & tanggal tertentu.
     * Hanya rekap berstatus APPROVED yang dihitung, dan setiap rekap muncul satu kali.
     *
     * @return Collection<int, SessionReport>
     */
    public function reportsForDay(?string $cabangId, $reportDate, ?int $excludeReportId = null): Collection
    {
        $date = $this->normalizeDate($reportDate);

        if (! $date || blank($cabangId)) {
            return collect();
        }

        return SessionReport::query()
            ->where('cabang_id', $cabangId)
            ->whereDate('report_date', $date)
            ->whereIn('status', self::COUNTED_STATUSES)
            ->when($excludeReportId, fn ($query) => $query->where('id', '!=', $excludeReportId))
            ->with('crews')
            ->get([
                'id',
                'report_number',
                'status',
                'cabang_id',
                'report_date',
                'total_transactions',
                'total_sessions',
                'bonus_amount',
            ]);
    }

    /**
     * Rekap yang ikut dihitung saat mengevaluasi satu rekap: rekap memenuhi syarat
     * pada hari yang sama, ditambah rekap yang sedang dievaluasi itu sendiri.
     *
     * Rekap yang sedang dievaluasi dikeluarkan lebih dulu dari query lalu ditambahkan
     * sekali, sehingga tidak mungkin terhitung dua kali.
     *
     * @return Collection<int, SessionReport>
     */
    public function dailyReports(SessionReport $report): Collection
    {
        $others = $this->reportsForDay($report->cabang_id, $report->report_date, $report->id ?: null);

        return $others->prepend($report)->values();
    }

    /**
     * Total sesi harian (jumlah SESI = kolom total_transactions) dari rekap yang memenuhi syarat.
     * Dipakai halaman detail rekap maupun form input agar keduanya memakai sumber yang sama.
     */
    public function dailySessionTotalFor(
        ?string $cabangId,
        $reportDate,
        int $sessionsFromCurrentInput = 0,
        ?int $excludeReportId = null
    ): int {
        $recorded = (int) $this->reportsForDay($cabangId, $reportDate, $excludeReportId)
            ->sum('total_transactions');

        return $recorded + $sessionsFromCurrentInput;
    }

    /**
     * Calculate bonus for a given SessionReport record, accounting for all sessions on that day.
     * Target 30 sesi dihitung dari total SESI (kolom total_transactions) yang sudah disetujui.
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
                'day_total_sheets' => (int) $report->total_sessions,
                'day_report_count' => 1,
                'day_reports' => [],
                'target_reached' => false,
                'remaining_sessions' => self::TARGET_SESSIONS,
                'total_bonus' => 0,
                'crew_count' => $report->crews()->count(),
                'bonus_per_crew' => 0,
                'bonus_per_crew_exact' => 0.0,
                'crew_breakdown' => [],
            ];
        }

        $dailyReports = $this->dailyReports($report);

        $dayTotalSessions = 0;
        $dayTotalSheets = 0;

        foreach ($dailyReports as $dailyReport) {
            $dayTotalSessions += (int) $dailyReport->total_transactions; // Sesi
            $dayTotalSheets += (int) $dailyReport->total_sessions;       // Lembar
        }

        // Bonus harian adalah satu pool Rp 50.000 per hari, dibagi ke seluruh crew
        // yang bertugas pada rekap-rekap yang memenuhi syarat di hari tersebut.
        $eligibleCrews = $dailyReports
            ->flatMap(fn (SessionReport $dailyReport) => $dailyReport->crews)
            ->unique('karyawan_id')
            ->values();

        $crewCount = $eligibleCrews->count();
        $targetReached = $dayTotalSessions >= self::TARGET_SESSIONS;
        $totalBonus = $targetReached ? self::TOTAL_BONUS_AMOUNT : 0;

        $bonusPerCrewExact = ($targetReached && $crewCount > 0)
            ? (self::TOTAL_BONUS_AMOUNT / $crewCount)
            : 0;

        $bonusPerCrewRounded = (int) round($bonusPerCrewExact);

        $crewBreakdown = $eligibleCrews
            ->map(fn (Karyawan $crew): array => [
                'karyawan_id' => $crew->karyawan_id,
                'nama_lengkap' => $crew->nama_lengkap,
                'bonus' => $bonusPerCrewRounded,
                'bonus_exact' => $bonusPerCrewExact,
            ])
            ->all();

        $dayReports = $dailyReports
            ->map(fn (SessionReport $dailyReport): array => [
                'report_number' => $dailyReport->report_number,
                'status' => $dailyReport->status,
                'sessions' => (int) $dailyReport->total_transactions,
                'sheets' => (int) $dailyReport->total_sessions,
                'is_current' => $dailyReport->is($report),
            ])
            ->all();

        return [
            'is_eligible_branch' => true,
            'target_sessions' => self::TARGET_SESSIONS,
            'total_sessions' => (int) $report->total_transactions, // Sesi pada report ini
            'total_sheets' => (int) $report->total_sessions,         // Lembar pada report ini
            'day_total_sessions' => $dayTotalSessions,               // Sesi harian cabang
            'day_total_sheets' => $dayTotalSheets,                   // Lembar harian cabang
            'day_report_count' => count($dayReports),
            'day_reports' => $dayReports,                            // Rincian rekap harian
            'target_reached' => $targetReached,
            'remaining_sessions' => max(0, self::TARGET_SESSIONS - $dayTotalSessions),
            'total_bonus' => $totalBonus,
            'crew_count' => $crewCount,
            'bonus_per_crew' => $bonusPerCrewRounded,
            'bonus_per_crew_exact' => $bonusPerCrewExact,
            'crew_breakdown' => $crewBreakdown,
        ];
    }

    /**
     * Selaraskan kolom bonus_amount seluruh rekap yang sudah disetujui pada hari yang sama
     * agar daftar/tabel rekap menampilkan angka yang identik dengan halaman detail.
     *
     * @return int jumlah rekap yang nilainya diperbarui
     */
    public function syncDailyBonusAmounts(SessionReport $report): int
    {
        if (! $report->exists || ! $this->isEligibleBranch($report->cabang)) {
            return 0;
        }

        $updated = 0;

        foreach ($this->reportsForDay($report->cabang_id, $report->report_date) as $dailyReport) {
            $expected = $this->calculateBonusForReport($dailyReport)['total_bonus'];

            if ((int) $dailyReport->bonus_amount === (int) $expected) {
                continue;
            }

            $dailyReport->bonus_amount = $expected;
            $dailyReport->saveQuietly();
            $updated++;
        }

        return $updated;
    }

    private function normalizeDate($date): ?string
    {
        if (blank($date)) {
            return null;
        }

        return $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : Carbon::parse($date)->toDateString();
    }
}
