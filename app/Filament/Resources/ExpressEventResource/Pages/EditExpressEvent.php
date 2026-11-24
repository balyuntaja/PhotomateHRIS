<?php

namespace App\Filament\Resources\ExpressEventResource\Pages;

use App\Filament\Resources\ExpressEventResource;
use App\Models\ExpressEvent;
use App\Services\ExpressEventAllocationService;
use App\Services\ExpressEventHppService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditExpressEvent extends EditRecord
{
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
        $record->loadMissing(['recaps.allocations', 'recaps.sessionReport']);

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

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $allocationsData = $data['allocations_data'] ?? [];
        unset($data['allocations_data']);

        // Pastikan tanggal mulai dan tanggal selesai selalu sinkron dengan rekap sesi yang dialokasikan
        if (! empty($allocationsData)) {
            $reportIds = array_filter(array_column($allocationsData, 'session_report_id'));
            if (! empty($reportIds)) {
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

        /** @var ExpressEvent $record */
        $record->update($data);

        app(ExpressEventAllocationService::class)->syncAllocations($record, $allocationsData);

        if (array_key_exists('hpp_rate_per_sheet', $data) && $data['hpp_rate_per_sheet'] !== null) {
            app(ExpressEventHppService::class)->updateRate($record, (int) $data['hpp_rate_per_sheet']);
        }

        return $record;
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
