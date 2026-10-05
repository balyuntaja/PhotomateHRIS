<?php

namespace App\Livewire;

use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Services\SessionWorkflowService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SessionReportApproval extends Component
{
    public int $reportId;

    public function mount(int $reportId): void
    {
        $this->reportId = $reportId;
    }

    public function approve(): void
    {
        try {
            /** @var Karyawan|null $user */
            $user = Auth::user();
            $workflow = app(SessionWorkflowService::class);

            if (!$user instanceof Karyawan || !$workflow->isSupervisorOrAdmin($user)) {
                throw new \RuntimeException('Anda tidak memiliki hak akses untuk menyetujui rekap sesi ini.');
            }

            $report = SessionReport::findOrFail($this->reportId);

            if (!$report->isWaitingApproval()) {
                throw new \RuntimeException('Rekap sesi ini tidak berstatus menunggu persetujuan.');
            }

            $workflow->approve($report, $user);

            Notification::make()
                ->title('Rekap sesi berhasil disetujui.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal menyetujui rekap sesi.')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function render()
    {
        $report = SessionReport::find($this->reportId);

        /** @var Karyawan|null $user */
        $user = Auth::user();

        $canApprove = $report?->isWaitingApproval()
            && $user instanceof Karyawan
            && app(SessionWorkflowService::class)->isSupervisorOrAdmin($user);

        return view('livewire.session-report-approval', [
            'report' => $report,
            'canApprove' => $canApprove,
        ]);
    }
}
