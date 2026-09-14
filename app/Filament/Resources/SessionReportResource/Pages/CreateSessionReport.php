<?php

namespace App\Filament\Resources\SessionReportResource\Pages;

use App\Filament\Resources\SessionReportResource;
use App\Models\SessionReport;
use App\Services\PricingService;
use App\Services\SessionWorkflowService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateSessionReport extends CreateRecord
{
    protected static string $resource = SessionReportResource::class;

    protected static ?string $title = 'Buat Rekap Sesi Baru';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = SessionReport::STATUS_DRAFT;
        $data['submitted_by'] = Auth::user()?->karyawan_id;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var SessionReport $record */
        $record = $this->record;

        // Recalculate transaction amounts & report totals from backend source of truth
        app(PricingService::class)->recalculateReport($record);

        // Log draft creation
        if (Auth::user()) {
            app(SessionWorkflowService::class)->logAction(
                $record,
                Auth::user(),
                'DRAFT_CREATED',
                'Draft rekap sesi dibuat.'
            );
        }

        Notification::make()
            ->title('Draft Rekap Sesi Tersimpan')
            ->body('Anda dapat memeriksa kembali atau langsung mengirimkan rekap ke Supervisor.')
            ->success()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
