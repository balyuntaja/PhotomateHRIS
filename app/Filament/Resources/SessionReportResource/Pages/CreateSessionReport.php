<?php

namespace App\Filament\Resources\SessionReportResource\Pages;

use App\Filament\Resources\SessionReportResource;
use App\Models\SessionReport;
use App\Services\PricingService;
use App\Services\SessionWorkflowService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateSessionReport extends CreateRecord
{
    protected static string $resource = SessionReportResource::class;

    protected static ?string $title = 'Buat Rekap Sesi Baru';

    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): \Filament\Actions\Action
    {
        return parent::getCreateFormAction()
            ->label('Kirim ke Supervisor')
            ->icon('heroicon-o-paper-airplane');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();
        $reportDate = $data['report_date'] ?? null;

        if ($reportDate && app(SessionWorkflowService::class)->isInputClosed($user, $reportDate)) {
            throw ValidationException::withMessages([
                'data.report_date' => SessionReport::inputClosedMessageFor($reportDate),
            ]);
        }

        $data['status'] = SessionReport::STATUS_DRAFT;
        $data['submitted_by'] = Auth::user()?->karyawan_id;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var SessionReport $record */
        $record = $this->record;

        $user = Auth::user();
        if ($user) {
            app(SessionWorkflowService::class)->submit($record, $user);
        } else {
            app(PricingService::class)->recalculateReport($record);
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title('Rekap Sesi Berhasil Dikirim')
            ->body('Rekap sesi telah diserahkan dan menunggu persetujuan (approval) Supervisor.')
            ->success();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
