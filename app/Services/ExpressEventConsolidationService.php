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

        // Langkah 2: Gabungkan grup yang terbukti merupakan satu event operasional yang sama
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

                    // Hanya jika keduanya TIDAK punya identitas eksplisit (data historis murni teks) & jarak tanggal dekat (<= 30 hari)
                    if (! $infoA['has_explicit_id'] && ! $infoB['has_explicit_id'] && $a->start_date && $b->start_date) {
                        $daysDiff = abs($a->end_date->diffInDays($b->start_date));
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
