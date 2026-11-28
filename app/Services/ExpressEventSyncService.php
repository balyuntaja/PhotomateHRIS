<?php

namespace App\Services;

use App\Models\ExpressEvent;
use App\Models\ExpressEventAuditLog;
use App\Models\ExpressEventRecap;
use App\Models\ExpressEventRecapAllocation;
use App\Models\Karyawan;
use App\Models\SessionReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExpressEventSyncService
{
    public function __construct(
        protected ExpressEventAllocationService $allocationService,
        protected ExpressEventInvoiceService $invoiceService
    ) {}

    /**
     * Cek apakah rekap sesi memenuhi seluruh syarat untuk masuk ke Manajemen HPP Express:
     * 1. Cabang termasuk operasional Express (Sharing Profit, Self Run, Sewa, atau Express historis)
     * 2. Status rekap sesi: APPROVED
     * 3. Nama Event tersedia
     * 4. Tanggal Event tersedia
     */
    public function isEligibleForSync(SessionReport $report): bool
    {
        return $report->status === SessionReport::STATUS_APPROVED
            && $report->supportsEventDetail()
            && $report->hasCompleteEventDetail();
    }

    /**
     * Sinkronisasi seluruh rekap sesi Express yang berstatus Approved (termasuk historis)
     * ke Manajemen HPP secara idempoten tanpa menghasilkan event atau alokasi duplikat.
     *
     * @return array{events_created: int, recaps_attached: int, events_updated: int, already_synced: int}
     */
    public function syncAllApprovedRecaps(?Karyawan $actor = null): array
    {
        $stats = [
            'events_created' => 0,
            'recaps_attached' => 0,
            'events_updated' => 0,
            'already_synced' => 0,
        ];

        if (! Schema::hasTable('express_events') || ! Schema::hasTable('express_event_recaps')) {
            return $stats;
        }

        $expressCabangIds = SessionReport::expressEventCabangIds();
        if ($expressCabangIds === []) {
            return $stats;
        }

        $reports = SessionReport::query()
            ->where('status', SessionReport::STATUS_APPROVED)
            ->whereIn('cabang_id', $expressCabangIds)
            ->whereNotNull('event_date')
            ->with(['cabang', 'invoice', 'transactions'])
            ->orderBy('event_date', 'asc')
            ->orderBy('report_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($reports as $report) {
            if (! $this->isEligibleForSync($report)) {
                continue;
            }

            $result = $this->syncSingleReportWithOutcome($report, $actor);

            match ($result['outcome']) {
                'created' => $stats['events_created']++,
                'attached' => $stats['recaps_attached']++,
                'updated' => $stats['events_updated']++,
                'already_synced' => $stats['already_synced']++,
                default => null,
            };
        }

        return $stats;
    }

    /**
     * Sinkronkan satu rekap sesi Express yang memenuhi kriteria ke Event HPP yang sesuai.
     */
    public function syncSingleReport(SessionReport $report, ?Karyawan $actor = null): ?ExpressEvent
    {
        return $this->syncSingleReportWithOutcome($report, $actor)['event'];
    }

    /**
     * @return array{outcome: string, event: ExpressEvent|null}
     */
    public function syncSingleReportWithOutcome(SessionReport $report, ?Karyawan $actor = null): array
    {
        if (! Schema::hasTable('express_events') || ! Schema::hasTable('express_event_recaps')) {
            return ['outcome' => 'skipped', 'event' => null];
        }

        return DB::transaction(function () use ($report, $actor): array {
            $lockedReport = SessionReport::query()
                ->with(['cabang', 'invoice', 'transactions'])
                ->lockForUpdate()
                ->find($report->id);

            if (! $lockedReport || ! $this->isEligibleForSync($lockedReport)) {
                return ['outcome' => 'skipped', 'event' => null];
            }

            $eventName = trim((string) $lockedReport->resolved_event_name);
            $normalizedEventName = $this->normalizeEventName($eventName);
            $eventDate = $lockedReport->event_date->toDateString();
            $cabangName = $lockedReport->resolveCabangWithTrashed()?->nama_cabang;
            $opType = ExpressEvent::classifyBranchOperationalType($cabangName) ?? ExpressEvent::TYPE_EXPRESS_GENERAL;

            // 1. Cek apakah rekap ini sudah pernah terhubung ke ExpressEvent aktif (cegah duplikasi)
            $existingLink = ExpressEventRecap::query()
                ->where('session_report_id', $lockedReport->id)
                ->whereHas('expressEvent', fn ($q) => $q->whereNull('deleted_at'))
                ->with(['expressEvent', 'allocations'])
                ->lockForUpdate()
                ->first();

            if ($existingLink && $existingLink->expressEvent) {
                $existingEvent = ExpressEvent::where('id', $existingLink->expressEvent->id)->lockForUpdate()->first();
                if (! $existingEvent) {
                    return ['outcome' => 'already_synced', 'event' => null];
                }

                $updated = $this->reconcileAlreadyLinkedReport($existingEvent, $existingLink, $lockedReport, $eventName, $eventDate, $opType);

                return [
                    'outcome' => $updated ? 'updated' : 'already_synced',
                    'event' => $existingEvent->fresh(['recaps.allocations', 'invoices']),
                ];
            }

            // Bersihkan baris pivot yatim jika sebelumnya terhubung ke ExpressEvent yang telah di-soft-delete
            ExpressEventRecap::query()
                ->where('session_report_id', $lockedReport->id)
                ->whereDoesntHave('expressEvent')
                ->delete();

            $source = $this->computeOperationalSource($lockedReport, $opType);

            // 2. Cari ExpressEvent aktif yang cocok untuk mengelompokkan beberapa rekap pada event yang sama
            $matchingEvent = $this->findMatchingEvent($normalizedEventName, $eventDate, $opType, $lockedReport->invoice_id);

            if ($matchingEvent) {
                $newStart = min($matchingEvent->start_date->toDateString(), $eventDate);
                $newEnd = max($matchingEvent->end_date->toDateString(), $eventDate);

                $eventRecap = ExpressEventRecap::firstOrCreate(
                    [
                        'express_event_id' => $matchingEvent->id,
                        'session_report_id' => $lockedReport->id,
                    ],
                    [
                        'status' => ExpressEventRecap::STATUS_ACTIVE,
                    ]
                );

                ExpressEventRecapAllocation::updateOrCreate(
                    ['express_event_recap_id' => $eventRecap->id],
                    [
                        'allocated_sessions' => $source['sessions'],
                        'allocated_sheets' => $source['sheets'],
                        'allocated_revenue' => $source['revenue'],
                    ]
                );

                $reconStatus = $matchingEvent->isConfirmed()
                    ? ExpressEvent::RECON_NEEDS_REVIEW
                    : ExpressEvent::RECON_FULLY_ALLOCATED;

                $matchingEvent->update([
                    'operational_type' => $matchingEvent->operational_type ?: $opType,
                    'start_date' => $newStart,
                    'end_date' => $newEnd,
                    'reconciliation_status' => $reconStatus,
                ]);

                if ($matchingEvent->requiresInvoice() && $matchingEvent->hasLinkedInvoices()) {
                    $this->invoiceService->syncEventInvoiceAllocations($matchingEvent);
                }

                ExpressEventAuditLog::record(
                    $matchingEvent,
                    'SYNCED_RECAP',
                    null,
                    [
                        'session_report_id' => $lockedReport->id,
                        'report_number' => $lockedReport->report_number,
                        'sessions' => $source['sessions'],
                        'sheets' => $source['sheets'],
                    ],
                    "Rekap sesi {$lockedReport->report_number} digabungkan ke event melalui sinkronisasi."
                );

                return [
                    'outcome' => 'attached',
                    'event' => $matchingEvent->fresh(['recaps.allocations', 'invoices']),
                ];
            }

            // 3. Buat ExpressEvent baru
            $newEvent = ExpressEvent::create([
                'event_name' => $eventName,
                'operational_type' => $opType,
                'start_date' => $eventDate,
                'end_date' => $eventDate,
                'hpp_rate_per_sheet' => null,
                'reconciliation_status' => ExpressEvent::RECON_FULLY_ALLOCATED,
                'hpp_status' => ExpressEvent::HPP_PENDING,
                'created_by' => $actor?->karyawan_id ?? (auth()->check() ? auth()->id() : null),
            ]);

            $eventRecap = ExpressEventRecap::create([
                'express_event_id' => $newEvent->id,
                'session_report_id' => $lockedReport->id,
                'status' => ExpressEventRecap::STATUS_ACTIVE,
            ]);

            ExpressEventRecapAllocation::create([
                'express_event_recap_id' => $eventRecap->id,
                'allocated_sessions' => $source['sessions'],
                'allocated_sheets' => $source['sheets'],
                'allocated_revenue' => $source['revenue'],
            ]);

            ExpressEventAuditLog::record(
                $newEvent,
                'SYNCED_CREATED',
                null,
                [
                    'event_name' => $eventName,
                    'operational_type' => $opType,
                    'session_report_id' => $lockedReport->id,
                    'report_number' => $lockedReport->report_number,
                ],
                "Event Express disinkronkan dari rekap sesi {$lockedReport->report_number}."
            );

            return [
                'outcome' => 'created',
                'event' => $newEvent->fresh(['recaps.allocations', 'invoices']),
            ];
        });
    }

    protected function reconcileAlreadyLinkedReport(
        ExpressEvent $event,
        ExpressEventRecap $link,
        SessionReport $report,
        string $eventName,
        string $eventDate,
        string $opType
    ): bool {
        if ($event->isFinalized()) {
            return false;
        }

        $changed = false;

        if (blank($event->operational_type)) {
            $event->operational_type = $opType;
            $changed = true;
        }

        // Bila event belum dikonfirmasi dan hanya memiliki 1 rekap ini, selaraskan perubahan nama/tanggal event dari Super Admin
        if (! $event->isConfirmed() && $event->recaps()->count() === 1) {
            if ($event->event_name !== $eventName) {
                $event->event_name = $eventName;
                $changed = true;
            }
            if ($event->start_date?->toDateString() !== $eventDate || $event->end_date?->toDateString() !== $eventDate) {
                $event->start_date = $eventDate;
                $event->end_date = $eventDate;
                $changed = true;
            }
        }

        if ($changed) {
            $event->save();

            ExpressEventAuditLog::record(
                $event,
                'EVENT_METADATA_SYNCED',
                null,
                [
                    'event_name' => $event->event_name,
                    'start_date' => $event->start_date?->toDateString(),
                    'end_date' => $event->end_date?->toDateString(),
                ],
                "Informasi event diselaraskan dengan pembaruan pada rekap {$report->report_number}."
            );
        }

        return $changed;
    }

    /**
     * Cari ExpressEvent aktif yang merupakan event yang sama:
     * - Nama event ternormalisasi sama
     * - Belum finalized
     * - Jenis operasional kompatibel (tidak mencampur Sharing Profit dengan Self Run / Sewa)
     * - Tanggal event sama atau berurutan (rentang H-1 s/d H+1 dari periode event) ATAU berbagi invoice_id yang sama,
     *   sehingga nama event generik di bulan/tahun berbeda tidak salah digabung.
     */
    protected function findMatchingEvent(
        string $normalizedEventName,
        string $eventDate,
        string $opType,
        ?int $reportInvoiceId
    ): ?ExpressEvent {
        $candidates = ExpressEvent::query()
            ->whereNull('finalized_at')
            ->with(['recaps.sessionReport.cabang', 'invoices'])
            ->lockForUpdate()
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->normalizeEventName($candidate->event_name) !== $normalizedEventName) {
                continue;
            }

            $candidateOpType = $candidate->resolved_operational_type;
            if ($candidateOpType !== null && $candidateOpType !== $opType) {
                continue;
            }

            $minAllowed = $candidate->start_date
                ? $candidate->start_date->copy()->subDay()->toDateString()
                : $eventDate;
            $maxAllowed = $candidate->end_date
                ? $candidate->end_date->copy()->addDay()->toDateString()
                : $eventDate;

            $dateMatches = $eventDate >= $minAllowed && $eventDate <= $maxAllowed;

            $sameInvoice = false;
            if ($reportInvoiceId !== null && $reportInvoiceId > 0) {
                $sameInvoice = $candidate->recaps->contains(
                    fn (ExpressEventRecap $r): bool => (int) ($r->sessionReport?->invoice_id ?? 0) === $reportInvoiceId
                ) || $candidate->invoices->contains('id', $reportInvoiceId);
            }

            if ($dateMatches || $sameInvoice) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Hitung sesi, lembar, dan omzet awal untuk sinkronisasi:
     * - Sharing Profit (dan Express umum): omzet berasal dari grand_total_amount rekap sesi
     * - Self Run & Sewa: omzet komersial berasal dari Invoice yang dihubungkan di Manajemen HPP (awal = 0)
     *
     * @return array{sessions: int, sheets: int, revenue: int}
     */
    protected function computeOperationalSource(SessionReport $report, string $opType): array
    {
        $source = $this->allocationService->getSourceTotals($report);

        $revenue = in_array($opType, [ExpressEvent::TYPE_SELF_RUN, ExpressEvent::TYPE_SEWA], true)
            ? 0
            : max(0, (int) $report->grand_total_amount);

        return [
            'sessions' => $source['sessions'],
            'sheets' => $source['sheets'],
            'revenue' => $revenue,
        ];
    }

    public function normalizeEventName(?string $name): string
    {
        $trimmed = strtolower(trim((string) $name));

        return preg_replace('/\s+/', ' ', $trimmed) ?? '';
    }
}
