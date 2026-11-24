<?php

namespace App\Services;

use App\Models\Cabang;
use App\Models\ExpressEvent;
use App\Models\ExpressEventAuditLog;
use App\Models\ExpressEventRecap;
use App\Models\ExpressEventRecapAllocation;
use App\Models\Invoice;
use App\Models\SessionReport;
use App\Utils\RupiahHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExpressEventAllocationService
{
    public function __construct(
        protected BranchInputModeService $branchInputModeService
    ) {}

    /**
     * Hitung kapasitas total sesi, lembar, dan omzet sumber dari sebuah SessionReport.
     *
     * @return array{sessions: int, sheets: int, revenue: int}
     */
    public function getSourceTotals(SessionReport $report): array
    {
        // 1. Sesi
        if ($report->cabang && $this->branchInputModeService->usesManualSessionInput($report->cabang)) {
            $sessions = (int) ($report->wedding_total_sessions ?: $report->total_transactions ?: $report->total_sessions);
            $sheets = (int) ($report->wedding_total_sheets ?: $report->total_sessions);
        } else {
            $sessions = (int) $report->total_transactions;
            if ($sessions === 0 && $report->relationLoaded('transactions') && $report->transactions->isNotEmpty()) {
                $sessions = $report->transactions->count();
            }
            $sheets = (int) $report->total_sessions;
            if ($sheets === 0 && $report->relationLoaded('transactions') && $report->transactions->isNotEmpty()) {
                $sheets = (int) $report->transactions->sum('session_count');
            }
        }

        $sessions = max(0, $sessions ?: (int) $report->total_sessions);
        $sheets = max(0, $sheets ?: (int) $report->wedding_total_sheets);

        // Jika rekap memiliki relasi invoice (terutama Express Sewa), omzet bersumber dari total nilai invoice
        $isExpressSewa = str_contains(strtolower($report->cabang?->nama_cabang ?? ''), 'express sewa');
        $invoice = $report->invoice ?? ($report->invoice_id ? \App\Models\Invoice::find($report->invoice_id) : null);

        if ($isExpressSewa && $invoice) {
            $revenue = (int) round((float) $invoice->total);
        } elseif ($invoice && (int) $report->grand_total_amount === 0) {
            $revenue = (int) round((float) $invoice->total);
        } else {
            $revenue = max(0, (int) $report->grand_total_amount);
        }

        return [
            'sessions' => $sessions,
            'sheets' => $sheets,
            'revenue' => $revenue,
        ];
    }

    /**
     * Total sesi, lembar, dan omzet yang telah dialokasikan ke SEMUA event
     * untuk sebuah SessionReport (opsional mengecualikan alokasi/event tertentu).
     *
     * @return array{sessions: int, sheets: int, revenue: int}
     */
    public function getAllocatedTotals(SessionReport|int $report, ?int $exceptEventId = null): array
    {
        $reportId = $report instanceof SessionReport ? $report->id : $report;

        $query = DB::table('express_event_recap_allocations')
            ->join('express_event_recaps', 'express_event_recaps.id', '=', 'express_event_recap_allocations.express_event_recap_id')
            ->where('express_event_recaps.session_report_id', $reportId);

        if ($exceptEventId !== null) {
            $query->where('express_event_recaps.express_event_id', '!=', $exceptEventId);
        }

        $row = $query->selectRaw('
            COALESCE(SUM(allocated_sessions), 0) as total_sessions,
            COALESCE(SUM(allocated_sheets), 0) as total_sheets,
            COALESCE(SUM(allocated_revenue), 0) as total_revenue
        ')->first();

        return [
            'sessions' => (int) ($row?->total_sessions ?? 0),
            'sheets' => (int) ($row?->total_sheets ?? 0),
            'revenue' => (int) ($row?->total_revenue ?? 0),
        ];
    }

    /**
     * Hitung sisa kapasitas sesi, lembar, dan omzet yang belum dialokasikan untuk sebuah rekap.
     *
     * @return array{sessions: int, sheets: int, revenue: int}
     */
    public function getRemainingTotals(SessionReport $report, ?int $exceptEventId = null): array
    {
        $source = $this->getSourceTotals($report);
        $allocated = $this->getAllocatedTotals($report, $exceptEventId);

        $remainingRevenue = max(0, $source['revenue'] - $allocated['revenue']);

        // Jika rekap terhubung ke invoice, periksa agregasi alokasi untuk invoice yang sama
        // agar invoice yang sama tidak dihitung lebih dari satu kali lintas rekap
        if ($report->invoice_id) {
            $allocatedForInvoiceQuery = DB::table('express_event_recap_allocations')
                ->join('express_event_recaps', 'express_event_recaps.id', '=', 'express_event_recap_allocations.express_event_recap_id')
                ->join('session_reports', 'session_reports.id', '=', 'express_event_recaps.session_report_id')
                ->where('session_reports.invoice_id', $report->invoice_id);

            if ($exceptEventId !== null) {
                $allocatedForInvoiceQuery->where('express_event_recaps.express_event_id', '!=', $exceptEventId);
            }

            $totalAllocatedInvoice = (int) $allocatedForInvoiceQuery->sum('express_event_recap_allocations.allocated_revenue');
            $remainingRevenue = min($remainingRevenue, max(0, $source['revenue'] - $totalAllocatedInvoice));
        }

        return [
            'sessions' => max(0, $source['sessions'] - $allocated['sessions']),
            'sheets' => max(0, $source['sheets'] - $allocated['sheets']),
            'revenue' => $remainingRevenue,
        ];
    }

    /**
     * Cek apakah sebuah cabang termasuk kategori Express (bukan Janus dan bukan Golio).
     */
    public function isExpressBranch(?string $branchName): bool
    {
        if (blank($branchName)) {
            return false;
        }

        $lower = strtolower(trim($branchName));

        // Strict exclude Janus & Golio
        if (str_contains($lower, 'janus') || str_contains($lower, 'golio')) {
            return false;
        }

        // Include express or wedding
        return str_contains($lower, 'express') || str_contains($lower, 'wedding');
    }

    /**
     * Resolves the event name for a session report according to Express branch rules:
     * - Self Run: from connected invoice event_name, fallback to event_note
     * - Sewa: from connected invoice event_name, fallback to event_note
     * - Sharing Profit: from manual event_note, fallback to invoice event_name
     * - Historical fallback: match invoice by date & cabang, or fallback to report number
     */
    public function resolveReportEventName(SessionReport $report): ?string
    {
        $cabangName = strtolower($report->cabang?->nama_cabang ?? '');
        $invoice = $report->invoice ?? ($report->invoice_id ? Invoice::find($report->invoice_id) : null);

        // Fallback pencocokan invoice berdasarkan tanggal jika invoice_id belum diset pada rekap historis
        if (! $invoice && ! $report->event_note && $report->report_date && (str_contains($cabangName, 'self run') || str_contains($cabangName, 'sewa'))) {
            $dateStr = $report->event_date?->toDateString() ?? $report->report_date->toDateString();
            $matchingInvoice = Invoice::query()
                ->whereDate('event_date', $dateStr)
                ->forCabang($report->cabang)
                ->first();
            if ($matchingInvoice) {
                $invoice = $matchingInvoice;
            }
        }

        if (str_contains($cabangName, 'self run') || str_contains($cabangName, 'sewa')) {
            $name = $invoice?->event_name ?? $report->event_note;
        } elseif (str_contains($cabangName, 'sharing profit')) {
            $name = $report->event_note ?? $invoice?->event_name;
        } else {
            $name = $invoice?->event_name ?? $report->event_note;
        }

        $trimmed = trim((string) $name);
        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Resolves human-readable branch type for display:
     * Self Run, Sewa, Sharing Profit, or the branch name.
     */
    public function resolveReportBranchType(SessionReport $report): string
    {
        $cabangName = strtolower($report->cabang?->nama_cabang ?? '');
        if (str_contains($cabangName, 'self run')) {
            return 'Self Run';
        }
        if (str_contains($cabangName, 'sewa')) {
            return 'Sewa';
        }
        if (str_contains($cabangName, 'sharing profit')) {
            return 'Sharing Profit';
        }
        return $report->cabang?->nama_cabang ?? 'Express';
    }

    /**
     * Mengambil daftar nama event unik dari rekap sesi Express yang berstatus Approved
     * dan dari daftar Invoice Express yang tersedia.
     *
     * @param int|null $currentEventId
     * @return array<string, string> [event_name => event_name]
     */
    public function getAvailableEventNames(?int $currentEventId = null): array
    {
        $allocatedToOtherEventReportIds = DB::table('express_event_recaps')
            ->when($currentEventId !== null, fn ($q) => $q->where('express_event_id', '!=', $currentEventId))
            ->pluck('session_report_id')
            ->all();

        $reports = SessionReport::query()
            ->where('status', SessionReport::STATUS_APPROVED)
            ->when(! empty($allocatedToOtherEventReportIds), fn ($q) => $q->whereNotIn('id', $allocatedToOtherEventReportIds))
            ->with(['cabang', 'invoice'])
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->get();

        $names = [];
        foreach ($reports as $report) {
            if (! $this->isExpressBranch($report->cabang?->nama_cabang)) {
                continue;
            }

            $eventName = $this->resolveReportEventName($report);
            if ($eventName !== null) {
                $names[$eventName] = $eventName;
            } else {
                // Rekap historis tanpa nama event tetap disediakan opsinya
                $fallbackName = "Rekap {$report->report_number} ({$report->cabang?->nama_cabang})";
                $names[$fallbackName] = $fallbackName;
            }
        }

        // Sertakan juga nama-nama event dari Invoice Express (Self Run & Sewa)
        $invoices = Invoice::query()
            ->whereNotNull('event_name')
            ->where('event_name', '!=', '')
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->get();

        foreach ($invoices as $inv) {
            if ($inv->isExpressSelfRun() || $inv->isExpressSewa()) {
                $trimmedInvName = trim($inv->event_name);
                if ($trimmedInvName !== '') {
                    $names[$trimmedInvName] = $trimmedInvName;
                }
            }
        }

        // Event yang sudah pernah dibuat Event HPP-nya tidak boleh muncul lagi saat membuat event baru
        $alreadyCreatedEventNames = ExpressEvent::query()
            ->when($currentEventId !== null, fn ($q) => $q->where('id', '!=', $currentEventId))
            ->whereNotNull('event_name')
            ->pluck('event_name')
            ->map(fn ($n) => trim($n))
            ->all();

        foreach ($alreadyCreatedEventNames as $createdName) {
            foreach (array_keys($names) as $existingKey) {
                if (strcasecmp($existingKey, $createdName) === 0) {
                    unset($names[$existingKey]);
                }
            }
        }

        // Jika dalam mode Edit, pastikan nama event saat ini tetap ada dalam daftar opsi
        if ($currentEventId !== null) {
            $currentEvent = ExpressEvent::find($currentEventId);
            if ($currentEvent && ! blank($currentEvent->event_name)) {
                $names[$currentEvent->event_name] = $currentEvent->event_name;
            }
        }

        ksort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * Mengambil rekap sesi Express yang relevan dengan nama event terpilih,
     * berstatus Approved, dan belum dialokasikan ke Event HPP lain.
     *
     * @param string|null $eventName
     * @param int|null $currentEventId
     * @return Collection<int, array<string, mixed>>
     */
    public function getEligibleRecapsForEventName(?string $eventName, ?int $currentEventId = null): Collection
    {
        $allocatedToOtherEventReportIds = DB::table('express_event_recaps')
            ->when($currentEventId !== null, fn ($q) => $q->where('express_event_id', '!=', $currentEventId))
            ->pluck('session_report_id')
            ->all();

        $reports = SessionReport::query()
            ->where('status', SessionReport::STATUS_APPROVED)
            ->when(! empty($allocatedToOtherEventReportIds), fn ($q) => $q->whereNotIn('id', $allocatedToOtherEventReportIds))
            ->with(['cabang', 'invoice', 'transactions', 'crews'])
            ->orderBy('report_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $trimmedTarget = blank($eventName) ? null : trim($eventName);

        return $reports
            ->filter(function (SessionReport $report) use ($trimmedTarget, $currentEventId) {
                if (! $this->isExpressBranch($report->cabang?->nama_cabang)) {
                    return false;
                }

                // Jika nama event belum dipilih, tampilkan semua rekap express yang eligible
                if ($trimmedTarget === null) {
                    return true;
                }

                $reportEventName = $this->resolveReportEventName($report);
                if ($reportEventName !== null && strcasecmp($reportEventName, $trimmedTarget) === 0) {
                    return true;
                }

                // Cek fallback rekap historis
                $fallbackName = "Rekap {$report->report_number} ({$report->cabang?->nama_cabang})";
                if (strcasecmp($fallbackName, $trimmedTarget) === 0) {
                    return true;
                }

                if ($currentEventId !== null) {
                    return DB::table('express_event_recaps')
                        ->where('express_event_id', $currentEventId)
                        ->where('session_report_id', $report->id)
                        ->exists();
                }

                return false;
            })
            ->map(function (SessionReport $report) use ($currentEventId) {
                $source = $this->getSourceTotals($report);
                $allocatedExceptCurrent = $this->getAllocatedTotals($report, $currentEventId);

                $remainingForEvent = [
                    'sessions' => max(0, $source['sessions'] - $allocatedExceptCurrent['sessions']),
                    'sheets' => max(0, $source['sheets'] - $allocatedExceptCurrent['sheets']),
                    'revenue' => max(0, $source['revenue'] - $allocatedExceptCurrent['revenue']),
                ];

                $branchType = $this->resolveReportBranchType($report);
                $dateStr = $report->report_date ? $report->report_date->format('d/m/Y') : '-';
                $revStr = RupiahHelper::format($source['revenue']);
                $label = "{$report->report_number} · {$branchType} ({$dateStr}) — {$source['sessions']} sesi · {$source['sheets']} lbr · {$revStr}";

                return [
                    'id' => $report->id,
                    'report_number' => $report->report_number,
                    'report_date' => $dateStr,
                    'raw_date' => $report->event_date?->toDateString() ?? $report->report_date?->toDateString(),
                    'cabang_name' => $report->cabang?->nama_cabang ?? '-',
                    'branch_type' => $branchType,
                    'label' => $label,
                    'source_sessions' => $source['sessions'],
                    'source_sheets' => $source['sheets'],
                    'source_revenue' => $source['revenue'],
                    'remaining_sessions' => $remainingForEvent['sessions'],
                    'remaining_sheets' => $remainingForEvent['sheets'],
                    'remaining_revenue' => $remainingForEvent['revenue'],
                ];
            })
            ->values();
    }

    /**
     * Ambil seluruh rekap sesi Express yang sudah approved dan masih memiliki sisa alokasi
     * (atau sudah terhubung ke event yang sedang diedit).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getEligibleRecaps(?int $currentEventId = null): Collection
    {
        $allocatedToOtherEventReportIds = DB::table('express_event_recaps')
            ->when($currentEventId !== null, fn ($q) => $q->where('express_event_id', '!=', $currentEventId))
            ->pluck('session_report_id')
            ->all();

        $reports = SessionReport::query()
            ->where('status', SessionReport::STATUS_APPROVED)
            ->when(! empty($allocatedToOtherEventReportIds), fn ($q) => $q->whereNotIn('id', $allocatedToOtherEventReportIds))
            ->with(['cabang', 'crews', 'transactions', 'invoice'])
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->get();

        return $reports
            ->filter(fn (SessionReport $r) => $this->isExpressBranch($r->cabang?->nama_cabang))
            ->map(function (SessionReport $report) use ($currentEventId) {
                $source = $this->getSourceTotals($report);
                $allocatedGlobal = $this->getAllocatedTotals($report);
                $allocatedExceptCurrent = $this->getAllocatedTotals($report, $currentEventId);

                $remainingForEvent = [
                    'sessions' => max(0, $source['sessions'] - $allocatedExceptCurrent['sessions']),
                    'sheets' => max(0, $source['sheets'] - $allocatedExceptCurrent['sheets']),
                    'revenue' => max(0, $source['revenue'] - $allocatedExceptCurrent['revenue']),
                ];

                $branchType = $this->resolveReportBranchType($report);
                $dateStr = $report->report_date?->format('d/m/Y') ?? '-';
                $revStr = RupiahHelper::format($source['revenue']);
                $label = "{$report->report_number} · {$branchType} ({$dateStr}) — {$source['sessions']} sesi · {$source['sheets']} lbr · {$revStr}";

                return [
                    'id' => $report->id,
                    'report_number' => $report->report_number,
                    'report_date' => $dateStr,
                    'raw_date' => $report->event_date?->toDateString() ?? $report->report_date?->toDateString(),
                    'cabang_name' => $report->cabang?->nama_cabang ?? '-',
                    'branch_type' => $branchType,
                    'label' => $label,
                    'crews' => $report->crew_names,
                    'source_sessions' => $source['sessions'],
                    'source_sheets' => $source['sheets'],
                    'source_revenue' => $source['revenue'],
                    'allocated_sessions' => $allocatedGlobal['sessions'],
                    'allocated_sheets' => $allocatedGlobal['sheets'],
                    'allocated_revenue' => $allocatedGlobal['revenue'],
                    'remaining_sessions' => $remainingForEvent['sessions'],
                    'remaining_sheets' => $remainingForEvent['sheets'],
                    'remaining_revenue' => $remainingForEvent['revenue'],
                    'is_fully_allocated' => $remainingForEvent['sheets'] === 0 && $remainingForEvent['revenue'] === 0,
                ];
            })
            ->filter(function ($item) use ($currentEventId) {
                // Selalu sertakan jika masih memiliki sisa lembar/omzet atau jika sudah ada alokasi pada currentEventId
                if ($item['remaining_sheets'] > 0 || $item['remaining_revenue'] > 0) {
                    return true;
                }

                if ($currentEventId !== null) {
                    return ExpressEventRecap::where('express_event_id', $currentEventId)
                        ->where('session_report_id', $item['id'])
                        ->exists();
                }

                return false;
            })
            ->values();
    }

    /**
     * Simpan alokasi rekap sesi untuk sebuah event secara transaksional dengan row locking.
     *
     * @param ExpressEvent $event
     * @param array<int, array{session_report_id: int, allocated_sessions: int, allocated_sheets: int, allocated_revenue: int, notes?: string|null}> $allocationsData
     * @return ExpressEvent
     */
    public function syncAllocations(ExpressEvent $event, array $allocationsData): ExpressEvent
    {
        return DB::transaction(function () use ($event, $allocationsData) {
            // Lock event for update
            $lockedEvent = ExpressEvent::where('id', $event->id)->lockForUpdate()->firstOrFail();

            if ($lockedEvent->isFinalized()) {
                throw new RuntimeException('Event telah berstatus finalized dan tidak dapat diubah tanpa proses pembukaan kembali (reopen).');
            }

            $oldSnapshot = [
                'allocations' => $lockedEvent->allocations()->get()->toArray(),
                'reconciliation_status' => $lockedEvent->reconciliation_status,
            ];

            $seenReportIds = [];
            $allRecapsComplete = true;
            $hasAnyAllocation = false;

            // Track active recap IDs to delete removed ones
            $activeRecapIds = [];

            foreach ($allocationsData as $item) {
                $reportId = (int) $item['session_report_id'];

                if (in_array($reportId, $seenReportIds, true)) {
                    throw new RuntimeException("Duplikasi rekap: Rekap ID #{$reportId} tidak boleh dimasukkan lebih dari satu kali pada event yang sama.");
                }
                $seenReportIds[] = $reportId;

                // Lock the source session report to prevent concurrent over-allocation
                $report = SessionReport::where('id', $reportId)->lockForUpdate()->first();
                if (! $report) {
                    throw new RuntimeException("Rekap Sesi ID #{$reportId} tidak ditemukan.");
                }

                if ($report->status !== SessionReport::STATUS_APPROVED) {
                    throw new RuntimeException("Rekap {$report->report_number} belum berstatus APPROVED dan tidak dapat dialokasikan.");
                }

                if (! $this->isExpressBranch($report->cabang?->nama_cabang)) {
                    throw new RuntimeException("Rekap {$report->report_number} bukan merupakan cabang Express.");
                }

                // Satu rekap sesi hanya boleh terhubung ke satu Event HPP
                $isAllocatedElsewhere = DB::table('express_event_recaps')
                    ->where('session_report_id', $reportId)
                    ->where('express_event_id', '!=', $lockedEvent->id)
                    ->exists();

                if ($isAllocatedElsewhere) {
                    throw new RuntimeException("Rekap {$report->report_number} sudah dialokasikan ke Event HPP lain. Satu rekap sesi hanya boleh terhubung ke satu Event HPP.");
                }

                // Nama event harus konsisten antara invoice, rekap sesi, dan Event HPP
                $reportEventName = $this->resolveReportEventName($report);
                if (! empty($reportEventName) && ! empty($lockedEvent->event_name) && strcasecmp(trim($reportEventName), trim($lockedEvent->event_name)) !== 0) {
                    throw new RuntimeException("Nama event pada rekap {$report->report_number} ('{$reportEventName}') tidak sesuai dengan Nama Event HPP ('{$lockedEvent->event_name}').");
                }

                $allocSessions = (int) ($item['allocated_sessions'] ?? 0);
                $allocSheets = (int) ($item['allocated_sheets'] ?? 0);
                $allocRevenue = (int) ($item['allocated_revenue'] ?? 0);
                $notes = $item['notes'] ?? null;

                // Validation rules
                if ($allocSessions < 0 || $allocSheets < 0 || $allocRevenue < 0) {
                    throw new RuntimeException("Nilai alokasi pada rekap {$report->report_number} tidak boleh bernilai negatif.");
                }

                $source = $this->getSourceTotals($report);
                $allocatedElsewhere = $this->getAllocatedTotals($report, $lockedEvent->id);

                $maxSessions = $source['sessions'] - $allocatedElsewhere['sessions'];
                $maxSheets = $source['sheets'] - $allocatedElsewhere['sheets'];
                $maxRevenue = $source['revenue'] - $allocatedElsewhere['revenue'];

                if ($allocSessions > $maxSessions) {
                    throw new RuntimeException("Alokasi sesi ({$allocSessions}) pada rekap {$report->report_number} melebihi sisa yang tersedia ({$maxSessions} dari total {$source['sessions']}).");
                }

                if ($allocSheets > $maxSheets) {
                    throw new RuntimeException("Alokasi lembar ({$allocSheets}) pada rekap {$report->report_number} melebihi sisa yang tersedia ({$maxSheets} dari total {$source['sheets']}).");
                }

                if ($allocRevenue > $maxRevenue) {
                    throw new RuntimeException("Alokasi omzet (Rp " . number_format($allocRevenue, 0, ',', '.') . ") pada rekap {$report->report_number} melebihi sisa yang tersedia (Rp " . number_format($maxRevenue, 0, ',', '.') . " dari total Rp " . number_format($source['revenue'], 0, ',', '.') . ").");
                }

                if ($allocSessions > 0 || $allocSheets > 0 || $allocRevenue > 0) {
                    $hasAnyAllocation = true;
                }

                // Check if this source report has remaining unallocated
                $remainingAfterThis = [
                    'sheets' => $maxSheets - $allocSheets,
                    'revenue' => $maxRevenue - $allocRevenue,
                ];
                if ($remainingAfterThis['sheets'] > 0 || $remainingAfterThis['revenue'] > 0) {
                    $allRecapsComplete = false;
                }

                // Create or find ExpressEventRecap
                $eventRecap = ExpressEventRecap::firstOrCreate([
                    'express_event_id' => $lockedEvent->id,
                    'session_report_id' => $reportId,
                ], [
                    'status' => ExpressEventRecap::STATUS_ACTIVE,
                ]);

                $activeRecapIds[] = $eventRecap->id;

                // Update or create allocation
                ExpressEventRecapAllocation::updateOrCreate([
                    'express_event_recap_id' => $eventRecap->id,
                ], [
                    'allocated_sessions' => $allocSessions,
                    'allocated_sheets' => $allocSheets,
                    'allocated_revenue' => $allocRevenue,
                    'notes' => $notes,
                ]);
            }

            // Remove any recaps detached
            ExpressEventRecap::where('express_event_id', $lockedEvent->id)
                ->whereNotIn('id', $activeRecapIds)
                ->delete();

            // Determine reconciliation status
            if (! $hasAnyAllocation || empty($allocationsData)) {
                $newReconStatus = ExpressEvent::RECON_UNALLOCATED;
            } elseif ($allRecapsComplete) {
                $newReconStatus = ExpressEvent::RECON_FULLY_ALLOCATED;
            } else {
                $newReconStatus = ExpressEvent::RECON_PARTIAL;
            }

            // If previously confirmed and recalculation is required
            $newHppStatus = $lockedEvent->hpp_status;
            if ($lockedEvent->hpp_status === ExpressEvent::HPP_CONFIRMED) {
                // If allocations changed on a confirmed event, mark needs_review or recalculate
                $newReconStatus = ExpressEvent::RECON_NEEDS_REVIEW;
            }

            $lockedEvent->update([
                'reconciliation_status' => $newReconStatus,
                'hpp_status' => $newHppStatus,
            ]);

            // Audit log
            ExpressEventAuditLog::record(
                $lockedEvent,
                'ALLOCATED',
                $oldSnapshot,
                [
                    'allocations' => $lockedEvent->allocations()->get()->toArray(),
                    'reconciliation_status' => $newReconStatus,
                ],
                'Alokasi rekap sesi diperbarui'
            );

            return $lockedEvent->fresh(['recaps.allocations', 'recaps.sessionReport']);
        });
    }

    /**
     * Periksa apakah ada ketidaksesuaian alokasi pada event (misal rekap sumber berubah).
     */
    public function checkReconciliationIntegrity(ExpressEvent $event): bool
    {
        $event->loadMissing(['recaps.allocations', 'recaps.sessionReport']);

        foreach ($event->recaps as $recap) {
            $report = $recap->sessionReport;
            if (! $report || $report->status !== SessionReport::STATUS_APPROVED) {
                $event->update(['reconciliation_status' => ExpressEvent::RECON_NEEDS_REVIEW]);
                return false;
            }

            $source = $this->getSourceTotals($report);
            $totalAllocatedAcrossEvents = $this->getAllocatedTotals($report);

            if ($totalAllocatedAcrossEvents['sheets'] > $source['sheets']
                || $totalAllocatedAcrossEvents['revenue'] > $source['revenue']) {
                $event->update(['reconciliation_status' => ExpressEvent::RECON_NEEDS_REVIEW]);
                return false;
            }
        }

        return true;
    }
}
