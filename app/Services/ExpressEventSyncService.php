<?php

namespace App\Services;

use App\Models\ExpressEvent;
use App\Models\ExpressEventAuditLog;
use App\Models\ExpressEventInvoice;
use App\Models\ExpressEventRecap;
use App\Models\ExpressEventRecapAllocation;
use App\Models\Karyawan;
use App\Models\SessionReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExpressEventSyncService
{
    public function __construct(
        protected ExpressEventAllocationService $allocationService,
        protected ExpressEventInvoiceService $invoiceService,
    ) {}

    /**
     * Cek apakah rekap sesi memenuhi seluruh syarat untuk masuk ke Manajemen HPP Express:
     * 1. Cabang termasuk operasional Express (Sharing Profit, Self Run, Sewa, atau Express historis)
     * 2. Status rekap sesi: APPROVED
     * 3. Nama Event tersedia
     * 4. Tanggal Event / operasional tersedia
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
     * @return array{events_created: int, recaps_attached: int, events_updated: int, already_synced: int, events_consolidated: int, flagged_for_verification: int}
     */
    public function syncAllApprovedRecaps(?Karyawan $actor = null): array
    {
        $stats = [
            'events_created' => 0,
            'recaps_attached' => 0,
            'events_updated' => 0,
            'already_synced' => 0,
            'events_consolidated' => 0,
            'flagged_for_verification' => 0,
        ];

        if (! Schema::hasTable('express_events') || ! Schema::hasTable('express_event_recaps')) {
            return $stats;
        }

        // Konsolidasikan lebih dulu event historis yang terbukti berasal dari 1 event operasional yang sama
        if ($this->hasIdentityColumns()) {
            $consolidation = app(ExpressEventConsolidationService::class)->consolidateHistoricalEvents($actor);
            $stats['events_consolidated'] = $consolidation['merged_events'];
            $stats['flagged_for_verification'] = $consolidation['flagged_for_verification'];
        }

        $expressCabangIds = SessionReport::expressEventCabangIds();
        if ($expressCabangIds === []) {
            return $stats;
        }

        $reports = SessionReport::query()
            ->where('status', SessionReport::STATUS_APPROVED)
            ->whereIn('cabang_id', $expressCabangIds)
            ->with(['cabang', 'event', 'invoice', 'transactions'])
            ->orderByRaw('COALESCE(event_date, report_date) ASC')
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

        if ($this->hasIdentityColumns()) {
            $postAudit = app(ExpressEventConsolidationService::class)->flagAmbiguousEvents();
            $stats['flagged_for_verification'] = max($stats['flagged_for_verification'], $postAudit);
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
                ->with(['cabang', 'event', 'invoice', 'transactions'])
                ->lockForUpdate()
                ->find($report->id);

            if (! $lockedReport || ! $this->isEligibleForSync($lockedReport)) {
                return ['outcome' => 'skipped', 'event' => null];
            }

            $identity = $lockedReport->resolveOperationalEventIdentity();
            $eventName = trim((string) $lockedReport->resolved_event_name);
            $normalizedEventName = $this->normalizeEventName($eventName);
            $opDate = $lockedReport->operational_event_date?->toDateString()
                ?? $lockedReport->report_date?->toDateString();
            $opType = $identity['operational_type'];

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

                $updated = $this->reconcileAlreadyLinkedReport(
                    $existingEvent,
                    $existingLink,
                    $lockedReport,
                    $eventName,
                    $opDate,
                    $opType,
                    $identity,
                );

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

            // 2. Cari ExpressEvent aktif yang cocok berdasarkan identitas unik Event Operasional
            $matchingEvent = $this->findMatchingEvent($lockedReport, $identity, $normalizedEventName, $opDate, $opType);

            if ($matchingEvent) {
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

                $this->recalculateEventDatesAndMetadata($matchingEvent, $opType, $identity);

                if ($matchingEvent->requiresInvoice() && $matchingEvent->hasLinkedInvoices()) {
                    $this->invoiceService->syncEventInvoiceAllocations($matchingEvent);
                }

                $matchingEvent->unsetRelation('latestHpp');
                $matchingEvent->load('latestHpp');
                if ($matchingEvent->latestHpp && ! $matchingEvent->isPosted() && $matchingEvent->hpp_rate_per_sheet !== null) {
                    $preview = app(ExpressEventHppService::class)->calculatePreview($matchingEvent);
                    $matchingEvent->latestHpp->update([
                        'total_sessions' => $preview['total_sessions'],
                        'total_sheets' => $preview['total_sheets'],
                        'total_revenue' => $preview['total_revenue'],
                        'total_hpp' => $preview['total_hpp'],
                        'gross_profit' => $preview['gross_profit'],
                        'calculated_at' => now(),
                    ]);
                }

                ExpressEventAuditLog::record(
                    $matchingEvent,
                    'SYNCED_RECAP',
                    null,
                    [
                        'session_report_id' => $lockedReport->id,
                        'report_number' => $lockedReport->report_number,
                        'identity_key' => $identity['key'],
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
            [$startDate, $endDate] = $this->computeEventDateBounds(collect([$lockedReport]));

            $createPayload = [
                'event_name' => $eventName,
                'operational_type' => $opType,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'hpp_rate_per_sheet' => null,
                'reconciliation_status' => ExpressEvent::RECON_FULLY_ALLOCATED,
                'hpp_status' => ExpressEvent::HPP_PENDING,
                'created_by' => $actor?->karyawan_id ?? (auth()->check() ? auth()->id() : null),
            ];

            if ($this->hasIdentityColumns()) {
                $createPayload['event_id'] = $identity['event_id'];
                $createPayload['operational_identity_key'] = $identity['key'];
                $createPayload['needs_admin_verification'] = $identity['source'] === 'unverified';
                $createPayload['verification_note'] = $identity['source'] === 'unverified'
                    ? 'Identitas event operasional pada rekap historis belum lengkap. Harap verifikasi manual.'
                    : null;
            }

            $newEvent = ExpressEvent::create($createPayload);

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
                    'identity_key' => $identity['key'],
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

    /**
     * Rekonsiliasi ulang Event HPP yang terhubung ketika sebuah Rekap Sesi berubah
     * status approval (mis. dibatalkan/revisi) atau mengalami koreksi jumlah lembar/sesi.
     */
    public function reconcileEventsForReport(SessionReport $report): void
    {
        if (! Schema::hasTable('express_events') || ! Schema::hasTable('express_event_recaps')) {
            return;
        }

        $eventIds = ExpressEventRecap::query()
            ->where('session_report_id', $report->id)
            ->pluck('express_event_id')
            ->unique()
            ->all();

        foreach ($eventIds as $eventId) {
            DB::transaction(function () use ($eventId, $report): void {
                $lockedEvent = ExpressEvent::where('id', $eventId)->lockForUpdate()->first();
                if (! $lockedEvent || $lockedEvent->isFinalized()) {
                    return;
                }

                $link = ExpressEventRecap::query()
                    ->where('express_event_id', $lockedEvent->id)
                    ->where('session_report_id', $report->id)
                    ->with('allocations')
                    ->first();

                if ($link && $report->status === SessionReport::STATUS_APPROVED) {
                    $opType = $lockedEvent->resolved_operational_type ?? ExpressEvent::TYPE_EXPRESS_GENERAL;
                    $source = $this->computeOperationalSource($report, $opType);
                    $alloc = $link->latestAllocation;
                    if ($alloc) {
                        $updates = [
                            'allocated_sessions' => $source['sessions'],
                            'allocated_sheets' => $source['sheets'],
                        ];
                        if (! $lockedEvent->requiresInvoice()) {
                            $updates['allocated_revenue'] = $source['revenue'];
                        }
                        $alloc->update($updates);
                    }
                }

                $this->recalculateEventDatesAndMetadata($lockedEvent);

                if ($lockedEvent->requiresInvoice() && $lockedEvent->hasLinkedInvoices()) {
                    $this->invoiceService->syncEventInvoiceAllocations($lockedEvent);
                }

                // Perbarui snapshot HPP apabila event memiliki tarif dan belum diposting jurnal
                $lockedEvent->unsetRelation('latestHpp');
                $lockedEvent->load('latestHpp');
                if ($lockedEvent->latestHpp && ! $lockedEvent->isPosted() && $lockedEvent->hpp_rate_per_sheet !== null) {
                    $preview = app(ExpressEventHppService::class)->calculatePreview($lockedEvent);
                    $lockedEvent->latestHpp->update([
                        'total_sessions' => $preview['total_sessions'],
                        'total_sheets' => $preview['total_sheets'],
                        'total_revenue' => $preview['total_revenue'],
                        'total_hpp' => $preview['total_hpp'],
                        'gross_profit' => $preview['gross_profit'],
                        'calculated_at' => now(),
                    ]);
                }
            });
        }
    }

    /**
     * Hitung ulang start_date dan end_date dari seluruh rekap sesi APPROVED yang aktif pada event.
     */
    public function recalculateEventDatesAndMetadata(
        ExpressEvent $event,
        ?string $fallbackOpType = null,
        ?array $identity = null,
    ): void {
        $event->unsetRelation('recaps');
        $event->load(['recaps.sessionReport.cabang', 'recaps.sessionReport.event', 'recaps.sessionReport.invoice']);

        $approvedReports = $event->recaps
            ->where('status', ExpressEventRecap::STATUS_ACTIVE)
            ->map(fn (ExpressEventRecap $r) => $r->sessionReport)
            ->filter(fn (?SessionReport $r): bool => $r !== null && $r->status === SessionReport::STATUS_APPROVED)
            ->values();

        $updates = [];

        if ($approvedReports->isNotEmpty()) {
            [$startDate, $endDate] = $this->computeEventDateBounds($approvedReports);
            $updates['start_date'] = $startDate;
            $updates['end_date'] = $endDate;
        }

        if (blank($event->operational_type) && $fallbackOpType !== null) {
            $updates['operational_type'] = $fallbackOpType;
        }

        if ($this->hasIdentityColumns() && $identity !== null) {
            if ($event->event_id === null && $identity['event_id'] !== null) {
                $updates['event_id'] = $identity['event_id'];
            }
            if (blank($event->operational_identity_key) && filled($identity['key'])) {
                $updates['operational_identity_key'] = $identity['key'];
            }
        }

        if ($event->isConfirmed()) {
            $updates['reconciliation_status'] = ExpressEvent::RECON_NEEDS_REVIEW;
        } elseif ($approvedReports->isEmpty()) {
            $updates['reconciliation_status'] = ExpressEvent::RECON_UNALLOCATED;
        } else {
            $updates['reconciliation_status'] = ExpressEvent::RECON_FULLY_ALLOCATED;
        }

        if ($updates !== []) {
            $event->update($updates);
        }
    }

    /**
     * Hitung tanggal mulai (paling awal) dan tanggal selesai (paling akhir)
     * dari koleksi rekap sesi APPROVED pada satu event operasional.
     *
     * @param  Collection<int, SessionReport>  $approvedReports
     * @return array{0: string, 1: string}
     */
    public function computeEventDateBounds(Collection $approvedReports): array
    {
        if ($approvedReports->isEmpty()) {
            $today = now()->toDateString();

            return [$today, $today];
        }

        if ($approvedReports->count() === 1) {
            /** @var SessionReport $single */
            $single = $approvedReports->first();
            $opDate = $single->operational_event_date?->toDateString();
            $repDate = $single->report_date?->toDateString();

            if (($single->event_id || $single->invoice_id) && $opDate && $repDate && $repDate > $opDate) {
                return [$opDate, $repDate];
            }

            $resolved = $opDate ?? $repDate ?? now()->toDateString();

            return [$resolved, $resolved];
        }

        $dates = [];
        foreach ($approvedReports as $rep) {
            $opDate = $rep->operational_event_date?->toDateString();
            $repDate = $rep->report_date?->toDateString();

            if ($opDate !== null) {
                $dates[] = $opDate;
            }
            if ($repDate !== null && ($opDate === null || $rep->event_id !== null || $rep->invoice_id !== null || $repDate >= $opDate)) {
                $dates[] = $repDate;
            }
        }

        $dates = array_values(array_unique(array_filter($dates)));
        sort($dates);

        if ($dates === []) {
            $today = now()->toDateString();

            return [$today, $today];
        }

        return [$dates[0], $dates[count($dates) - 1]];
    }

    protected function reconcileAlreadyLinkedReport(
        ExpressEvent $event,
        ExpressEventRecap $link,
        SessionReport $report,
        string $eventName,
        string $eventDate,
        string $opType,
        array $identity,
    ): bool {
        if ($event->isFinalized()) {
            return false;
        }

        $changed = false;

        if (blank($event->operational_type)) {
            $event->operational_type = $opType;
            $changed = true;
        }

        if ($this->hasIdentityColumns()) {
            if ($event->event_id === null && $identity['event_id'] !== null) {
                $event->event_id = $identity['event_id'];
                $changed = true;
            }
            if (blank($event->operational_identity_key)) {
                $event->operational_identity_key = $identity['key'];
                $changed = true;
            }
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
     * Cari ExpressEvent aktif berdasarkan aturan ketat identitas Event Operasional:
     * 1. Jika rekap memiliki event_id unik -> WAJIB cocok dengan event_id yang sama (tidak boleh digabung dengan event_id berbeda, meskipun nama & tanggal sama!).
     * 2. Jika rekap memiliki invoice_id unik (tanpa event_id) -> WAJIB cocok dengan invoice_id yang sama (tidak boleh digabung dengan invoice_id berbeda, meskipun nama sama!).
     * 3. Jika rekap manual (tanpa event_id & tanpa invoice_id, mis. Sharing Profit):
     *    hanya boleh bergabung ke kandidat yang juga tidak memiliki konflik event_id/invoice_id,
     *    jenis operasional sama, nama ternormalisasi sama, dan tanggal operasional berurutan langsung (H-1 s/d H+1).
     */
    protected function findMatchingEvent(
        SessionReport $report,
        array $identity,
        string $normalizedEventName,
        ?string $eventDate,
        string $opType,
    ): ?ExpressEvent {
        $candidates = ExpressEvent::query()
            ->whereNull('finalized_at')
            ->with(['recaps.sessionReport.cabang', 'invoices'])
            ->lockForUpdate()
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        $reportEventId = $identity['event_id'];
        $reportInvoiceId = $identity['invoice_id'];

        // TIER 1: Pencocokan berbasis ID Event Operasional (event_id)
        if ($reportEventId !== null) {
            foreach ($candidates as $candidate) {
                $candidateEventIds = $this->collectCandidateEventIds($candidate);

                if ($candidateEventIds !== [] && in_array($reportEventId, $candidateEventIds, true) && count($candidateEventIds) === 1) {
                    return $candidate;
                }
            }

            // Rekap dengan event_id eksplisit tidak boleh digabung ke kandidat lain hanya karena kesamaan nama!
            return null;
        }

        // TIER 2: Pencocokan berbasis ID Kontrak Invoice unik (invoice_id) ketika event_id kosong
        if ($reportInvoiceId !== null) {
            foreach ($candidates as $candidate) {
                $candidateEventIds = $this->collectCandidateEventIds($candidate);
                if ($candidateEventIds !== []) {
                    // Kandidat punya event_id eksplisit berbeda -> jangan campur
                    continue;
                }

                $candidateInvoiceIds = $this->collectCandidateInvoiceIds($candidate);

                if ($candidateInvoiceIds !== []) {
                    if (in_array($reportInvoiceId, $candidateInvoiceIds, true)) {
                        return $candidate;
                    }

                    // Kandidat terikat ke invoice lain -> meskipun nama event sama, ini event berbeda!
                    continue;
                }

                if ($this->hasIdentityColumns() && $candidate->operational_identity_key === "invoice:{$reportInvoiceId}") {
                    return $candidate;
                }
            }

            // Rekap dengan invoice_id eksplisit tidak boleh digabung ke event lain hanya karena nama sama!
            return null;
        }

        // TIER 3: Rekap tanpa event_id dan tanpa invoice_id (Sharing Profit / historis manual)
        if ($identity['source'] === 'unverified' || $eventDate === null || $normalizedEventName === '') {
            return null;
        }

        foreach ($candidates as $candidate) {
            if ($this->normalizeEventName($candidate->event_name) !== $normalizedEventName) {
                continue;
            }

            $candidateOpType = $candidate->resolved_operational_type;
            if (
                $candidateOpType !== null
                && $candidateOpType !== ExpressEvent::TYPE_EXPRESS_GENERAL
                && $opType !== ExpressEvent::TYPE_EXPRESS_GENERAL
                && $candidateOpType !== $opType
            ) {
                continue;
            }

            // Cek apakah rekap masuk dalam rentang tanggal kontrak invoice yang sudah terhubung ke kandidat
            $coveredByLinkedInvoice = false;
            foreach ($candidate->invoices as $linkedInv) {
                if ($linkedInv->event_date && $linkedInv->event_end_date) {
                    $invStart = $linkedInv->event_date->toDateString();
                    $invEnd = $linkedInv->event_end_date->toDateString();
                    if ($eventDate >= $invStart && $eventDate <= $invEnd) {
                        $coveredByLinkedInvoice = true;
                        break;
                    }
                }
            }

            // Pastikan pada tanggal rekap ini tidak ada Invoice atau Event terpisah lain dengan nama yang sama
            $candInvIds = $this->collectCandidateInvoiceIds($candidate);
            $candEvIds = $this->collectCandidateEventIds($candidate);

            $hasConflictingInvoiceOnDate = \App\Models\Invoice::query()
                ->whereNotNull('event_name')
                ->whereDate('event_date', $eventDate)
                ->when($candInvIds !== [], fn ($q) => $q->whereNotIn('id', $candInvIds))
                ->get(['id', 'event_name'])
                ->contains(fn (\App\Models\Invoice $inv): bool => $this->normalizeEventName($inv->event_name) === $normalizedEventName);

            if ($hasConflictingInvoiceOnDate && $candInvIds !== []) {
                continue;
            }

            $hasConflictingEventOnDate = \App\Models\Event::query()
                ->whereNotNull('name')
                ->whereDate('date', $eventDate)
                ->when($candEvIds !== [], fn ($q) => $q->whereNotIn('id', $candEvIds))
                ->get(['id', 'name'])
                ->contains(fn (\App\Models\Event $ev): bool => $this->normalizeEventName($ev->name) === $normalizedEventName);

            if ($hasConflictingEventOnDate && $candEvIds !== []) {
                continue;
            }

            $minAllowed = $candidate->start_date
                ? $candidate->start_date->copy()->subDays(2)->toDateString()
                : $eventDate;
            $maxAllowed = $candidate->end_date
                ? $candidate->end_date->copy()->addDays(2)->toDateString()
                : $eventDate;

            if ($coveredByLinkedInvoice || ($eventDate >= $minAllowed && $eventDate <= $maxAllowed)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, int>
     */
    private function collectCandidateEventIds(ExpressEvent $candidate): array
    {
        $ids = [];
        if ($this->hasIdentityColumns() && $candidate->event_id !== null && (int) $candidate->event_id > 0) {
            $ids[] = (int) $candidate->event_id;
        }

        foreach ($candidate->recaps as $recap) {
            $evId = $recap->sessionReport?->event_id;
            if ($evId !== null && (int) $evId > 0) {
                $ids[] = (int) $evId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, int>
     */
    private function collectCandidateInvoiceIds(ExpressEvent $candidate): array
    {
        $ids = [];
        foreach ($candidate->invoices as $inv) {
            $ids[] = (int) $inv->id;
        }

        foreach ($candidate->recaps as $recap) {
            $invId = $recap->sessionReport?->invoice_id;
            if ($invId !== null && (int) $invId > 0) {
                $ids[] = (int) $invId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
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

    protected function hasIdentityColumns(): bool
    {
        return Schema::hasTable('express_events')
            && Schema::hasColumn('express_events', 'event_id')
            && Schema::hasColumn('express_events', 'operational_identity_key')
            && Schema::hasColumn('express_events', 'needs_admin_verification');
    }
}
