<?php

namespace App\Livewire;

use App\Models\Karyawan;
use App\Services\RevisionNotificationService;
use App\Services\SessionWorkflowService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class EmployeeRevisionPopup extends Component
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $revisionItems = [];

    public bool $showModal = false;

    public function mount(): void
    {
        $this->loadRevisionItems(openIfAny: true);
    }

    #[On('session-report-revision-updated')]
    public function refreshRevisionItems(): void
    {
        $this->loadRevisionItems(openIfAny: false);
    }

    public function loadRevisionItems(bool $openIfAny = false): void
    {
        $user = Auth::user();

        if (! $user instanceof Karyawan || app(SessionWorkflowService::class)->isSupervisorOrAdmin($user)) {
            $this->revisionItems = [];
            $this->showModal = false;

            return;
        }

        $this->revisionItems = app(RevisionNotificationService::class)
            ->getActiveRevisionsForEmployee($user)
            ->all();

        if (empty($this->revisionItems)) {
            $this->showModal = false;

            return;
        }

        if ($openIfAny) {
            $this->showModal = true;
        }
    }

    public function dismissPopup(): void
    {
        // Menutup popup hanya untuk tampilan saat ini; tidak menandai notifikasi sebagai sudah dibaca.
        $this->showModal = false;
    }

    public function openRevision(int|string $reportId): void
    {
        $user = Auth::user();

        if (! $user instanceof Karyawan || app(SessionWorkflowService::class)->isSupervisorOrAdmin($user)) {
            return;
        }

        $service = app(RevisionNotificationService::class);
        $targetUrl = is_numeric($reportId)
            ? $service->openRevisionReport($user, (int) $reportId)
            : $service->openRevisionNotification($user, (string) $reportId);

        if (! $targetUrl) {
            $this->loadRevisionItems(openIfAny: false);

            return;
        }

        $this->showModal = false;
        $this->dispatch('databaseNotificationsSent');
        $this->redirect($targetUrl);
    }

    public function render()
    {
        return view('livewire.employee-revision-popup');
    }
}
