<?php

namespace App\Notifications;

use App\Models\SessionReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SessionSubmittedNotification extends Notification implements ShouldQueueAfterCommit
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
        public bool $isResubmission = false
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Load necessary relationships if not already loaded
        $this->report->loadMissing(['cabang', 'submitter', 'crews']);

        $actionUrl = route('filament.admin.resources.session-reports.index');

        $subjectPrefix = $this->isResubmission ? '[Diajukan Ulang] ' : '[Pengajuan Baru] ';
        $cabangName = $this->report->cabang->nama_cabang ?? 'Cabang';

        return (new MailMessage)
            ->subject($subjectPrefix.'Rekap Sesi #'.$this->report->report_number.' - '.$cabangName)
            ->view('emails.session-submitted', [
                'report' => $this->report,
                'isResubmission' => $this->isResubmission,
                'actionUrl' => $actionUrl,
            ]);
    }
}
