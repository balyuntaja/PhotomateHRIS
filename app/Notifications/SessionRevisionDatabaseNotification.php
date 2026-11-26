<?php

namespace App\Notifications;

use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Services\RevisionNotificationService;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Notification;

class SessionRevisionDatabaseNotification extends Notification
{
    public function __construct(
        public SessionReport $report,
        public string $reason,
        public Karyawan $supervisor,
        public CarbonInterface $revisedAt,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return app(RevisionNotificationService::class)->buildDatabasePayload(
            $this->report,
            $this->reason,
            $this->supervisor,
            $this->revisedAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
