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
    public string $revisionReason = '';
    public bool $showApproveModal = false;
    public bool $showRevisionModal = false;

    public function mount(int $reportId): void
    {
        $this->reportId = $reportId;
    }

    public function openApproveModal(): void
    {
        $this->showApproveModal = true;
    }

    public function closeApproveModal(): void
    {
        $this->showApproveModal = false;
    }

    public function openRevisionModal(): void
    {
        $this->resetErrorBag();
        $this->revisionReason = '';
        $this->showRevisionModal = true;
    }

    public function closeRevisionModal(): void
    {
        $this->resetErrorBag();
        $this->revisionReason = '';
        $this->showRevisionModal = false;
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

            if ($workflow->isOwnReport($user, $report)) {
                throw new \RuntimeException('Anda tidak dapat menyetujui rekap sesi Anda sendiri.');
            }

            if (!$report->isWaitingApproval()) {
                throw new \RuntimeException('Rekap sesi ini tidak berstatus menunggu persetujuan.');
            }

            $workflow->approve($report, $user);

            $this->showApproveModal = false;

            Notification::make()
                ->title('Rekap sesi berhasil disetujui.')
                ->success()
                ->send();

            $this->dispatch('$refresh');
            $this->dispatch('session-report-updated');
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal menyetujui rekap sesi.')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function requestRevision(?string $reason = null): void
    {
        if ($reason !== null) {
            $this->revisionReason = $reason;
        }

        $this->validate([
            'revisionReason' => ['required', 'string', 'min:3'],
        ], [
            'revisionReason.required' => 'Catatan revisi wajib diisi agar crew dapat memperbaiki kesalahan.',
            'revisionReason.min' => 'Catatan revisi minimal 3 karakter.',
        ]);

        try {
            /** @var Karyawan|null $user */
            $user = Auth::user();
            $workflow = app(SessionWorkflowService::class);

            if (!$user instanceof Karyawan || !$workflow->isSupervisorOrAdmin($user)) {
                throw new \RuntimeException('Anda tidak memiliki hak akses untuk meminta revisi rekap sesi ini.');
            }

            $report = SessionReport::findOrFail($this->reportId);

            if ($workflow->isOwnReport($user, $report)) {
                throw new \RuntimeException('Anda tidak dapat meminta revisi pada rekap sesi Anda sendiri.');
            }

            if (!$report->isWaitingApproval()) {
                throw new \RuntimeException('Rekap sesi ini tidak berstatus menunggu persetujuan.');
            }

            $workflow->requestRevision($report, $user, $this->revisionReason);

            $this->showRevisionModal = false;
            $this->revisionReason = '';

            Notification::make()
                ->title('Rekap sesi dikembalikan untuk revisi.')
                ->body('Catatan revisi telah dikirimkan ke crew.')
                ->warning()
                ->send();

            $this->dispatch('$refresh');
            $this->dispatch('session-report-updated');
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal meminta revisi.')
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

        $canApprove = false;
        if ($report && $user instanceof Karyawan) {
            $canApprove = app(SessionWorkflowService::class)->canApprove($user, $report);
        }

        return view('livewire.session-report-approval', [
            'report' => $report,
            'canApprove' => $canApprove,
        ]);
    }
}
