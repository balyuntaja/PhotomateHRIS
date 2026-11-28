<?php

namespace App\Filament\Resources\ExpressEventResource\Pages;

use App\Filament\Resources\ExpressEventResource;
use App\Filament\Resources\ExpressEventResource\Concerns\InteractsWithEventInvoices;
use App\Models\ExpressEvent;
use App\Models\ExpressEventAuditLog;
use App\Models\Karyawan;
use App\Services\ExpressEventAllocationService;
use App\Services\ExpressEventHppService;
use App\Services\ExpressEventInvoiceService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreateExpressEvent extends CreateRecord
{
    use InteractsWithEventInvoices;

    protected static string $resource = ExpressEventResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $allocationsData = is_array($data['allocations_data'] ?? null) ? $data['allocations_data'] : [];
        $linkedInvoiceIds = is_array($data['linked_invoice_ids'] ?? null) ? $data['linked_invoice_ids'] : [];
        unset($data['allocations_data'], $data['linked_invoice_ids'], $data['invoices_modified']);

        return DB::transaction(function () use ($data, $allocationsData, $linkedInvoiceIds): ExpressEvent {
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

            $formContext = ExpressEvent::resolveFormContext($allocationsData, $linkedInvoiceIds);
            if ($formContext['operational_type'] !== null && empty($data['operational_type'])) {
                $data['operational_type'] = $formContext['operational_type'];
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

            if ($linkedInvoiceIds !== []) {
                /** @var Karyawan|null $actor */
                $actor = Auth::user() instanceof Karyawan ? Auth::user() : null;
                app(ExpressEventInvoiceService::class)->syncInvoices($event, $linkedInvoiceIds, $actor);
            }

            if ($allocationsData !== []) {
                app(ExpressEventAllocationService::class)->syncAllocations($event, $allocationsData);
            }

            if ($event->hpp_rate_per_sheet !== null) {
                app(ExpressEventHppService::class)->updateRate($event, (int) $event->hpp_rate_per_sheet);
            }

            return $event->fresh();
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
