<?php

namespace App\Notifications;

use App\Models\SessionReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SessionRevisionNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public $locale = 'id';

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public SessionReport $report,
        public string $reason,
        public ?string $supervisorName = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->report->loadMissing(['cabang', 'submitter', 'crews']);

        // Action URL to edit report in Filament
        $actionUrl = route('filament.admin.resources.session-reports.edit', ['record' => $this->report->id]);

        $cabangName = $this->report->cabang->nama_cabang ?? 'Cabang';

        return (new MailMessage)
            ->subject('⚠️ [Perlu Revisi] Rekap Sesi #'.$this->report->report_number.' - '.$cabangName)
            ->view('emails.session-revision', [
                'report' => $this->report,
                'reason' => $this->reason,
                'supervisorName' => $this->supervisorName,
                'actionUrl' => $actionUrl,
            ]);
    }
}
