<?php

namespace App\Filament\Resources\SessionReportResource\Pages;

use App\Filament\Resources\SessionReportResource;
use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Services\RevisionNotificationService;
use App\Services\SessionWorkflowService;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

class ListSessionReports extends ListRecords
{
    protected static string $resource = SessionReportResource::class;

    #[On('session-report-updated')]
    public function refreshOnSessionReportUpdated(): void {}

    public function mountTableAction(string $name, ?string $record = null, array $arguments = []): mixed
    {
        $result = parent::mountTableAction($name, $record, $arguments);

        if ($name === 'review_detail' && filled($record)) {
            $user = Auth::user();
            $sessionReport = $this->getMountedTableActionRecord();

            if (
                $user instanceof Karyawan
                && $sessionReport instanceof SessionReport
                && $sessionReport->isRevision()
                && ! app(SessionWorkflowService::class)->isSupervisorOrAdmin($user)
            ) {
                $marked = app(RevisionNotificationService::class)->markReportNotificationsAsRead($user, $sessionReport);

                if ($marked > 0) {
                    $this->dispatch('databaseNotificationsSent');
                    $this->dispatch('session-report-revision-updated');
                }
            }
        }

        return $result;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Buat Rekap')
                ->icon('heroicon-o-plus'),
        ];
    }
}
