<?php

namespace App\Filament\Resources\ExpressEventResource\Pages;

use App\Filament\Resources\ExpressEventResource;
use App\Models\ExpressEvent;
use App\Models\ExpressEventAuditLog;
use App\Services\ExpressEventAllocationService;
use App\Services\ExpressEventHppService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateExpressEvent extends CreateRecord
{
    protected static string $resource = ExpressEventResource::class;

    protected function handleRecordCreation(array $data): Model
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

        $data['created_by'] = auth()->id();
        $data['reconciliation_status'] = ExpressEvent::RECON_UNALLOCATED;
        $data['hpp_status'] = ExpressEvent::HPP_PENDING;

        /** @var ExpressEvent $event */
        $event = static::getModel()::create($data);

        ExpressEventAuditLog::record(
            $event,
            'CREATED',
            null,
            $event->toArray(),
            'Event Express dibuat'
        );

        if (! empty($allocationsData)) {
            app(ExpressEventAllocationService::class)->syncAllocations($event, $allocationsData);
        }

        if ($event->hpp_rate_per_sheet !== null) {
            app(ExpressEventHppService::class)->updateRate($event, (int) $event->hpp_rate_per_sheet);
        }

        return $event;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
