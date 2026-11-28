<?php

namespace App\Services;

use App\Models\ExpressEvent;
use App\Models\ExpressEventAuditLog;
use App\Models\ExpressEventHpp;
use App\Models\Karyawan;
use App\Models\SessionReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExpressEventHppService
{
    public function __construct(
        protected ExpressEventAllocationService $allocationService
    ) {}

    /**
     * Hitung preview perhitungan HPP berdasarkan alokasi event dan tarif yang diberikan.
     * Mendukung pemecahan per periode (bulan/tahun) untuk event lintas bulan.
     *
     * @return array{
     *     total_sessions: int,
     *     total_sheets: int,
     *     total_revenue: int,
     *     hpp_rate_per_sheet: int|null,
     *     total_hpp: int,
     *     gross_profit: int,
     *     period_breakdown: array<string, array{year: int, month: int, label: string, sessions: int, sheets: int, revenue: int, hpp: int, gross_profit: int}>
     * }
     */
    public function calculatePreview(ExpressEvent $event, ?int $rate = null): array
    {
        $event->loadMissing(['recaps.allocations', 'recaps.sessionReport.cabang', 'invoices']);

        $rate = $rate !== null ? $rate : $event->hpp_rate_per_sheet;

        $totalSessions = 0;
        $totalSheets = 0;
        $totalRevenue = 0;

        $periodBreakdown = [];

        foreach ($event->recaps as $recap) {
            if ($recap->status !== \App\Models\ExpressEventRecap::STATUS_ACTIVE) {
                continue;
            }

            $report = $recap->sessionReport;
            if (! $report || $report->status !== SessionReport::STATUS_APPROVED) {
                continue;
            }

            $reportDate = $report->report_date ?? $event->start_date ?? now();
            $periodKey = $reportDate->format('Y-m');

            if (! isset($periodBreakdown[$periodKey])) {
                $periodBreakdown[$periodKey] = [
                    'year' => (int) $reportDate->year,
                    'month' => (int) $reportDate->month,
                    'label' => $reportDate->translatedFormat('F Y'),
                    'sessions' => 0,
                    'sheets' => 0,
                    'revenue' => 0,
                    'hpp' => 0,
                    'gross_profit' => 0,
                ];
            }

            foreach ($recap->allocations as $alloc) {
                $s = (int) $alloc->allocated_sessions;
                $l = (int) $alloc->allocated_sheets;
                $r = (int) $alloc->allocated_revenue;

                $totalSessions += $s;
                $totalSheets += $l;
                $totalRevenue += $r;

                $periodBreakdown[$periodKey]['sessions'] += $s;
                $periodBreakdown[$periodKey]['sheets'] += $l;
                $periodBreakdown[$periodKey]['revenue'] += $r;
            }
        }

        // Untuk Express Self Run & Express Sewa, sumber omzet selalu total nilai kontrak invoice yang terhubung (0 bila belum ada invoice)
        if ($event->requiresInvoice()) {
            $totalRevenue = $event->total_invoice_amount;
        }

        $totalHpp = $rate !== null ? (int) ($totalSheets * $rate) : 0;
        $grossProfit = $totalRevenue - $totalHpp;

        foreach ($periodBreakdown as $k => $pb) {
            $periodHpp = $rate !== null ? (int) ($pb['sheets'] * $rate) : 0;
            $periodBreakdown[$k]['hpp'] = $periodHpp;
            $periodBreakdown[$k]['gross_profit'] = $pb['revenue'] - $periodHpp;
        }

        ksort($periodBreakdown);

        return [
            'total_sessions' => $totalSessions,
            'total_sheets' => $totalSheets,
            'total_revenue' => $totalRevenue,
            'hpp_rate_per_sheet' => $rate,
            'total_hpp' => $totalHpp,
            'gross_profit' => $grossProfit,
            'period_breakdown' => $periodBreakdown,
        ];
    }

    /**
     * Update tarif HPP per lembar event.
     */
    public function updateRate(ExpressEvent $event, int $rate, ?Karyawan $actor = null): ExpressEvent
    {
        if ($rate < 0) {
            throw new RuntimeException('Tarif HPP per lembar tidak boleh bernilai negatif.');
        }

        if ($event->isFinalized()) {
            throw new RuntimeException('Event telah finalized dan tidak dapat diubah tarifnya.');
        }

        $oldRate = $event->hpp_rate_per_sheet;

        $newHppStatus = in_array($event->hpp_status, [ExpressEvent::HPP_CONFIRMED, ExpressEvent::HPP_POSTED], true)
            ? ExpressEvent::HPP_CALCULATED
            : ExpressEvent::HPP_CALCULATED;

        $event->update([
            'hpp_rate_per_sheet' => $rate,
            'hpp_status' => $newHppStatus,
        ]);

        ExpressEventAuditLog::record(
            $event,
            'UPDATED_RATE',
            ['hpp_rate_per_sheet' => $oldRate],
            ['hpp_rate_per_sheet' => $rate],
            "Tarif HPP diubah menjadi Rp " . number_format($rate, 0, ',', '.') . "/lembar"
        );

        return $event;
    }

    /**
     * Konfirmasi perhitungan HPP event dan simpan snapshot.
     */
    public function confirmHpp(ExpressEvent $event, ?Karyawan $actor = null): ExpressEventHpp
    {
        return DB::transaction(function () use ($event, $actor) {
            $lockedEvent = ExpressEvent::where('id', $event->id)->lockForUpdate()->firstOrFail();

            if ($lockedEvent->reconciliation_status === ExpressEvent::RECON_NEEDS_REVIEW) {
                throw new RuntimeException('Event memiliki status "Perlu Ditinjau" karena ada ketidaksesuaian alokasi. Harap periksa dan selesaikan rekonsiliasi sebelum konfirmasi.');
            }

            if ($lockedEvent->reconciliation_status === ExpressEvent::RECON_UNALLOCATED) {
                throw new RuntimeException('Event belum memiliki alokasi rekap sesi yang valid.');
            }

            if ($lockedEvent->requiresInvoice() && ! $lockedEvent->hasLinkedInvoices()) {
                throw new RuntimeException('Invoice belum terhubung. Event Express Self Run dan Express Sewa wajib dihubungkan dengan minimal satu invoice sebelum HPP dapat dikonfirmasi.');
            }

            if ($lockedEvent->hpp_rate_per_sheet === null) {
                throw new RuntimeException('Tarif HPP belum ditentukan. Masukkan tarif HPP per lembar (bisa Rp 0 jika bebas biaya).');
            }

            $preview = $this->calculatePreview($lockedEvent);

            if ($preview['total_sheets'] <= 0 && $preview['total_revenue'] <= 0) {
                throw new RuntimeException('Total lembar dan omzet yang dialokasikan masih 0.');
            }

            // Pastikan integritas rekap sumber
            if (! $this->allocationService->checkReconciliationIntegrity($lockedEvent)) {
                throw new RuntimeException('Ditemukan ketidaksesuaian data pada rekap sesi sumber.');
            }

            $nextVersion = ((int) $lockedEvent->hppSnapshots()->max('version')) + 1;

            $snapshot = ExpressEventHpp::create([
                'express_event_id' => $lockedEvent->id,
                'version' => $nextVersion,
                'hpp_rate_per_sheet' => $preview['hpp_rate_per_sheet'],
                'total_sessions' => $preview['total_sessions'],
                'total_sheets' => $preview['total_sheets'],
                'total_revenue' => $preview['total_revenue'],
                'total_hpp' => $preview['total_hpp'],
                'gross_profit' => $preview['gross_profit'],
                'calculation_status' => ExpressEvent::HPP_CONFIRMED,
                'calculated_at' => now(),
                'confirmed_at' => now(),
                'confirmed_by' => $actor?->karyawan_id ?? auth()->id(),
            ]);

            $lockedEvent->update([
                'hpp_status' => ExpressEvent::HPP_CONFIRMED,
                'confirmed_at' => now(),
                'confirmed_by' => $actor?->karyawan_id ?? auth()->id(),
            ]);

            ExpressEventAuditLog::record(
                $lockedEvent,
                'CONFIRMED_HPP',
                null,
                $snapshot->toArray(),
                "HPP Event dikonfirmasi v{$nextVersion} dengan tarif Rp " . number_format($preview['hpp_rate_per_sheet'], 0, ',', '.') . " dan Total HPP Rp " . number_format($preview['total_hpp'], 0, ',', '.')
            );

            return $snapshot;
        });
    }

    /**
     * Buka kembali event yang sudah dikonfirmasi/diposting untuk revisi.
     */
    public function reopenEvent(ExpressEvent $event, string $reason, ?Karyawan $actor = null): ExpressEvent
    {
        return DB::transaction(function () use ($event, $reason, $actor) {
            $lockedEvent = ExpressEvent::where('id', $event->id)->lockForUpdate()->firstOrFail();

            if (empty(trim($reason))) {
                throw new RuntimeException('Alasan pembukaan kembali event wajib diisi.');
            }

            $oldStatus = $lockedEvent->hpp_status;

            // Jika sebelumnya sudah diposting ke akuntansi, balik entri jurnalnya
            if ($lockedEvent->hpp_status === ExpressEvent::HPP_POSTED) {
                app(ExpressEventAccountingService::class)->reverseHpp($lockedEvent, $reason);
            }

            $lockedEvent->update([
                'hpp_status' => ExpressEvent::HPP_CALCULATED,
                'confirmed_at' => null,
                'confirmed_by' => null,
                'finalized_at' => null,
            ]);

            ExpressEventAuditLog::record(
                $lockedEvent,
                'REOPENED',
                ['hpp_status' => $oldStatus],
                ['hpp_status' => ExpressEvent::HPP_CALCULATED],
                "Event dibuka kembali untuk revisi: {$reason}"
            );

            return $lockedEvent;
        });
    }

    /**
     * Finalisasi event (kunci dari pengeditan langsung).
     */
    public function finalizeEvent(ExpressEvent $event, ?Karyawan $actor = null): ExpressEvent
    {
        if ($event->hpp_status !== ExpressEvent::HPP_CONFIRMED && $event->hpp_status !== ExpressEvent::HPP_POSTED) {
            throw new RuntimeException('Hanya event yang sudah terkonfirmasi HPP-nya yang dapat difinalisasi.');
        }

        $event->update([
            'finalized_at' => now(),
        ]);

        ExpressEventAuditLog::record(
            $event,
            'FINALIZED',
            null,
            ['finalized_at' => now()->toDateTimeString()],
            'Event dinyatakan final'
        );

        return $event;
    }
}
