<?php

namespace App\Filament\Resources\ExpressEventResource\Concerns;

use App\Models\ExpressEvent;
use App\Models\Invoice;
use App\Models\Karyawan;
use App\Services\ExpressEventInvoiceService;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

trait InteractsWithEventInvoices
{
    public bool $isInvoiceModalOpen = false;

    public string $invoiceSearch = '';

    public string $invoiceStatusFilter = '';

    public string $invoiceDateFrom = '';

    public string $invoiceDateTo = '';

    /**
     * @var array<int, int|string>
     */
    public array $modalSelectedInvoiceIds = [];

    public function getCurrentEventRecord(): ?ExpressEvent
    {
        if (property_exists($this, 'record') && $this->record instanceof ExpressEvent) {
            return $this->record;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInvoiceFormContext(): array
    {
        $allocationsData = is_array($this->data['allocations_data'] ?? null)
            ? $this->data['allocations_data']
            : [];
        $linkedInvoiceIds = is_array($this->data['linked_invoice_ids'] ?? null)
            ? $this->data['linked_invoice_ids']
            : [];

        return ExpressEvent::resolveFormContext(
            $allocationsData,
            $linkedInvoiceIds,
            $this->getCurrentEventRecord(),
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getModalSelectableInvoices(): Collection
    {
        if (! $this->isInvoiceModalOpen) {
            return collect();
        }

        $record = $this->getCurrentEventRecord();
        $allocationsData = is_array($this->data['allocations_data'] ?? null)
            ? $this->data['allocations_data']
            : [];
        $currentlyLinkedIds = is_array($this->data['linked_invoice_ids'] ?? null)
            ? $this->data['linked_invoice_ids']
            : [];

        $reportIds = collect($allocationsData)
            ->map(fn ($row): int => is_array($row) ? (int) ($row['session_report_id'] ?? 0) : 0)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $referencedInvoiceIds = $reportIds !== []
            ? \App\Models\SessionReport::query()
                ->whereIn('id', $reportIds)
                ->whereNotNull('invoice_id')
                ->pluck('invoice_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all()
            : [];

        return app(ExpressEventInvoiceService::class)->getSelectableInvoicesForForm(
            currentEventId: $record?->id,
            currentlySelectedIds: $currentlyLinkedIds,
            search: $this->invoiceSearch,
            status: $this->invoiceStatusFilter,
            dateFrom: $this->invoiceDateFrom,
            dateTo: $this->invoiceDateTo,
            referencedInvoiceIds: $referencedInvoiceIds,
        );
    }

    public function openInvoiceModal(): void
    {
        $record = $this->getCurrentEventRecord();
        if ($record && $record->isFinalized()) {
            Notification::make()
                ->title('Event Telah Dikunci')
                ->body('Event berstatus finalized tidak dapat diubah tanpa proses reopen.')
                ->warning()
                ->send();

            return;
        }

        $context = $this->getInvoiceFormContext();
        if ($context['is_sharing_profit']) {
            Notification::make()
                ->title('Tidak Memerlukan Invoice')
                ->body('Event Express Sharing Profit menggunakan omzet langsung dari alokasi rekap sesi.')
                ->info()
                ->send();

            return;
        }

        $this->modalSelectedInvoiceIds = [];
        $this->invoiceSearch = '';
        $this->invoiceStatusFilter = '';
        $this->invoiceDateFrom = '';
        $this->invoiceDateTo = '';
        $this->isInvoiceModalOpen = true;
    }

    public function closeInvoiceModal(): void
    {
        $this->isInvoiceModalOpen = false;
        $this->modalSelectedInvoiceIds = [];
    }

    public function toggleModalInvoice(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            return;
        }

        $record = $this->getCurrentEventRecord();
        $usedElsewhere = \App\Models\ExpressEventInvoice::query()
            ->where('invoice_id', $invoiceId)
            ->whereHas('expressEvent')
            ->when($record !== null, fn ($q) => $q->where('express_event_id', '!=', $record->id))
            ->exists();

        if ($usedElsewhere) {
            Notification::make()
                ->title('Invoice Tidak Dapat Dipilih')
                ->body('Invoice tersebut sudah terhubung ke Event HPP lain.')
                ->warning()
                ->send();

            return;
        }

        $current = collect($this->modalSelectedInvoiceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (in_array($invoiceId, $current, true)) {
            $this->modalSelectedInvoiceIds = array_values(
                array_filter($current, fn (int $id): bool => $id !== $invoiceId)
            );
        } else {
            $current[] = $invoiceId;
            $this->modalSelectedInvoiceIds = array_values(array_unique($current));
        }
    }

    public function confirmAttachModalInvoices(): void
    {
        $newlySelected = collect($this->modalSelectedInvoiceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($newlySelected === []) {
            Notification::make()
                ->title('Belum Ada Invoice Dipilih')
                ->body('Pilih minimal satu invoice untuk dihubungkan ke event ini.')
                ->warning()
                ->send();

            return;
        }

        $record = $this->getCurrentEventRecord();

        // Validasi eksklusivitas terhadap event HPP lain
        $conflictingLink = \App\Models\ExpressEventInvoice::query()
            ->with(['invoice', 'expressEvent'])
            ->whereIn('invoice_id', $newlySelected)
            ->whereHas('expressEvent')
            ->when($record !== null, fn ($q) => $q->where('express_event_id', '!=', $record->id))
            ->first();

        if ($conflictingLink && $conflictingLink->expressEvent) {
            $invNum = $conflictingLink->invoice?->invoice_number ?? "#{$conflictingLink->invoice_id}";
            Notification::make()
                ->title('Invoice Sudah Terhubung ke Event Lain')
                ->body("Invoice {$invNum} sudah digunakan oleh event '{$conflictingLink->expressEvent->event_name}'.")
                ->danger()
                ->send();

            return;
        }

        $existingIds = collect($this->data['linked_invoice_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->all();

        $mergedIds = array_values(array_unique(array_merge($existingIds, $newlySelected)));

        $this->data['linked_invoice_ids'] = $mergedIds;
        $this->data['invoices_modified'] = true;

        // Jika dalam mode Edit (record sudah ada di DB), sinkronkan langsung agar relasi & perhitungan HPP terupdate otomatis
        if ($record !== null) {
            try {
                /** @var Karyawan|null $actor */
                $actor = Auth::user() instanceof Karyawan ? Auth::user() : null;
                app(ExpressEventInvoiceService::class)->syncInvoices(
                    $record,
                    $mergedIds,
                    $actor,
                    forceClearWhenEmpty: true,
                );
                $record->refresh();
            } catch (\Throwable $e) {
                Notification::make()
                    ->title('Gagal Menghubungkan Invoice')
                    ->body($e->getMessage())
                    ->danger()
                    ->send();

                return;
            }
        }

        $this->syncFormAllocationsWithLinkedInvoices();
        $this->closeInvoiceModal();

        Notification::make()
            ->title('Invoice Berhasil Dihubungkan')
            ->body('Total nilai kontrak invoice telah diperbarui ke ringkasan perhitungan HPP.')
            ->success()
            ->send();
    }

    public function detachFormInvoice(int $invoiceId): void
    {
        $record = $this->getCurrentEventRecord();
        if ($record && $record->isFinalized()) {
            Notification::make()
                ->title('Event Telah Dikunci')
                ->body('Event berstatus finalized tidak dapat diubah tanpa proses reopen.')
                ->warning()
                ->send();

            return;
        }

        $remainingIds = collect($this->data['linked_invoice_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0 && $id !== $invoiceId)
            ->unique()
            ->values()
            ->all();

        $this->data['linked_invoice_ids'] = $remainingIds;
        $this->data['invoices_modified'] = true;

        // Jika dalam mode Edit, perbarui relasi DB & perhitungan HPP secara otomatis saat dilepas
        if ($record !== null) {
            try {
                /** @var Karyawan|null $actor */
                $actor = Auth::user() instanceof Karyawan ? Auth::user() : null;
                app(ExpressEventInvoiceService::class)->detachInvoice($record, $invoiceId, $actor);
                $record->refresh();
            } catch (\Throwable $e) {
                Notification::make()
                    ->title('Gagal Melepas Invoice')
                    ->body($e->getMessage())
                    ->danger()
                    ->send();

                return;
            }
        }

        $this->syncFormAllocationsWithLinkedInvoices();

        Notification::make()
            ->title('Invoice Dilepas dari Event')
            ->body('Perhitungan Total Omzet dan Laba Kotor event telah diperbarui secara otomatis.')
            ->info()
            ->send();
    }

    /**
     * Distribusikan ulang nilai kontrak invoice ke baris alokasi rekap sesi pada state form
     * khusus untuk Express Self Run dan Express Sewa, tanpa mengubah data Sharing Profit.
     */
    public function syncFormAllocationsWithLinkedInvoices(): void
    {
        $context = $this->getInvoiceFormContext();

        if ($context['is_sharing_profit']) {
            return;
        }

        $allocations = $this->data['allocations_data'] ?? [];
        if (! is_array($allocations) || $allocations === []) {
            return;
        }

        if (! $context['requires_invoice'] && $context['linked_invoices']->isEmpty()) {
            return;
        }

        $totalContract = (int) $context['invoice_total_revenue'];
        $keys = array_keys($allocations);
        $count = count($keys);

        $totalSheets = 0;
        $totalSessions = 0;
        foreach ($allocations as $row) {
            if (! is_array($row)) {
                continue;
            }
            $totalSheets += max(0, (int) ($row['allocated_sheets'] ?? 0));
            $totalSessions += max(0, (int) ($row['allocated_sessions'] ?? 0));
        }

        $distributed = 0;
        foreach ($keys as $idx => $key) {
            if (! is_array($allocations[$key])) {
                continue;
            }

            if ($idx === $count - 1) {
                $share = max(0, $totalContract - $distributed);
            } elseif ($totalSheets > 0) {
                $weight = max(0, (int) ($allocations[$key]['allocated_sheets'] ?? 0));
                $share = (int) floor(($totalContract * $weight) / $totalSheets);
                $distributed += $share;
            } elseif ($totalSessions > 0) {
                $weight = max(0, (int) ($allocations[$key]['allocated_sessions'] ?? 0));
                $share = (int) floor(($totalContract * $weight) / $totalSessions);
                $distributed += $share;
            } else {
                $share = (int) floor($totalContract / $count);
                $distributed += $share;
            }

            $allocations[$key]['allocated_revenue'] = $share;
        }

        $this->data['allocations_data'] = $allocations;
    }
}
