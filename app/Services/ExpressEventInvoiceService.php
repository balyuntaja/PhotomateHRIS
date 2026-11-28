<?php

namespace App\Services;

use App\Models\ExpressEvent;
use App\Models\ExpressEventAuditLog;
use App\Models\ExpressEventInvoice;
use App\Models\ExpressEventRecapAllocation;
use App\Models\Invoice;
use App\Models\Karyawan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ExpressEventInvoiceService
{
    /**
     * Daftar seluruh invoice dari modul Invoice beserta status pemakaian pada event HPP lain.
     * Tidak membatasi hanya pada status Lunas: invoice Draft, Belum Dibayar, Sebagian Dibayar (DP),
     * maupun Lunas tetap ditampilkan karena memiliki nilai kontrak.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getSelectableInvoices(
        ExpressEvent $event,
        ?string $search = null,
        ?string $status = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): Collection {
        $event->loadMissing(['recaps.sessionReport', 'invoices']);

        $referencedInvoiceIds = $event->recaps
            ->map(fn ($r) => $r->sessionReport?->invoice_id)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $currentlyLinkedIds = $event->invoices
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->getSelectableInvoicesForForm(
            currentEventId: (int) $event->id,
            currentlySelectedIds: $currentlyLinkedIds,
            search: $search,
            status: $status,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            referencedInvoiceIds: $referencedInvoiceIds,
        );
    }

    /**
     * Daftar invoice yang relevan dengan cabang Express untuk modal pemilihan
     * baik pada halaman Create/Edit maupun Detail Event HPP.
     *
     * @param  array<int, int|string>  $currentlySelectedIds
     * @param  array<int, int>  $referencedInvoiceIds
     * @return Collection<int, array<string, mixed>>
     */
    public function getSelectableInvoicesForForm(
        ?int $currentEventId = null,
        array $currentlySelectedIds = [],
        ?string $search = null,
        ?string $status = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        array $referencedInvoiceIds = [],
    ): Collection {
        $normalizedCurrentSelected = collect($currentlySelectedIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $query = Invoice::query()
            ->with(['cabang', 'invoiceItems', 'expressEventLink.expressEvent'])
            ->when(filled($search), function (Builder $q) use ($search): void {
                $term = '%' . trim((string) $search) . '%';
                $q->where(function (Builder $sub) use ($term): void {
                    $sub->where('invoice_number', 'like', $term)
                        ->orWhere('client_name', 'like', $term)
                        ->orWhere('event_name', 'like', $term);
                });
            })
            ->when(filled($status), fn (Builder $q): Builder => $q->where('status', $status))
            ->when(filled($dateFrom), function (Builder $q) use ($dateFrom): void {
                $q->where(function (Builder $sub) use ($dateFrom): void {
                    $sub->whereDate('event_date', '>=', $dateFrom)
                        ->orWhere(function (Builder $fallback) use ($dateFrom): void {
                            $fallback->whereNull('event_date')
                                ->whereDate('invoice_date', '>=', $dateFrom);
                        });
                });
            })
            ->when(filled($dateTo), function (Builder $q) use ($dateTo): void {
                $q->where(function (Builder $sub) use ($dateTo): void {
                    $sub->whereDate('event_date', '<=', $dateTo)
                        ->orWhere(function (Builder $fallback) use ($dateTo): void {
                            $fallback->whereNull('event_date')
                                ->whereDate('invoice_date', '<=', $dateTo);
                        });
                });
            })
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');

        return $query->get()
            ->filter(fn (Invoice $invoice): bool => $invoice->isExpressRelevant())
            ->values()
            ->map(function (Invoice $invoice) use ($currentEventId, $normalizedCurrentSelected, $referencedInvoiceIds): array {
                $link = $invoice->expressEventLink;
                $linkedEvent = $link?->expressEvent;

                $isLinkedToCurrentDb = $currentEventId !== null
                    && $linkedEvent !== null
                    && (int) $linkedEvent->id === (int) $currentEventId;

                $isLinkedToCurrent = $isLinkedToCurrentDb
                    || in_array((int) $invoice->id, $normalizedCurrentSelected, true);

                $isUsedByOther = $linkedEvent !== null
                    && ($currentEventId === null || (int) $linkedEvent->id !== (int) $currentEventId);

                $eventDateLabel = '-';
                if ($invoice->event_date) {
                    $eventDateLabel = $invoice->event_end_date && $invoice->event_end_date->ne($invoice->event_date)
                        ? $invoice->event_date->format('d/m/Y') . ' - ' . $invoice->event_end_date->format('d/m/Y')
                        : $invoice->event_date->format('d/m/Y');
                }

                return [
                    'id' => (int) $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'client_name' => $invoice->client_name,
                    'event_name' => $invoice->event_name,
                    'express_type_label' => $invoice->express_type_label,
                    'event_date_label' => $eventDateLabel,
                    'invoice_date_label' => $invoice->invoice_date?->format('d/m/Y') ?? '-',
                    'contract_amount' => $invoice->contract_amount,
                    'down_payment' => (int) round((float) ($invoice->down_payment ?? 0)),
                    'status' => $invoice->status,
                    'linked_to_current' => $isLinkedToCurrent,
                    'used_by_other' => $isUsedByOther,
                    'other_event_name' => $isUsedByOther ? $linkedEvent->event_name : null,
                    'referenced_by_recap' => in_array((int) $invoice->id, $referencedInvoiceIds, true),
                ];
            });
    }

    /**
     * Hubungkan satu atau beberapa invoice sekaligus ke Event HPP Express (Self Run / Sewa).
     *
     * @param array<int, int|string> $invoiceIds
     */
    public function attachInvoices(ExpressEvent $event, array $invoiceIds, ?Karyawan $actor = null): ExpressEvent
    {
        $normalizedIds = collect($invoiceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($normalizedIds === []) {
            throw new RuntimeException('Pilih minimal satu invoice untuk dihubungkan.');
        }

        return DB::transaction(function () use ($event, $normalizedIds, $actor): ExpressEvent {
            $lockedEvent = ExpressEvent::where('id', $event->id)->lockForUpdate()->firstOrFail();

            if ($lockedEvent->isFinalized()) {
                throw new RuntimeException('Event telah berstatus finalized dan tidak dapat diubah tanpa proses pembukaan kembali (reopen).');
            }

            if (! $lockedEvent->requiresInvoice()) {
                throw new RuntimeException('Fitur Hubungkan Invoice hanya berlaku untuk Express Self Run dan Express Sewa.');
            }

            $invoices = Invoice::query()
                ->whereIn('id', $normalizedIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($invoices->count() !== count($normalizedIds)) {
                throw new RuntimeException('Terdapat invoice yang tidak ditemukan di database.');
            }

            $existingLinks = ExpressEventInvoice::query()
                ->whereIn('invoice_id', $normalizedIds)
                ->with('expressEvent')
                ->lockForUpdate()
                ->get();

            foreach ($existingLinks as $link) {
                // Bersihkan relasi yatim jika event sebelumnya sudah di-soft-delete
                if ($link->expressEvent === null) {
                    $link->delete();
                    continue;
                }

                if ((int) $link->express_event_id !== (int) $lockedEvent->id) {
                    $invNumber = $invoices[$link->invoice_id]?->invoice_number ?? "#{$link->invoice_id}";
                    throw new RuntimeException(
                        "Invoice {$invNumber} sudah digunakan pada event HPP lain ({$link->expressEvent->event_name}) dan tidak dapat dipilih kembali."
                    );
                }
            }

            $beforeInvoiceNumbers = $lockedEvent->invoices()->pluck('invoice_number')->all();
            $beforeRevenue = $lockedEvent->total_allocated_revenue;

            foreach ($normalizedIds as $invId) {
                ExpressEventInvoice::firstOrCreate(
                    [
                        'express_event_id' => $lockedEvent->id,
                        'invoice_id' => $invId,
                    ],
                    [
                        'linked_by' => $actor?->karyawan_id ?? auth()->id(),
                    ]
                );
            }

            $this->syncEventInvoiceAllocations($lockedEvent);
            $this->refreshHppAfterInvoiceChange($lockedEvent);

            $lockedEvent->unsetRelation('invoices');
            $afterInvoices = $lockedEvent->invoices()->get();

            ExpressEventAuditLog::record(
                $lockedEvent,
                'INVOICE_LINKED',
                [
                    'invoices' => $beforeInvoiceNumbers,
                    'total_revenue' => $beforeRevenue,
                ],
                [
                    'invoices' => $afterInvoices->pluck('invoice_number')->all(),
                    'total_invoice_amount' => $lockedEvent->total_invoice_amount,
                ],
                'Invoice dihubungkan: ' . $afterInvoices->pluck('invoice_number')->implode(', ')
            );

            return $lockedEvent->fresh(['invoices', 'recaps.allocations', 'recaps.sessionReport.cabang']);
        });
    }

    /**
     * Sinkronkan himpunan penuh invoice yang terhubung pada Event HPP dari form Create / Edit Event.
     *
     * @param  array<int, int|string>  $invoiceIds
     */
    public function syncInvoices(
        ExpressEvent $event,
        array $invoiceIds,
        ?Karyawan $actor = null,
        bool $forceClearWhenEmpty = false,
    ): ExpressEvent {
        if (! Schema::hasTable('express_event_invoices')) {
            return $event;
        }

        $normalizedIds = collect($invoiceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        return DB::transaction(function () use ($event, $normalizedIds, $actor, $forceClearWhenEmpty): ExpressEvent {
            $lockedEvent = ExpressEvent::where('id', $event->id)->lockForUpdate()->firstOrFail();

            if ($lockedEvent->isFinalized()) {
                throw new RuntimeException('Event telah berstatus finalized dan tidak dapat diubah tanpa proses pembukaan kembali (reopen).');
            }

            // Express Sharing Profit selalu memakai omzet dari rekap sesi (tidak menghubungkan invoice kontrak)
            if ($lockedEvent->isSharingProfit()) {
                ExpressEventInvoice::where('express_event_id', $lockedEvent->id)->delete();

                return $lockedEvent->fresh(['invoices', 'recaps.allocations', 'recaps.sessionReport.cabang']);
            }

            $existingInvoiceIds = ExpressEventInvoice::query()
                ->where('express_event_id', $lockedEvent->id)
                ->lockForUpdate()
                ->pluck('invoice_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($normalizedIds === [] && $existingInvoiceIds === [] && ! $forceClearWhenEmpty) {
                return $lockedEvent->fresh(['invoices', 'recaps.allocations', 'recaps.sessionReport.cabang']);
            }

            if ($normalizedIds !== []) {
                $invoices = Invoice::query()
                    ->with('cabang')
                    ->whereIn('id', $normalizedIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($invoices->count() !== count($normalizedIds)) {
                    throw new RuntimeException('Terdapat invoice yang tidak ditemukan di database.');
                }

                foreach ($invoices as $inv) {
                    if (! $inv->isExpressRelevant()) {
                        throw new RuntimeException("Invoice {$inv->invoice_number} bukan merupakan invoice cabang Express.");
                    }
                }

                $existingLinks = ExpressEventInvoice::query()
                    ->whereIn('invoice_id', $normalizedIds)
                    ->with('expressEvent')
                    ->lockForUpdate()
                    ->get();

                foreach ($existingLinks as $link) {
                    if ($link->expressEvent === null) {
                        $link->delete();
                        continue;
                    }

                    if ((int) $link->express_event_id !== (int) $lockedEvent->id) {
                        $invNumber = $invoices[$link->invoice_id]?->invoice_number ?? "#{$link->invoice_id}";
                        throw new RuntimeException(
                            "Invoice {$invNumber} sudah digunakan pada event HPP lain ({$link->expressEvent->event_name}) dan tidak dapat dipilih kembali."
                        );
                    }
                }

                // Jika operational_type belum terisi pada event tanpa rekap, turunkan dari invoice pertama
                if (blank($lockedEvent->operational_type)) {
                    foreach ($invoices as $inv) {
                        $typeLabel = strtolower($inv->express_type_label);
                        if (str_contains($typeLabel, 'self run')) {
                            $lockedEvent->update(['operational_type' => ExpressEvent::TYPE_SELF_RUN]);
                            break;
                        }
                        if (str_contains($typeLabel, 'sewa')) {
                            $lockedEvent->update(['operational_type' => ExpressEvent::TYPE_SEWA]);
                            break;
                        }
                    }
                }
            }

            $beforeInvoiceNumbers = $lockedEvent->invoices()->pluck('invoice_number')->all();
            $beforeRevenue = $lockedEvent->total_allocated_revenue;

            if ($normalizedIds === []) {
                ExpressEventInvoice::where('express_event_id', $lockedEvent->id)->delete();
            } else {
                ExpressEventInvoice::where('express_event_id', $lockedEvent->id)
                    ->whereNotIn('invoice_id', $normalizedIds)
                    ->delete();

                foreach ($normalizedIds as $invId) {
                    ExpressEventInvoice::firstOrCreate(
                        [
                            'express_event_id' => $lockedEvent->id,
                            'invoice_id' => $invId,
                        ],
                        [
                            'linked_by' => $actor?->karyawan_id ?? auth()->id(),
                        ]
                    );
                }
            }

            $this->syncEventInvoiceAllocations($lockedEvent);
            $this->refreshHppAfterInvoiceChange($lockedEvent);

            $lockedEvent->unsetRelation('invoices');
            $afterInvoices = $lockedEvent->invoices()->get();
            $afterInvoiceNumbers = $afterInvoices->pluck('invoice_number')->all();

            if ($beforeInvoiceNumbers !== $afterInvoiceNumbers) {
                $action = $afterInvoiceNumbers === [] ? 'INVOICE_DETACHED' : 'INVOICE_LINKED';
                $summary = $afterInvoiceNumbers === []
                    ? 'Seluruh invoice dilepas dari event.'
                    : 'Alokasi invoice event disinkronkan: ' . implode(', ', $afterInvoiceNumbers);

                ExpressEventAuditLog::record(
                    $lockedEvent,
                    $action,
                    [
                        'invoices' => $beforeInvoiceNumbers,
                        'previous_revenue' => $beforeRevenue,
                    ],
                    [
                        'invoices' => $afterInvoiceNumbers,
                        'total_invoice_amount' => $lockedEvent->total_invoice_amount,
                    ],
                    $summary
                );
            }

            return $lockedEvent->fresh(['invoices', 'recaps.allocations', 'recaps.sessionReport.cabang']);
        });
    }

    /**
     * Lepas satu invoice dari Event HPP tanpa menghapus data invoice asli di modul Invoice.
     */
    public function detachInvoice(ExpressEvent $event, int $invoiceId, ?Karyawan $actor = null): ExpressEvent
    {
        return DB::transaction(function () use ($event, $invoiceId): ExpressEvent {
            $lockedEvent = ExpressEvent::where('id', $event->id)->lockForUpdate()->firstOrFail();

            if ($lockedEvent->isFinalized()) {
                throw new RuntimeException('Event telah berstatus finalized dan tidak dapat diubah tanpa proses pembukaan kembali (reopen).');
            }

            $invoice = Invoice::find($invoiceId);
            $link = ExpressEventInvoice::query()
                ->where('express_event_id', $lockedEvent->id)
                ->where('invoice_id', $invoiceId)
                ->lockForUpdate()
                ->first();

            if (! $link) {
                return $lockedEvent->fresh(['invoices', 'recaps.allocations', 'recaps.sessionReport.cabang']);
            }

            $beforeRevenue = $lockedEvent->total_allocated_revenue;

            $link->delete();

            $this->syncEventInvoiceAllocations($lockedEvent);
            $this->refreshHppAfterInvoiceChange($lockedEvent);

            $lockedEvent->unsetRelation('invoices');

            ExpressEventAuditLog::record(
                $lockedEvent,
                'INVOICE_DETACHED',
                [
                    'detached_invoice' => $invoice?->invoice_number ?? "#{$invoiceId}",
                    'previous_revenue' => $beforeRevenue,
                ],
                [
                    'remaining_invoices' => $lockedEvent->invoices()->pluck('invoice_number')->all(),
                    'total_invoice_amount' => $lockedEvent->total_invoice_amount,
                ],
                'Invoice dilepas dari event: ' . ($invoice?->invoice_number ?? "#{$invoiceId}")
            );

            return $lockedEvent->fresh(['invoices', 'recaps.allocations', 'recaps.sessionReport.cabang']);
        });
    }

    /**
     * Sinkronkan nilai total kontrak invoice terhubung ke alokasi rekap sesi pada Event Self Run / Sewa.
     * Menjamin satu invoice hanya dihitung satu kali dan nilai menggunakan total kontrak penuh (bukan DP).
     */
    public function syncEventInvoiceAllocations(ExpressEvent $event): void
    {
        if (! Schema::hasTable('express_event_invoices')) {
            return;
        }

        $event->unsetRelation('invoices');
        $event->unsetRelation('recaps');
        $event->load(['invoices', 'recaps.allocations', 'recaps.sessionReport.cabang']);

        if (! $event->requiresInvoice()) {
            return;
        }

        $totalContractAmount = (int) $event->invoices
            ->unique('id')
            ->sum(fn (Invoice $inv): int => $inv->contract_amount);

        $activeRecaps = $event->recaps->where('status', 'active')->values();

        if ($activeRecaps->isEmpty()) {
            return;
        }

        $totalSheets = (int) $activeRecaps->sum(fn ($r): int => (int) ($r->latestAllocation?->allocated_sheets ?? 0));
        $totalSessions = (int) $activeRecaps->sum(fn ($r): int => (int) ($r->latestAllocation?->allocated_sessions ?? 0));
        $recapCount = $activeRecaps->count();

        $distributed = 0;

        foreach ($activeRecaps as $index => $recap) {
            $allocation = $recap->latestAllocation;
            if (! $allocation) {
                $allocation = ExpressEventRecapAllocation::create([
                    'express_event_recap_id' => $recap->id,
                    'allocated_sessions' => $recap->source_sessions,
                    'allocated_sheets' => $recap->source_sheets,
                    'allocated_revenue' => 0,
                ]);
            }

            if ($index === $recapCount - 1) {
                $share = max(0, $totalContractAmount - $distributed);
            } elseif ($totalSheets > 0) {
                $weight = (int) $allocation->allocated_sheets;
                $share = (int) floor(($totalContractAmount * $weight) / $totalSheets);
                $distributed += $share;
            } elseif ($totalSessions > 0) {
                $weight = (int) $allocation->allocated_sessions;
                $share = (int) floor(($totalContractAmount * $weight) / $totalSessions);
                $distributed += $share;
            } else {
                $share = (int) floor($totalContractAmount / $recapCount);
                $distributed += $share;
            }

            if ((int) $allocation->allocated_revenue !== $share) {
                $allocation->update(['allocated_revenue' => $share]);
            }
        }

        $updates = [];
        if ($event->isConfirmed()) {
            $latestSnapshotRevenue = (int) ($event->latestHpp?->total_revenue ?? 0);
            if ($totalContractAmount === 0 || $latestSnapshotRevenue !== $totalContractAmount) {
                $updates['reconciliation_status'] = ExpressEvent::RECON_NEEDS_REVIEW;
            }
        } else {
            $updates['reconciliation_status'] = ExpressEvent::RECON_FULLY_ALLOCATED;
        }

        if ($updates !== []) {
            $event->update($updates);
        }
    }

    /**
     * Perbarui status & snapshot perhitungan HPP secara otomatis ketika invoice dihubungkan atau dilepas.
     */
    protected function refreshHppAfterInvoiceChange(ExpressEvent $event): void
    {
        $event->unsetRelation('invoices');
        $event->unsetRelation('recaps');
        $event->unsetRelation('allocations');
        $event->unsetRelation('latestHpp');
        $event->load(['invoices', 'recaps.allocations', 'recaps.sessionReport.cabang', 'latestHpp']);

        if (! $event->requiresInvoice() || $event->isPosted()) {
            return;
        }

        // Jika seluruh invoice dilepas dari event yang sebelumnya terkonfirmasi, kembalikan ke preview/pending
        if (! $event->hasLinkedInvoices()) {
            if ($event->hpp_status === ExpressEvent::HPP_CONFIRMED) {
                $event->update([
                    'hpp_status' => $event->hpp_rate_per_sheet !== null
                        ? ExpressEvent::HPP_CALCULATED
                        : ExpressEvent::HPP_PENDING,
                    'confirmed_at' => null,
                    'confirmed_by' => null,
                ]);
            }

            if ($event->latestHpp && $event->hpp_rate_per_sheet !== null) {
                $preview = app(ExpressEventHppService::class)->calculatePreview($event);
                $event->latestHpp->update([
                    'total_revenue' => $preview['total_revenue'],
                    'total_hpp' => $preview['total_hpp'],
                    'gross_profit' => $preview['gross_profit'],
                    'calculated_at' => now(),
                ]);
            }

            return;
        }

        // Jika masih memiliki snapshot HPP (belum diposting jurnal), perbarui angkanya secara otomatis
        if ($event->latestHpp && $event->hpp_rate_per_sheet !== null) {
            $preview = app(ExpressEventHppService::class)->calculatePreview($event);
            $event->latestHpp->update([
                'total_sessions' => $preview['total_sessions'],
                'total_sheets' => $preview['total_sheets'],
                'total_revenue' => $preview['total_revenue'],
                'total_hpp' => $preview['total_hpp'],
                'gross_profit' => $preview['gross_profit'],
                'calculated_at' => now(),
            ]);
        }
    }

    /**
     * Dipanggil saat data Invoice sumber berubah atau dihapus agar total harga pada Event HPP
     * yang terhubung ikut diperbarui secara konsisten dengan audit trail.
     */
    public function syncEventsForInvoice(Invoice $invoice): void
    {
        if (! Schema::hasTable('express_event_invoices')) {
            return;
        }

        $eventIds = ExpressEventInvoice::query()
            ->where('invoice_id', $invoice->id)
            ->pluck('express_event_id')
            ->unique()
            ->all();

        foreach ($eventIds as $eventId) {
            DB::transaction(function () use ($eventId, $invoice): void {
                $lockedEvent = ExpressEvent::where('id', $eventId)->lockForUpdate()->first();
                if (! $lockedEvent) {
                    return;
                }

                $oldRevenue = $lockedEvent->total_allocated_revenue;
                $this->syncEventInvoiceAllocations($lockedEvent);
                $lockedEvent->unsetRelation('allocations');
                $lockedEvent->unsetRelation('invoices');
                $newRevenue = $lockedEvent->total_invoice_amount;

                if ($oldRevenue !== $newRevenue) {
                    ExpressEventAuditLog::record(
                        $lockedEvent,
                        'INVOICE_SOURCE_UPDATED',
                        [
                            'invoice_number' => $invoice->invoice_number,
                            'total_revenue' => $oldRevenue,
                        ],
                        [
                            'invoice_number' => $invoice->invoice_number,
                            'total_revenue' => $newRevenue,
                        ],
                        "Nominal kontrak invoice {$invoice->invoice_number} diperbarui pada sumber invoice."
                    );
                }
            });
        }
    }

    /**
     * Dipanggil tepat sebelum sebuah Invoice dihapus dari database agar relasi event HPP
     * dilepas dengan bersih dan tercatat di audit log.
     */
    public function handleInvoiceDeleting(Invoice $invoice): void
    {
        if (! Schema::hasTable('express_event_invoices')) {
            return;
        }

        $links = ExpressEventInvoice::query()
            ->where('invoice_id', $invoice->id)
            ->get();

        foreach ($links as $link) {
            DB::transaction(function () use ($link, $invoice): void {
                $lockedEvent = ExpressEvent::where('id', $link->express_event_id)->lockForUpdate()->first();
                $oldRevenue = $lockedEvent?->total_allocated_revenue ?? 0;

                $link->delete();

                if ($lockedEvent) {
                    $this->syncEventInvoiceAllocations($lockedEvent);

                    ExpressEventAuditLog::record(
                        $lockedEvent,
                        'INVOICE_SOURCE_DELETED',
                        [
                            'invoice_number' => $invoice->invoice_number,
                            'previous_revenue' => $oldRevenue,
                        ],
                        [
                            'total_invoice_amount' => $lockedEvent->total_invoice_amount,
                        ],
                        "Invoice {$invoice->invoice_number} dihapus dari modul Invoice; alokasi event disesuaikan."
                    );
                }
            });
        }
    }
}
