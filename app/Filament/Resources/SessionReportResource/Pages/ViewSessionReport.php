<?php

namespace App\Filament\Resources\SessionReportResource\Pages;

use App\Filament\Resources\SessionReportResource;
use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Services\RevisionNotificationService;
use App\Services\SessionWorkflowService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewSessionReport extends ViewRecord
{
    protected static string $resource = SessionReportResource::class;

    protected static ?string $title = 'Detail Rekap Sesi';

    public function mount(int | string $record): void
    {
        parent::mount($record);

        $user = Auth::user();
        if (
            $user instanceof Karyawan
            && $this->record instanceof SessionReport
            && ! app(SessionWorkflowService::class)->isSupervisorOrAdmin($user)
        ) {
            $marked = app(RevisionNotificationService::class)->markReportNotificationsAsRead($user, $this->record);

            if ($marked > 0) {
                $this->dispatch('databaseNotificationsSent');
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->label(fn () => $this->record->isRevision() ? 'Edit & Perbaiki' : 'Edit')
                ->color(fn () => $this->record->isRevision() ? 'primary' : 'gray')
                ->visible(fn () => in_array($this->record->status, [SessionReport::STATUS_DRAFT, SessionReport::STATUS_REVISION])
                    && ! app(SessionWorkflowService::class)->isInputClosedForReport(Auth::user(), $this->record)),

            Actions\Action::make('submit_rekap')
                ->label(fn () => $this->record->isRevision() ? 'Submit Ulang ke Supervisor' : 'Submit ke Supervisor')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(fn () => $this->record->isRevision() ? 'Submit Ulang Rekap Sesi?' : 'Submit Rekap Sesi?')
                ->modalDescription('Rekap sesi akan dikirimkan ke Supervisor untuk diperiksa. Pastikan semua data transaksi sudah benar.')
                ->modalSubmitActionLabel('Ya, Submit')
                ->action(function (): void {
                    try {
                        app(SessionWorkflowService::class)->submit($this->record, Auth::user());

                        $this->dispatch('databaseNotificationsSent');

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
                ->visible(fn () => in_array($this->record->status, [SessionReport::STATUS_DRAFT, SessionReport::STATUS_REVISION])
                    && ! app(SessionWorkflowService::class)->isInputClosedForReport(Auth::user(), $this->record)),

            Actions\DeleteAction::make()
                ->visible(fn () => (bool) Auth::user()?->isSuperAdmin()),
        ];
    }

    public function infolist(\Filament\Infolists\Infolist $infolist): \Filament\Infolists\Infolist
    {
        return $infolist
            ->schema([
                \Filament\Infolists\Components\ViewEntry::make('review_content')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->view('filament.resources.session-reports.review-modal', [
                        'record' => $this->record,
                    ]),
            ]);
    }
}
