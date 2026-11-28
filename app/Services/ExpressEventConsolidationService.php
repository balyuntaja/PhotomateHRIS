<?php

namespace App\Services;

use App\Models\Event;
use App\Models\ExpressEvent;
use App\Models\ExpressEventAuditLog;
use App\Models\ExpressEventInvoice;
use App\Models\ExpressEventRecap;
use App\Models\ExpressEventRecapAllocation;
use App\Models\Invoice;
use App\Models\Karyawan;
use App\Models\SessionReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ExpressEventConsolidationService
{
    public function __construct(
        protected ExpressEventSyncService $syncService,
        protected ExpressEventInvoiceService $invoiceService,
    ) {}

    /**
     * Audit dan konsolidasikan data historis ExpressEvent secara aman:
     * 1. Menggabungkan event terpisah yang TERBUKTI berasal dari satu identitas event operasional yang sama
     *    (event_id sama, atau invoice_id sama ketika event_id kosong, tanpa menghapus record SessionReport/Invoice).
     * 2. Mempertahankan terpisah event dengan nama sama tetapi identitas berbeda.
     * 3. Menandai event historis yang identitasnya ambigu (needs_admin_verification = true) tanpa menebak.
     *
     * @return array{
     *     merged_events: int,
     *     kept_separate: int,
     *     flagged_for_verification: int,
     *     merged_details: array<int, array<string, mixed>>,
     *     flagged_details: array<int, array<string, mixed>>
     * }
     */
    public function consolidateHistoricalEvents(?Karyawan $actor = null): array
    {
        $report = [
            'merged_events' => 0,
            'kept_separate' => 0,
            'flagged_for_verification' => 0,
            'merged_details' => [],
            'flagged_details' => [],
        ];

        if (! $this->hasRequiredSchema()) {
            return $report;
        }

        // Langkah 1: Backfill identitas unik pada setiap ExpressEvent aktif
        $events = ExpressEvent::query()
            ->with(['recaps.sessionReport.cabang', 'recaps.allocations', 'invoices', 'hppSnapshots'])
            ->orderBy('start_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $provenGroups = [];

        foreach ($events as $event) {
            $analysis = $this->analyzeEventIdentity($event);

            if ($analysis['conflict_note'] !== null) {
                $event->update([
                    'needs_admin_verification' => true,
                    'verification_note' => $analysis['conflict_note'],
                ]);
                continue;
            }

            $updates = [];
            if ($event->event_id === null && $analysis['event_id'] !== null) {
                $updates['event_id'] = $analysis['event_id'];
            }
            if (blank($event->operational_identity_key) && $analysis['identity_key'] !== null) {
                $updates['operational_identity_key'] = $analysis['identity_key'];
            }
            if (blank($event->operational_type) && $analysis['operational_type'] !== null) {
                $updates['operational_type'] = $analysis['operational_type'];
            }
            if ($updates !== []) {
                $event->update($updates);
            }

            // Hanya grup yang memiliki bukti identitas eksplisit (event_id atau invoice_id) yang digabung otomatis
            if ($analysis['proven_merge_key'] !== null) {
                $provenGroups[$analysis['proven_merge_key']][] = $event->id;
            }
        }

        // Langkah 2: Gabungkan grup yang terbukti merupakan satu event operasional yang sama (event_id / invoice_id sama)
        foreach ($provenGroups as $mergeKey => $eventIds) {
            if (count($eventIds) < 2) {
                continue;
            }

            $mergeOutcome = $this->mergeDuplicateGroup($eventIds, $mergeKey, $actor);
            $report['merged_events'] += $mergeOutcome['merged_count'];
            if ($mergeOutcome['detail'] !== null) {
                $report['merged_details'][] = $mergeOutcome['detail'];
            }
        }

        // Langkah 2b: Gabungkan event multi-hari berurutan (<= 2 hari / dalam rentang tanggal invoice yang sama)
        // yang terpecah menjadi beberapa baris (mis. baris 1 sudah punya invoice, baris 2 berasal dari rekap historis manual tanpa ID konflik)
        $contiguousOutcome = $this->consolidateContiguousMultiDaySiblings($actor);
        $report['merged_events'] += $contiguousOutcome['merged_count'];
        foreach ($contiguousOutcome['details'] as $detail) {
            $report['merged_details'][] = $detail;
        }

        // Langkah 3: Rekalkulasi rentang tanggal & alokasi invoice pada seluruh event aktif yang tersisa
        $remainingEvents = ExpressEvent::query()
            ->with(['recaps.sessionReport.cabang', 'invoices'])
            ->orderBy('id')
            ->get();

        foreach ($remainingEvents as $remaining) {
            if (! $remaining->isFinalized()) {
                $this->syncService->recalculateEventDatesAndMetadata($remaining);
                if ($remaining->requiresInvoice() && $remaining->hasLinkedInvoices()) {
                    $this->invoiceService->syncEventInvoiceAllocations($remaining);
                }
            }
        }

        // Langkah 4: Tandai data historis yang belum jelas identitasnya untuk verifikasi admin (tanpa menggabung otomatis!)
        $flaggedCount = $this->flagAmbiguousEvents($report['flagged_details']);
        $report['flagged_for_verification'] = $flaggedCount;
        $report['kept_separate'] = ExpressEvent::query()->count();

        return $report;
    }

    /**
     * Tandai event historis yang memiliki kemiripan nama tanpa identitas eksplisit (event_id / invoice_id)
     * atau data belum lengkap agar diverifikasi oleh admin, tanpa pernah menggabungkannya secara paksa.
     *
     * @param  array<int, array<string, mixed>>|null  $detailsOut
     */
    public function flagAmbiguousEvents(?array &$detailsOut = null): int
    {
        if (! $this->hasRequiredSchema()) {
            return 0;
        }

        $detailsOut ??= [];

        $events = ExpressEvent::query()
            ->with(['recaps.sessionReport.cabang', 'invoices'])
            ->orderBy('start_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $byNormalizedName = [];
        foreach ($events as $ev) {
            $norm = $this->syncService->normalizeEventName($ev->event_name);
            if ($norm !== '') {
                $byNormalizedName[$norm][] = $ev;
            }
        }

        $flaggedIds = [];

        foreach ($events as $ev) {
            $analysis = $this->analyzeEventIdentity($ev);

            if ($analysis['conflict_note'] !== null) {
                if (! $ev->needs_admin_verification || $ev->verification_note !== $analysis['conflict_note']) {
                    $ev->update([
                        'needs_admin_verification' => true,
                        'verification_note' => $analysis['conflict_note'],
                    ]);
                }
                $flaggedIds[$ev->id] = $analysis['conflict_note'];
                continue;
            }

            // Event tanpa nama atau tanpa satu pun rekap sesi Approved
            if (blank($ev->event_name) || $ev->start_date === null) {
                $note = 'Data identitas event belum lengkap (nama atau tanggal operasional belum tersedia).';
                if (! $ev->needs_admin_verification) {
                    $ev->update([
                        'needs_admin_verification' => true,
                        'verification_note' => $note,
                    ]);
                }
                $flaggedIds[$ev->id] = $note;
            }
        }

        // Periksa beberapa ExpressEvent dengan nama sama di mana keduanya TIDAK memiliki event_id maupun invoice_id
        // dalam rentang berdekatan (2 s/d 14 hari): jangan digabung otomatis, cukup tandai untuk verifikasi admin!
        foreach ($byNormalizedName as $siblings) {
            if (count($siblings) < 2) {
                continue;
            }

            $count = count($siblings);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    /** @var ExpressEvent $a */
                    $a = $siblings[$i];
                    /** @var ExpressEvent $b */
                    $b = $siblings[$j];

                    $infoA = $this->analyzeEventIdentity($a);
                    $infoB = $this->analyzeEventIdentity($b);

                    // Jika salah satu atau keduanya punya event_id / invoice_id berbeda, itu memang 2 event berbeda yang valid!
                    if ($infoA['has_explicit_id'] && $infoB['has_explicit_id'] && $infoA['proven_merge_key'] !== $infoB['proven_merge_key']) {
                        continue;
                    }

                    // Jika salah satu atau keduanya tidak punya identitas eksplisit lengkap dan berjarak dekat (3 s/d 30 hari), tandai verifikasi admin
                    if ((! $infoA['has_explicit_id'] || ! $infoB['has_explicit_id']) && $a->start_date && $b->start_date) {
                        $daysDiff = $this->computeIntervalGapDays($a, $b);
                        if ($daysDiff <= 30) {
                            $note = sprintf(
                                'Terdapat event lain bernama "%s" pada tanggal berdekatan tanpa ID Event / Invoice unik. Tidak digabung otomatis — silakan verifikasi apakah merupakan event terpisah atau satu rangkaian event.',
                                $a->event_name
                            );

                            foreach ([$a, $b] as $ambiguousEv) {
                                // Jangan menimpa bila admin sudah pernah memverifikasi secara eksplisit di audit log
                                $alreadyVerifiedByAdmin = $ambiguousEv->auditLogs()
                                    ->where('action', 'ADMIN_VERIFIED_IDENTITY')
                                    ->exists();

                                if (! $alreadyVerifiedByAdmin) {
                                    $ambiguousEv->update([
                                        'needs_admin_verification' => true,
                                        'verification_note' => $note,
                                    ]);
                                    $flaggedIds[$ambiguousEv->id] = $note;
                                }
                            }
                        }
                    }
                }
            }
        }

        foreach ($flaggedIds as $evId => $reason) {
            $detailsOut[] = [
                'event_id' => $evId,
                'reason' => $reason,
            ];
        }

        return count($flaggedIds);
    }

    /**
     * Daftar kandidat Event HPP lain yang dapat digabungkan secara manual ke event ini oleh Admin.
     *
     * @return Collection<int, ExpressEvent>
     */
    public function getMergeableCandidateEvents(ExpressEvent $currentEvent): Collection
    {
        if ($currentEvent->isFinalized()) {
            return collect();
        }

        $currentNormName = $this->syncService->normalizeEventName($currentEvent->event_name);
        $currentIsSharing = $currentEvent->isSharingProfit();

        return ExpressEvent::query()
            ->where('id', '!=', $currentEvent->id)
            ->whereNull('finalized_at')
            ->with(['recaps.sessionReport.cabang', 'recaps.allocations', 'invoices'])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->filter(function (ExpressEvent $candidate) use ($currentEvent, $currentIsSharing): bool {
                if ($currentEvent->isPosted() && $candidate->isPosted()) {
                    return false;
                }

                // Jangan mencampur Sharing Profit dengan Self Run / Sewa
                if ($currentIsSharing !== $candidate->isSharingProfit()) {
                    $candType = $candidate->resolved_operational_type;
                    $currType = $currentEvent->resolved_operational_type;
                    if ($candType !== null && $currType !== null && $candType !== ExpressEvent::TYPE_EXPRESS_GENERAL && $currType !== ExpressEvent::TYPE_EXPRESS_GENERAL) {
                        return false;
                    }
                }

                return true;
            })
            ->sortBy(function (ExpressEvent $candidate) use ($currentNormName): string {
                $sameNamePriority = ($currentNormName !== '' && $this->syncService->normalizeEventName($candidate->event_name) === $currentNormName)
                    ? '0'
                    : '1';
                $dateSort = $candidate->start_date ? (string) (99999999 - (int) $candidate->start_date->format('Ymd')) : '99999999';

                return "{$sameNamePriority}:{$dateSort}:{$candidate->id}";
            })
            ->values();
    }

    /**
     * Gabungkan satu atau beberapa Event HPP duplikat/terpisah ke dalam $targetEvent secara manual oleh Admin.
     * Memindahkan seluruh rekap sesi dan invoice unik ke $targetEvent tanpa menghapus SessionReport maupun Invoice asli.
     *
     * @param  array<int, int|string>  $sourceEventIds
     */
    public function mergeEventsManually(ExpressEvent $targetEvent, array $sourceEventIds, ?Karyawan $actor = null): ExpressEvent
    {
        $normalizedSourceIds = collect($sourceEventIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0 && $id !== (int) $targetEvent->id)
            ->unique()
            ->values()
            ->all();

        if ($normalizedSourceIds === []) {
            throw new RuntimeException('Pilih minimal satu Event HPP lain untuk digabungkan.');
        }

        return DB::transaction(function () use ($targetEvent, $normalizedSourceIds): ExpressEvent {
            $allIds = array_merge([(int) $targetEvent->id], $normalizedSourceIds);

            $lockedEvents = ExpressEvent::query()
                ->whereIn('id', $allIds)
                ->with(['recaps.allocations', 'recaps.sessionReport.cabang', 'invoices', 'hppSnapshots', 'latestHpp'])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var ExpressEvent|null $primary */
            $primary = $lockedEvents->get((int) $targetEvent->id);
            if (! $primary) {
                throw new RuntimeException('Event tujuan penggabungan tidak ditemukan.');
            }

            if ($primary->isFinalized()) {
                throw new RuntimeException('Event tujuan sudah berstatus finalized dan harus dibuka kembali (reopen) terlebih dahulu.');
            }

            $mergedIds = [];
            $mergedNames = [];

            foreach ($normalizedSourceIds as $srcId) {
                /** @var ExpressEvent|null $source */
                $source = $lockedEvents->get($srcId);
                if (! $source) {
                    continue;
                }

                if ($source->isFinalized()) {
                    throw new RuntimeException("Event '{$source->event_name}' sudah berstatus finalized dan tidak dapat digabungkan sebelum di-reopen.");
                }

                if ($primary->isPosted() && $source->isPosted()) {
                    throw new RuntimeException('Kedua event sudah diposting ke Jurnal Keuangan. Buka kembali (reopen) salah satu event terlebih dahulu sebelum menggabungkan.');
                }

                $primaryType = $primary->resolved_operational_type;
                $sourceType = $source->resolved_operational_type;
                if (
                    $primaryType !== null
                    && $sourceType !== null
                    && $primaryType !== ExpressEvent::TYPE_EXPRESS_GENERAL
                    && $sourceType !== ExpressEvent::TYPE_EXPRESS_GENERAL
                    && ($primary->isSharingProfit() !== $source->isSharingProfit())
                ) {
                    throw new RuntimeException('Event Express Sharing Profit tidak dapat digabungkan dengan Event Express Self Run / Sewa.');
                }

                // Apabila source sudah diposting jurnal sementara primary belum, pindahkan ke event yang sudah diposting?
                // Untuk keamanan, bila source sudah posted namun primary belum, tolak dan minta penggabungan diarahkan ke event yang sudah posted.
                if ($source->isPosted() && ! $primary->isPosted()) {
                    throw new RuntimeException("Event '{$source->event_name}' sudah diposting ke Jurnal Keuangan. Lakukan penggabungan dari/menuju event tersebut atau reopen terlebih dahulu.");
                }

                foreach ($source->recaps as $srcRecap) {
                    $existingPrimaryRecap = ExpressEventRecap::query()
                        ->where('express_event_id', $primary->id)
                        ->where('session_report_id', $srcRecap->session_report_id)
                        ->first();

                    if (! $existingPrimaryRecap) {
                        $srcRecap->update(['express_event_id' => $primary->id]);
                    } else {
                        ExpressEventRecapAllocation::where('express_event_recap_id', $srcRecap->id)->delete();
                        $srcRecap->delete();
                    }
                }

                $srcInvoiceLinks = ExpressEventInvoice::where('express_event_id', $source->id)->get();
                foreach ($srcInvoiceLinks as $srcLink) {
                    $alreadyOnPrimary = ExpressEventInvoice::query()
                        ->where('express_event_id', $primary->id)
                        ->where('invoice_id', $srcLink->invoice_id)
                        ->exists();

                    if (! $alreadyOnPrimary) {
                        $srcLink->update(['express_event_id' => $primary->id]);
                    } else {
                        $srcLink->delete();
                    }
                }

                $primaryUpdates = [];
                if ($primary->hpp_rate_per_sheet === null && $source->hpp_rate_per_sheet !== null) {
                    $primaryUpdates['hpp_rate_per_sheet'] = $source->hpp_rate_per_sheet;
                }
                if ($primary->event_id === null && $source->event_id !== null) {
                    $primaryUpdates['event_id'] = $source->event_id;
                }
                if ($primaryUpdates !== []) {
                    $primary->update($primaryUpdates);
                }

                $mergedIds[] = $source->id;
                $mergedNames[] = "{$source->event_name} ({$source->period_range_label})";
                $source->delete();
            }

            if ($mergedIds === []) {
                throw new RuntimeException('Tidak ada event yang dapat digabungkan.');
            }

            $primary->update([
                'needs_admin_verification' => false,
                'verification_note' => null,
            ]);

            $this->syncService->recalculateEventDatesAndMetadata($primary);

            if ($primary->requiresInvoice() && $primary->hasLinkedInvoices()) {
                $this->invoiceService->syncEventInvoiceAllocations($primary);
            }

            $primary->unsetRelation('latestHpp');
            $primary->load('latestHpp');
            if ($primary->latestHpp && ! $primary->isPosted() && $primary->hpp_rate_per_sheet !== null) {
                $preview = app(ExpressEventHppService::class)->calculatePreview($primary);
                $primary->latestHpp->update([
                    'total_sessions' => $preview['total_sessions'],
                    'total_sheets' => $preview['total_sheets'],
                    'total_revenue' => $preview['total_revenue'],
                    'total_hpp' => $preview['total_hpp'],
                    'gross_profit' => $preview['gross_profit'],
                    'calculated_at' => now(),
                ]);
            }

            ExpressEventAuditLog::record(
                $primary,
                'ADMIN_MERGED_EVENTS',
                ['merged_event_ids' => $mergedIds, 'merged_events' => $mergedNames],
                [
                    'period_range' => $primary->fresh()?->period_range_label,
                    'total_sessions' => $primary->fresh()?->total_allocated_sessions,
                    'total_sheets' => $primary->fresh()?->total_allocated_sheets,
                    'effective_revenue' => $primary->fresh()?->effective_revenue,
                ],
                'Penggabungan manual Event HPP oleh Admin dari: ' . implode(', ', $mergedNames)
            );

            return $primary->fresh(['invoices', 'recaps.allocations', 'recaps.sessionReport.cabang', 'latestHpp']);
        });
    }

    /**
     * Konsolidasi otomatis baris ExpressEvent yang memiliki nama event identik, jenis operasional kompatibel,
     * tidak memiliki konflik event_id / invoice_id berbeda, dan berada pada tanggal multi-hari berurutan (<= 2 hari)
     * atau masuk dalam rentang tanggal kontrak invoice yang sama.
     *
     * @return array{merged_count: int, details: array<int, array<string, mixed>>}
     */
    protected function consolidateContiguousMultiDaySiblings(?Karyawan $actor = null): array
    {
        $events = ExpressEvent::query()
            ->whereNull('finalized_at')
            ->with(['recaps.sessionReport.cabang', 'recaps.allocations', 'invoices', 'hppSnapshots', 'auditLogs'])
            ->orderBy('start_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $byNormName = [];
        foreach ($events as $ev) {
            $norm = $this->syncService->normalizeEventName($ev->event_name);
            if ($norm !== '') {
                $byNormName[$norm][] = $ev;
            }
        }

        $totalMerged = 0;
        $details = [];

        foreach ($byNormName as $normName => $siblings) {
            $n = count($siblings);
            if ($n < 2) {
                continue;
            }

            $parent = range(0, $n - 1);
            $find = function (int $x) use (&$parent, &$find): int {
                return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
            };
            $union = function (int $x, int $y) use (&$parent, $find): void {
                $rx = $find($x);
                $ry = $find($y);
                if ($rx !== $ry) {
                    $parent[$ry] = $rx;
                }
            };

            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($this->areProvenContiguousSameOperationalEvent($siblings[$i], $siblings[$j], $normName)) {
                        $union($i, $j);
                    }
                }
            }

            $clusters = [];
            for ($i = 0; $i < $n; $i++) {
                $clusters[$find($i)][] = $siblings[$i]->id;
            }

            foreach ($clusters as $clusterEventIds) {
                if (count($clusterEventIds) < 2) {
                    continue;
                }

                $outcome = $this->mergeDuplicateGroup(
                    $clusterEventIds,
                    "contiguous:{$normName}",
                    $actor
                );
                $totalMerged += $outcome['merged_count'];
                if ($outcome['detail'] !== null) {
                    $details[] = $outcome['detail'];
                }
            }
        }

        return [
            'merged_count' => $totalMerged,
            'details' => $details,
        ];
    }

    protected function areProvenContiguousSameOperationalEvent(ExpressEvent $a, ExpressEvent $b, string $normName): bool
    {
        if (! $a->start_date || ! $b->start_date) {
            return false;
        }

        if ($a->isPosted() && $b->isPosted()) {
            return false;
        }

        // Hormati keputusan Admin apabila salah satu sudah diverifikasi terpisah secara eksplisit
        $verifiedSeparately = $a->auditLogs->contains('action', 'ADMIN_VERIFIED_IDENTITY')
            || $b->auditLogs->contains('action', 'ADMIN_VERIFIED_IDENTITY');
        if ($verifiedSeparately) {
            return false;
        }

        // Jenis operasional harus sejenis (tidak boleh mencampur Sharing Profit dengan Self Run / Sewa)
        $typeA = $a->resolved_operational_type;
        $typeB = $b->resolved_operational_type;
        if (
            $typeA !== null
            && $typeB !== null
            && $typeA !== ExpressEvent::TYPE_EXPRESS_GENERAL
            && $typeB !== ExpressEvent::TYPE_EXPRESS_GENERAL
            && $typeA !== $typeB
        ) {
            return false;
        }

        $evIdsA = $this->collectExplicitEventIds($a);
        $evIdsB = $this->collectExplicitEventIds($b);
        if ($evIdsA !== [] && $evIdsB !== [] && array_intersect($evIdsA, $evIdsB) === []) {
            return false;
        }

        $invIdsA = $this->collectExplicitInvoiceIds($a);
        $invIdsB = $this->collectExplicitInvoiceIds($b);
        if ($invIdsA !== [] && $invIdsB !== [] && array_intersect($invIdsA, $invIdsB) === []) {
            return false;
        }

        // Pastikan tidak ada Invoice atau Event terpisah di database yang memang dibuat khusus untuk tanggal sisi satunya
        $combinedInvIds = array_values(array_unique(array_merge($invIdsA, $invIdsB)));
        $combinedEvIds = array_values(array_unique(array_merge($evIdsA, $evIdsB)));

        if ($this->hasSeparateDatabaseContractOnDates($normName, $a, $b, $combinedInvIds, $combinedEvIds)) {
            return false;
        }

        // Cek apakah rentang tanggal dicakup langsung oleh event_date..event_end_date dari invoice terhubung
        $allLinkedInvoices = $a->invoices->concat($b->invoices)->unique('id');
        foreach ($allLinkedInvoices as $inv) {
            if ($inv->event_date && $inv->event_end_date) {
                $invStart = $inv->event_date->toDateString();
                $invEnd = $inv->event_end_date->toDateString();
                $minEv = min($a->start_date->toDateString(), $b->start_date->toDateString());
                $maxEv = max(($a->end_date ?? $a->start_date)->toDateString(), ($b->end_date ?? $b->start_date)->toDateString());
                if ($minEv >= $invStart && $maxEv <= $invEnd) {
                    return true;
                }
            }
        }

        // Multi-day event berurutan langsung (selisih jeda <= 2 hari kalender, mis. 29 Okt dengan 30-31 Okt)
        return $this->computeIntervalGapDays($a, $b) <= 2;
    }

    protected function computeIntervalGapDays(ExpressEvent $a, ExpressEvent $b): int
    {
        $aStart = $a->start_date->copy()->startOfDay();
        $aEnd = ($a->end_date ?? $a->start_date)->copy()->startOfDay();
        $bStart = $b->start_date->copy()->startOfDay();
        $bEnd = ($b->end_date ?? $b->start_date)->copy()->startOfDay();

        if ($aEnd->lt($bStart)) {
            return (int) $aEnd->diffInDays($bStart);
        }

        if ($bEnd->lt($aStart)) {
            return (int) $bEnd->diffInDays($aStart);
        }

        return 0;
    }

    /**
     * @return array<int, int>
     */
    protected function collectExplicitEventIds(ExpressEvent $event): array
    {
        $ids = [];
        if ($event->event_id !== null && (int) $event->event_id > 0) {
            $ids[] = (int) $event->event_id;
        }
        foreach ($event->recaps as $recap) {
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
    protected function collectExplicitInvoiceIds(ExpressEvent $event): array
    {
        $ids = $event->invoices->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach ($event->recaps as $recap) {
            $invId = $recap->sessionReport?->invoice_id;
            if ($invId !== null && (int) $invId > 0) {
                $ids[] = (int) $invId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Cek apakah di tabel invoices atau events memang terdapat 2 kontrak/event berbeda dengan nama yang sama
     * untuk tanggal $a dan tanggal $b (mencegah penggabungan jika ternyata ada 2 event terpisah berdekatan).
     *
     * @param  array<int, int>  $knownInvoiceIds
     * @param  array<int, int>  $knownEventIds
     */
    protected function hasSeparateDatabaseContractOnDates(
        string $normName,
        ExpressEvent $a,
        ExpressEvent $b,
        array $knownInvoiceIds,
        array $knownEventIds,
    ): bool {
        $dateA = $a->start_date?->toDateString();
        $dateB = $b->start_date?->toDateString();

        if (! $dateA || ! $dateB || $dateA === $dateB) {
            return false;
        }

        if (Schema::hasTable('invoices')) {
            $matchingInvoices = Invoice::query()
                ->whereNotNull('event_name')
                ->where(function ($q) use ($dateA, $dateB): void {
                    $q->whereDate('event_date', $dateA)
                        ->orWhereDate('event_date', $dateB);
                })
                ->get(['id', 'event_name', 'event_date'])
                ->filter(fn (Invoice $inv): bool => $this->syncService->normalizeEventName($inv->event_name) === $normName);

            if ($matchingInvoices->pluck('id')->unique()->count() > 1) {
                return true;
            }

            if ($knownInvoiceIds !== [] && $matchingInvoices->contains(fn (Invoice $inv): bool => ! in_array((int) $inv->id, $knownInvoiceIds, true))) {
                return true;
            }
        }

        if (Schema::hasTable('events')) {
            $matchingDbEvents = Event::query()
                ->whereNotNull('name')
                ->where(function ($q) use ($dateA, $dateB): void {
                    $q->whereDate('date', $dateA)
                        ->orWhereDate('date', $dateB);
                })
                ->get(['id', 'name', 'date'])
                ->filter(fn (Event $ev): bool => $this->syncService->normalizeEventName($ev->name) === $normName);

            if ($matchingDbEvents->pluck('id')->unique()->count() > 1) {
                return true;
            }

            if ($knownEventIds !== [] && $matchingDbEvents->contains(fn (Event $ev): bool => ! in_array((int) $ev->id, $knownEventIds, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Admin menandai bahwa identitas event historis yang ambigu telah diverifikasi.
     */
    public function markVerifiedByAdmin(ExpressEvent $event, ?Karyawan $actor = null, ?string $note = null): ExpressEvent
    {
        return DB::transaction(function () use ($event, $actor, $note): ExpressEvent {
            $locked = ExpressEvent::where('id', $event->id)->lockForUpdate()->firstOrFail();

            $oldNote = $locked->verification_note;

            $locked->update([
                'needs_admin_verification' => false,
                'verification_note' => null,
            ]);

            ExpressEventAuditLog::record(
                $locked,
                'ADMIN_VERIFIED_IDENTITY',
                ['verification_note' => $oldNote],
                ['needs_admin_verification' => false],
                $note ? trim($note) : 'Identitas event historis telah diverifikasi oleh Admin.'
            );

            return $locked->fresh();
        });
    }

    /**
     * @return array{
     *     event_id: int|null,
     *     invoice_id: int|null,
     *     operational_type: string|null,
     *     identity_key: string|null,
     *     proven_merge_key: string|null,
     *     has_explicit_id: bool,
     *     conflict_note: string|null
     * }
     */
    protected function analyzeEventIdentity(ExpressEvent $event): array
    {
        $eventIds = [];
        if ($event->event_id !== null && (int) $event->event_id > 0) {
            $eventIds[] = (int) $event->event_id;
        }

        $invoiceIds = $event->invoices->pluck('id')->map(fn ($id): int => (int) $id)->all();

        foreach ($event->recaps as $recap) {
            $rep = $recap->sessionReport;
            if (! $rep) {
                continue;
            }
            if ($rep->event_id !== null && (int) $rep->event_id > 0) {
                $eventIds[] = (int) $rep->event_id;
            }
            if ($rep->invoice_id !== null && (int) $rep->invoice_id > 0) {
                $invoiceIds[] = (int) $rep->invoice_id;
            }
        }

        $eventIds = array_values(array_unique($eventIds));
        $invoiceIds = array_values(array_unique($invoiceIds));

        if (count($eventIds) > 1) {
            return [
                'event_id' => null,
                'invoice_id' => null,
                'operational_type' => $event->resolved_operational_type,
                'identity_key' => null,
                'proven_merge_key' => null,
                'has_explicit_id' => false,
                'conflict_note' => 'Event HPP ini memiliki rekap sesi dari beberapa ID Event Operasional berbeda (' . implode(', ', $eventIds) . '). Harap verifikasi pemisahannya.',
            ];
        }

        if (count($eventIds) === 1) {
            $evId = $eventIds[0];

            return [
                'event_id' => $evId,
                'invoice_id' => $invoiceIds[0] ?? null,
                'operational_type' => $event->resolved_operational_type,
                'identity_key' => "event:{$evId}",
                'proven_merge_key' => "event:{$evId}",
                'has_explicit_id' => true,
                'conflict_note' => null,
            ];
        }

        if (count($invoiceIds) === 1) {
            $invId = $invoiceIds[0];

            return [
                'event_id' => null,
                'invoice_id' => $invId,
                'operational_type' => $event->resolved_operational_type,
                'identity_key' => "invoice:{$invId}",
                'proven_merge_key' => "invoice:{$invId}",
                'has_explicit_id' => true,
                'conflict_note' => null,
            ];
        }

        if (count($invoiceIds) > 1) {
            return [
                'event_id' => null,
                'invoice_id' => $invoiceIds[0],
                'operational_type' => $event->resolved_operational_type,
                'identity_key' => 'multi-invoice:' . $event->id,
                'proven_merge_key' => null,
                'has_explicit_id' => true,
                'conflict_note' => null,
            ];
        }

        $opType = $event->resolved_operational_type ?? ExpressEvent::TYPE_EXPRESS_GENERAL;
        $normName = $this->syncService->normalizeEventName($event->event_name);
        $startStr = $event->start_date?->toDateString();

        $manualKey = ($normName !== '' && $startStr !== null)
            ? sprintf('manual:%s:%s:%s', $opType, $startStr, substr(sha1($normName), 0, 16))
            : null;

        return [
            'event_id' => null,
            'invoice_id' => null,
            'operational_type' => $opType,
            'identity_key' => $manualKey,
            'proven_merge_key' => null,
            'has_explicit_id' => false,
            'conflict_note' => null,
        ];
    }

    /**
     * @param  array<int, int>  $eventIds
     * @return array{merged_count: int, detail: array<string, mixed>|null}
     */
    protected function mergeDuplicateGroup(array $eventIds, string $mergeKey, ?Karyawan $actor = null): array
    {
        return DB::transaction(function () use ($eventIds, $mergeKey, $actor): array {
            /** @var Collection<int, ExpressEvent> $groupEvents */
            $groupEvents = ExpressEvent::query()
                ->whereIn('id', $eventIds)
                ->with(['recaps.allocations', 'recaps.sessionReport.cabang', 'invoices', 'hppSnapshots'])
                ->lockForUpdate()
                ->orderBy('id', 'asc')
                ->get();

            if ($groupEvents->count() < 2) {
                return ['merged_count' => 0, 'detail' => null];
            }

            // Jangan menggabungkan otomatis jika ada lebih dari satu event yang sudah telanjur diposting ke jurnal keuangan
            $postedEvents = $groupEvents->filter(fn (ExpressEvent $e): bool => $e->isPosted());
            if ($postedEvents->count() > 1) {
                foreach ($groupEvents as $ev) {
                    $ev->update([
                        'needs_admin_verification' => true,
                        'verification_note' => 'Terdapat beberapa baris event terposting ke jurnal untuk identitas yang sama. Harap verifikasi manual.',
                    ]);
                }

                return ['merged_count' => 0, 'detail' => null];
            }

            // Pilih event utama: utamakan yang sudah posted/confirmed, atau yang memiliki tarif/invoice, lalu ID paling awal
            /** @var ExpressEvent $primary */
            $primary = $postedEvents->first()
                ?? $groupEvents->first(fn (ExpressEvent $e): bool => $e->isConfirmed())
                ?? $groupEvents->first(fn (ExpressEvent $e): bool => $e->hasLinkedInvoices() || $e->hpp_rate_per_sheet !== null)
                ?? $groupEvents->first();

            $duplicates = $groupEvents->filter(fn (ExpressEvent $e): bool => (int) $e->id !== (int) $primary->id);
            $mergedIds = [];

            foreach ($duplicates as $duplicate) {
                if ($duplicate->isPosted()) {
                    continue;
                }

                // Pindahkan seluruh ExpressEventRecap ke primary tanpa menghapus SessionReport asli
                foreach ($duplicate->recaps as $dupRecap) {
                    $existingPrimaryRecap = ExpressEventRecap::query()
                        ->where('express_event_id', $primary->id)
                        ->where('session_report_id', $dupRecap->session_report_id)
                        ->first();

                    if (! $existingPrimaryRecap) {
                        $dupRecap->update(['express_event_id' => $primary->id]);
                    } else {
                        // Rekap yang sama persis sudah ada di primary -> hapus baris pivot duplikatnya saja
                        ExpressEventRecapAllocation::where('express_event_recap_id', $dupRecap->id)->delete();
                        $dupRecap->delete();
                    }
                }

                // Pindahkan relasi invoice (jika ada) tanpa duplikasi invoice_id
                $dupLinks = ExpressEventInvoice::where('express_event_id', $duplicate->id)->get();
                foreach ($dupLinks as $dupLink) {
                    $alreadyOnPrimary = ExpressEventInvoice::query()
                        ->where('express_event_id', $primary->id)
                        ->where('invoice_id', $dupLink->invoice_id)
                        ->exists();

                    if (! $alreadyOnPrimary) {
                        $dupLink->update(['express_event_id' => $primary->id]);
                    } else {
                        $dupLink->delete();
                    }
                }

                if ($primary->hpp_rate_per_sheet === null && $duplicate->hpp_rate_per_sheet !== null) {
                    $primary->update(['hpp_rate_per_sheet' => $duplicate->hpp_rate_per_sheet]);
                }

                $mergedIds[] = $duplicate->id;
                $duplicate->delete();
            }

            if ($mergedIds === []) {
                return ['merged_count' => 0, 'detail' => null];
            }

            $this->syncService->recalculateEventDatesAndMetadata($primary);

            if ($primary->requiresInvoice() && $primary->hasLinkedInvoices()) {
                $this->invoiceService->syncEventInvoiceAllocations($primary);
            }

            $primary->unsetRelation('latestHpp');
            $primary->load('latestHpp');
            if ($primary->latestHpp && ! $primary->isPosted() && $primary->hpp_rate_per_sheet !== null) {
                $preview = app(ExpressEventHppService::class)->calculatePreview($primary);
                $primary->latestHpp->update([
                    'total_sessions' => $preview['total_sessions'],
                    'total_sheets' => $preview['total_sheets'],
                    'total_revenue' => $preview['total_revenue'],
                    'total_hpp' => $preview['total_hpp'],
                    'gross_profit' => $preview['gross_profit'],
                    'calculated_at' => now(),
                ]);
            }

            ExpressEventAuditLog::record(
                $primary,
                'HISTORICAL_CONSOLIDATED',
                ['merged_duplicate_event_ids' => $mergedIds],
                ['identity_key' => $mergeKey],
                'Konsolidasi otomatis event duplikat berdasarkan identitas unik (' . $mergeKey . ').'
            );

            return [
                'merged_count' => count($mergedIds),
                'detail' => [
                    'primary_event_id' => $primary->id,
                    'event_name' => $primary->event_name,
                    'identity_key' => $mergeKey,
                    'merged_event_ids' => $mergedIds,
                ],
            ];
        });
    }

    protected function hasRequiredSchema(): bool
    {
        return Schema::hasTable('express_events')
            && Schema::hasTable('express_event_recaps')
            && Schema::hasColumn('express_events', 'event_id')
            && Schema::hasColumn('express_events', 'operational_identity_key')
            && Schema::hasColumn('express_events', 'needs_admin_verification');
    }
}
