<?php

namespace App\Filament\Resources\ExpressEventResource\Pages;

use App\Filament\Resources\ExpressEventResource;
use App\Filament\Resources\ExpressEventResource\Concerns\InteractsWithEventInvoices;
use App\Models\ExpressEvent;
use App\Models\Karyawan;
use App\Services\ExpressEventAllocationService;
use App\Services\ExpressEventHppService;
use App\Services\ExpressEventInvoiceService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EditExpressEvent extends EditRecord
{
    use InteractsWithEventInvoices;

    protected static string $resource = ExpressEventResource::class;

    protected function beforeFill(): void
    {
        /** @var ExpressEvent $record */
        $record = $this->record;

        if ($record->isFinalized()) {
            Notification::make()
                ->title('Event Telah Finalized')
                ->body('Event ini telah dikunci (finalized) dan tidak dapat diubah tanpa proses pembukaan kembali (reopen).')
                ->warning()
                ->send();

            $this->redirect($this->getResource()::getUrl('view', ['record' => $record]));
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var ExpressEvent $record */
        $record = $this->record;
        $record->loadMissing(['recaps.allocations', 'recaps.sessionReport', 'invoices']);

        $allocations = [];
        foreach ($record->recaps as $recap) {
            $alloc = $recap->latestAllocation;
            if ($alloc) {
                $allocations[] = [
                    'session_report_id' => $recap->session_report_id,
                    'allocated_sessions' => $alloc->allocated_sessions,
                    'allocated_sheets' => $alloc->allocated_sheets,
                    'allocated_revenue' => $alloc->allocated_revenue,
                    'notes' => $alloc->notes,
                ];
            }
        }

        $data['allocations_data'] = $allocations;
        $data['linked_invoice_ids'] = $record->invoices
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $data['invoices_modified'] = false;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $allocationsData = is_array($data['allocations_data'] ?? null) ? $data['allocations_data'] : [];
        $linkedInvoiceIds = is_array($data['linked_invoice_ids'] ?? null) ? $data['linked_invoice_ids'] : [];
        $invoicesModified = (bool) ($data['invoices_modified'] ?? false);
        unset($data['allocations_data'], $data['linked_invoice_ids'], $data['invoices_modified']);

        /** @var ExpressEvent $record */
        return DB::transaction(function () use ($record, $data, $allocationsData, $linkedInvoiceIds, $invoicesModified): ExpressEvent {
            // Pastikan tanggal mulai dan tanggal selesai selalu sinkron dengan rekap sesi yang dialokasikan
            if ($allocationsData !== []) {
                $reportIds = array_filter(array_column($allocationsData, 'session_report_id'));
                if ($reportIds !== []) {
                    $reports = \App\Models\SessionReport::whereIn('id', $reportIds)->get();
                    $dates = $reports->map(fn ($r) => $r->event_date?->toDateString() ?? $r->report_date?->toDateString())
                        ->filter()
                        ->sort()
                        ->values();
                    if ($dates->isNotEmpty()) {
                        $data['start_date'] = $dates->first();
                        $data['end_date'] = $dates->last();
                    }
                }
            }

            $hadInvoicesBefore = $record->hasLinkedInvoices();

            $record->update($data);

            /** @var Karyawan|null $actor */
            $actor = Auth::user() instanceof Karyawan ? Auth::user() : null;
            app(ExpressEventInvoiceService::class)->syncInvoices(
                $record,
                $linkedInvoiceIds,
                $actor,
                forceClearWhenEmpty: $invoicesModified || $hadInvoicesBefore,
            );

            app(ExpressEventAllocationService::class)->syncAllocations($record, $allocationsData);

            // Jika seluruh invoice sudah dilepas pada event Self Run / Sewa, pastikan alokasi omzet menjadi 0
            $freshRecord = $record->fresh();
            if (($invoicesModified || $hadInvoicesBefore) && $freshRecord && $freshRecord->requiresInvoice() && ! $freshRecord->hasLinkedInvoices()) {
                app(ExpressEventInvoiceService::class)->syncEventInvoiceAllocations($freshRecord);
            }

            if (array_key_exists('hpp_rate_per_sheet', $data) && $data['hpp_rate_per_sheet'] !== null) {
                app(ExpressEventHppService::class)->updateRate($record->fresh(), (int) $data['hpp_rate_per_sheet']);
            }

            return $record->fresh();
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->visible(fn (): bool => ! $this->record->isConfirmed() && ! $this->record->isFinalized()),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
