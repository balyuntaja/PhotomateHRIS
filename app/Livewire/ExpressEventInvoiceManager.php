<?php

namespace App\Livewire;

use App\Filament\Resources\ExpressEventResource;
use App\Models\ExpressEvent;
use App\Models\Karyawan;
use App\Services\ExpressEventConsolidationService;
use App\Services\ExpressEventInvoiceService;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ExpressEventInvoiceManager extends Component
{
    public int $eventId;

    public bool $isModalOpen = false;

    public string $search = '';

    public string $statusFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    /**
     * @var array<int, int|string>
     */
    public array $selectedInvoiceIds = [];

    public function mount(int $eventId): void
    {
        $this->eventId = $eventId;
    }

    public function openModal(): void
    {
        $event = ExpressEvent::findOrFail($this->eventId);

        if (! $this->canManage($event)) {
            Notification::make()
                ->title('Akses ditolak')
                ->body('Anda tidak memiliki izin atau event telah dikunci (finalized).')
                ->danger()
                ->send();

            return;
        }

        $this->selectedInvoiceIds = [];
        $this->search = '';
        $this->statusFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->isModalOpen = true;
    }

    public function closeModal(): void
    {
        $this->isModalOpen = false;
        $this->selectedInvoiceIds = [];
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
    }

    public function toggleInvoice(int $invoiceId): void
    {
        $current = collect($this->selectedInvoiceIds)
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (in_array($invoiceId, $current, true)) {
            $this->selectedInvoiceIds = array_values(
                array_filter($current, fn (int $id): bool => $id !== $invoiceId)
            );
        } else {
            $current[] = $invoiceId;
            $this->selectedInvoiceIds = array_values(array_unique($current));
        }
    }

    public function attachSelectedInvoices(): void
    {
        $event = ExpressEvent::findOrFail($this->eventId);

        if (! $this->canManage($event)) {
            Notification::make()
                ->title('Akses ditolak')
                ->body('Anda tidak memiliki izin untuk menghubungkan invoice.')
                ->danger()
                ->send();

            return;
        }

        /** @var Karyawan|null $user */
        $user = Auth::user();

        try {
            app(ExpressEventInvoiceService::class)->attachInvoices(
                $event,
                $this->selectedInvoiceIds,
                $user instanceof Karyawan ? $user : null
            );

            $this->closeModal();

            Notification::make()
                ->title('Invoice Berhasil Dihubungkan')
                ->body('Nilai kontrak invoice telah disinkronkan ke perhitungan Event HPP.')
                ->success()
                ->send();

            $this->dispatch('express-event-updated');
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal Menghubungkan Invoice')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function detachInvoice(int $invoiceId): void
    {
        $event = ExpressEvent::findOrFail($this->eventId);

        if (! $this->canManage($event)) {
            Notification::make()
                ->title('Akses ditolak')
                ->body('Anda tidak memiliki izin untuk melepas invoice.')
                ->danger()
                ->send();

            return;
        }

        /** @var Karyawan|null $user */
        $user = Auth::user();

        try {
            app(ExpressEventInvoiceService::class)->detachInvoice(
                $event,
                $invoiceId,
                $user instanceof Karyawan ? $user : null
            );

            Notification::make()
                ->title('Invoice Dilepas')
                ->body('Relasi invoice telah dilepas tanpa menghapus data invoice asli.')
                ->info()
                ->send();

            $this->dispatch('express-event-updated');
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal Melepas Invoice')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function mergeIntoInvoiceOwnerEvent(int $targetEventId): void
    {
        $currentEvent = ExpressEvent::findOrFail($this->eventId);

        if (! $this->canManage($currentEvent)) {
            Notification::make()
                ->title('Akses ditolak')
                ->body('Anda tidak memiliki izin untuk menggabungkan event ini.')
                ->danger()
                ->send();

            return;
        }

        $targetEvent = ExpressEvent::find($targetEventId);
        if (! $targetEvent) {
            Notification::make()
                ->title('Event Tidak Ditemukan')
                ->body('Event HPP tujuan penggabungan tidak ditemukan.')
                ->danger()
                ->send();

            return;
        }

        /** @var Karyawan|null $user */
        $user = Auth::user();

        try {
            $merged = app(ExpressEventConsolidationService::class)->mergeEventsManually(
                $targetEvent,
                [$currentEvent->id],
                $user instanceof Karyawan ? $user : null
            );

            $this->closeModal();

            Notification::make()
                ->title('Event Berhasil Digabungkan')
                ->body("Seluruh rekap sesi berhasil disatukan ke '{$merged->event_name}' ({$merged->period_range_label}) dengan 1 invoice tanpa perhitungan ganda.")
                ->success()
                ->send();

            $this->redirect(ExpressEventResource::getUrl('view', ['record' => $merged]));
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal Menggabungkan Event')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function canManage(ExpressEvent $event): bool
    {
        if (! $event->requiresInvoice() || $event->isFinalized()) {
            return false;
        }

        $user = Auth::user();
        if (! $user instanceof Karyawan) {
            return false;
        }

        return $user->isSuperAdmin()
            || $user->can('update', $event)
            || $user->can('allocate', $event)
            || $user->can('manageHpp', $event);
    }

    public function render()
    {
        $event = ExpressEvent::with(['invoices', 'recaps.sessionReport.cabang'])->find($this->eventId);
        $requiresInvoice = $event?->requiresInvoice() ?? false;

        /** @var Collection<int, array<string, mixed>> $selectableInvoices */
        $selectableInvoices = ($requiresInvoice && $this->isModalOpen && $event)
            ? app(ExpressEventInvoiceService::class)->getSelectableInvoices(
                $event,
                $this->search,
                $this->statusFilter,
                $this->dateFrom,
                $this->dateTo
            )
            : collect();

        $selectedIds = collect($this->selectedInvoiceIds)->map(fn ($id): int => (int) $id)->unique()->all();
        $selectedPreviewTotal = (int) $selectableInvoices
            ->whereIn('id', $selectedIds)
            ->sum('contract_amount');

        return view('livewire.express-event-invoice-manager', [
            'event' => $event,
            'requiresInvoice' => $requiresInvoice,
            'canManage' => $event ? $this->canManage($event) : false,
            'linkedInvoices' => $event ? $event->invoices : collect(),
            'selectableInvoices' => $selectableInvoices,
            'selectedIds' => $selectedIds,
            'selectedPreviewTotal' => $selectedPreviewTotal,
        ]);
    }
}
