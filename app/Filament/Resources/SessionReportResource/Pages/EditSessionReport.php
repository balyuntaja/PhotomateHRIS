<?php

namespace App\Filament\Resources\SessionReportResource\Pages;

use App\Filament\Resources\SessionReportResource;
use App\Models\Invoice;
use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Services\PricingService;
use App\Services\RevisionNotificationService;
use App\Services\SessionWorkflowService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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

            return;
        }

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
        if (app(SessionWorkflowService::class)->isInputClosedForReport(Auth::user(), $this->getRecord())) {
            Notification::make()
                ->title('Rekap Sesi Sudah Ditutup')
                ->body($this->getRecord()->inputClosedMessage())
                ->danger()
                ->send();

            return;
        }

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $cabangId = $data['cabang_id'] ?? null;
        if ($cabangId) {
            $cabang = \App\Models\Cabang::find($cabangId);
            $namaCabang = strtolower($cabang?->nama_cabang ?? '');
            if (str_contains($namaCabang, 'express sharing profit') || str_contains($namaCabang, 'sharing profit')) {
                $data['invoice_id'] = null;
                if (blank($data['event_note'] ?? null)) {
                    throw ValidationException::withMessages([
                        'data.event_note' => 'Nama Event wajib diisi untuk cabang Express Sharing Profit.',
                    ]);
                }
            } elseif (str_contains($namaCabang, 'express self run') || str_contains($namaCabang, 'self run')) {
                if (empty($data['invoice_id'])) {
                    throw ValidationException::withMessages([
                        'data.invoice_id' => 'Pilih Event dari Invoice Express Self Run wajib dipilih.',
                    ]);
                }
                $inv = Invoice::find($data['invoice_id']);
                if (! $inv || ! $inv->isExpressSelfRun()) {
                    throw ValidationException::withMessages([
                        'data.invoice_id' => 'Invoice yang dipilih bukan merupakan invoice Express Self Run yang valid.',
                    ]);
                }
                $data['event_note'] = $inv->event_name;
                if (empty($data['event_date']) && $inv->event_date) {
                    $data['event_date'] = $inv->event_date->toDateString();
                }
                $matchingEvent = \App\Models\Event::where('name', $inv->event_name)->first();
                if ($matchingEvent) {
                    $data['event_id'] = $matchingEvent->id;
                }
            } elseif (str_contains($namaCabang, 'express sewa')) {
                if (empty($data['invoice_id'])) {
                    throw ValidationException::withMessages([
                        'data.invoice_id' => 'Pilih Event dari Invoice Express Sewa wajib dipilih.',
                    ]);
                }
                $inv = Invoice::find($data['invoice_id']);
                if (! $inv || ! $inv->isExpressSewa()) {
                    throw ValidationException::withMessages([
                        'data.invoice_id' => 'Invoice yang dipilih bukan merupakan invoice Express Sewa yang valid.',
                    ]);
                }
                $data['event_note'] = $inv->event_name;
                if (empty($data['event_date']) && $inv->event_date) {
                    $data['event_date'] = $inv->event_date->toDateString();
                }
                $matchingEvent = \App\Models\Event::where('name', $inv->event_name)->first();
                if ($matchingEvent) {
                    $data['event_id'] = $matchingEvent->id;
                }
            }
        }

        return $data;
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
            Actions\ViewAction::make()
                ->label('Lihat Detail')
                ->color('gray')
                ->icon('heroicon-o-eye'),

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
                ->visible(fn () => in_array($this->record->status, [SessionReport::STATUS_DRAFT, SessionReport::STATUS_REVISION])),

            Actions\DeleteAction::make()
                ->visible(fn () => ($this->record->status === SessionReport::STATUS_DRAFT || (bool) Auth::user()?->isSuperAdmin())
                    && !app(SessionWorkflowService::class)->isInputClosed(Auth::user(), $this->record->report_date)),
        ];
    }
}
