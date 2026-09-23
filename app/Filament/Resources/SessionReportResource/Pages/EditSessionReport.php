<?php

namespace App\Filament\Resources\SessionReportResource\Pages;

use App\Filament\Resources\SessionReportResource;
use App\Models\SessionReport;
use App\Services\PricingService;
use App\Services\SessionWorkflowService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditSessionReport extends EditRecord
{
    protected static string $resource = SessionReportResource::class;

    protected static ?string $title = 'Edit Rekap Sesi';

    public function mount(int | string $record): void
    {
        parent::mount($record);

        // Strict Immutability Guard
        if ($this->record->isApproved()) {
            Notification::make()
                ->title('Rekap Sesi Sudah Disetujui')
                ->body('Data yang sudah berstatus APPROVED bersifat tetap (immutable) dan tidak dapat diubah.')
                ->warning()
                ->send();

            $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
        }
    }

    protected function authorizeAccess(): void
    {
        if (static::getResource()::canEdit($this->getRecord())) {
            parent::authorizeAccess();

            return;
        }

        // Rekap yang sudah melewati batas waktu input ditolak dengan pesan, bukan halaman 403
        if (app(SessionWorkflowService::class)->isInputClosed(Auth::user(), $this->getRecord()->report_date)) {
            Notification::make()
                ->title('Rekap Sesi Sudah Ditutup')
                ->body($this->getRecord()->inputClosedMessage())
                ->warning()
                ->send();

            $this->redirect(static::getResource()::getUrl('view', ['record' => $this->getRecord()]));

            return;
        }

        parent::authorizeAccess();
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        if (app(SessionWorkflowService::class)->isInputClosed(Auth::user(), $this->getRecord()->report_date)) {
            Notification::make()
                ->title('Rekap Sesi Sudah Ditutup')
                ->body($this->getRecord()->inputClosedMessage())
                ->danger()
                ->send();

            return;
        }

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    protected function afterSave(): void
    {
        /** @var SessionReport $record */
        $record = $this->record;

        // Recalculate transaction amounts & report totals from backend source of truth
        app(PricingService::class)->recalculateReport($record);

        // Log update
        if (Auth::user()) {
            app(SessionWorkflowService::class)->logAction(
                $record,
                Auth::user(),
                'DRAFT_UPDATED',
                'Perubahan pada draft rekap sesi disimpan.'
            );
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('submit_now')
                ->label(fn () => $this->record->status === SessionReport::STATUS_REVISION ? 'Submit Ulang ke Supervisor' : 'Submit Rekap ke Supervisor')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(fn () => $this->record->status === SessionReport::STATUS_REVISION ? 'Submit Ulang Rekap Sesi?' : 'Submit Rekap Sesi?')
                ->modalDescription('Rekap sesi akan dikirimkan ke Supervisor untuk proses verifikasi & approval. Pastikan nominal dan jumlah sesi sudah benar.')
                ->modalSubmitActionLabel('Ya, Submit')
                ->action(function () {
                    try {
                        // Save form changes first
                        $this->save();

                        app(SessionWorkflowService::class)->submit($this->record, Auth::user());

                        Notification::make()
                            ->title('Rekap sesi berhasil dikirim dan menunggu approval Supervisor.')
                            ->success()
                            ->send();

                        $this->redirect($this->getResource()::getUrl('index'));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Gagal mengirim rekap')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                })
                ->visible(fn () => in_array($this->record->status, [SessionReport::STATUS_DRAFT, SessionReport::STATUS_REVISION])),

            Actions\DeleteAction::make()
                ->visible(fn () => ($this->record->status === SessionReport::STATUS_DRAFT || (bool) Auth::user()?->isSuperAdmin())
                    && !app(SessionWorkflowService::class)->isInputClosed(Auth::user(), $this->record->report_date)),
        ];
    }
}
