<?php

namespace App\Notifications;

use App\Models\SessionReport;
use App\Services\BranchBonusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SessionApprovedNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public $locale = 'id';

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public SessionReport $report
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Load necessary relationships
        $this->report->loadMissing(['cabang', 'approver', 'crews', 'submitter']);

        // Calculate bonus details for this report
        $bonusService = app(BranchBonusService::class);
        $bonusDetails = $bonusService->calculateBonusForReport($this->report);

        // Action URL to view report detail in Filament
        $actionUrl = route('filament.admin.resources.session-reports.view', ['record' => $this->report->id]);

        $cabangName = $this->report->cabang->nama_cabang ?? 'Cabang';

        return (new MailMessage)
            ->subject('✅ [Disetujui] Rekap Sesi #'.$this->report->report_number.' - '.$cabangName)
            ->view('emails.session-approved', [
                'report' => $this->report,
                'bonusDetails' => $bonusDetails,
                'actionUrl' => $actionUrl,
            ]);
    }
}
