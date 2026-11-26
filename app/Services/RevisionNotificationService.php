<?php

namespace App\Services;

use App\Filament\Resources\SessionReportResource;
use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Notifications\SessionRevisionDatabaseNotification;
use Carbon\CarbonInterface;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

class RevisionNotificationService
{
    public const TYPE_SESSION_REVISION = 'session_revision';

    /**
     * Karyawan atau crew yang berhak menerima notifikasi revisi untuk rekap terkait.
     *
     * @return Collection<int, Karyawan>
     */
    public function resolveNotifiables(SessionReport $report): Collection
    {
        $report->loadMissing(['submitter', 'crews']);

        return collect([$report->submitter])
            ->merge($report->crews)
            ->filter(fn ($user): bool => $user instanceof Karyawan)
            ->unique('karyawan_id')
            ->values();
    }

    /**
     * Bangun payload database notification agar kompatibel dengan Filament navbar bell
     * sekaligus menyimpan metadata revisi secara terstruktur.
     *
     * @return array<string, mixed>
     */
    public function buildDatabasePayload(
        SessionReport $report,
        string $reason,
        Karyawan $supervisor,
        CarbonInterface $revisedAt,
    ): array {
        $report->loadMissing(['cabang', 'submitter', 'crews']);

        $timezone = config('session_reports.timezone', 'Asia/Jakarta');
        $reportDateFormatted = $report->report_date
            ? $report->report_date->copy()->locale('id')->translatedFormat('d F Y')
            : '-';
        $revisedAtFormatted = $revisedAt->copy()
            ->setTimezone($timezone)
            ->locale('id')
            ->translatedFormat('d M Y, H:i') . ' WIB';
        $cabangName = $report->cabang?->nama_cabang ?? 'Tanpa Cabang';
        $crewNames = $report->crew_names;
        $detailUrl = $this->resolveReportUrl($report, 'view');
        $editUrl = $this->resolveReportUrl($report, 'edit');

        $filamentPayload = FilamentNotification::make()
            ->title('Rekap Sesi Memerlukan Revisi')
            ->body("Rekap #{$report->report_number} ({$reportDateFormatted} · {$cabangName}) — \"{$reason}\"")
            ->danger()
            ->icon('heroicon-o-exclamation-triangle')
            ->iconColor('danger')
            ->actions([
                NotificationAction::make('open_detail')
                    ->label('Lihat Detail Rekap')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('danger')
                    ->url($detailUrl)
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();

        return array_merge($filamentPayload, [
            'type' => self::TYPE_SESSION_REVISION,
            'session_report_id' => $report->id,
            'report_number' => $report->report_number,
            'report_date' => $report->report_date?->toDateString(),
            'report_date_formatted' => $reportDateFormatted,
            'cabang_id' => $report->cabang_id,
            'cabang_name' => $cabangName,
            'crew_names' => $crewNames,
            'revision_note' => $reason,
            'revised_at' => $revisedAt->toIso8601String(),
            'revised_at_formatted' => $revisedAtFormatted,
            'supervisor_id' => $supervisor->karyawan_id,
            'supervisor_name' => $supervisor->nama_lengkap,
            'detail_url' => $detailUrl,
            'edit_url' => $editUrl,
        ]);
    }

    /**
     * Kirim database notification revisi secara sinkron kepada karyawan/crew terkait.
     */
    public function sendRevisionNotifications(
        SessionReport $report,
        string $reason,
        Karyawan $supervisor,
        ?CarbonInterface $revisedAt = null,
    ): void {
        $revisedAt ??= now();
        $report->load(['cabang', 'submitter', 'crews']);

        foreach ($this->resolveNotifiables($report) as $recipient) {
            $recipient->notifyNow(
                new SessionRevisionDatabaseNotification($report, $reason, $supervisor, $revisedAt)
            );
        }
    }

    /**
     * Ambil seluruh rekap aktif berstatus REVISION milik karyawan untuk ditampilkan di popup
     * setiap kali halaman Dashboard atau Rekap Sesi dimuat/di-refresh.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getActiveRevisionsForEmployee(Karyawan $user): Collection
    {
        $workflow = app(SessionWorkflowService::class);

        if ($workflow->isSupervisorOrAdmin($user)) {
            return collect();
        }

        $reports = SessionReport::query()
            ->with(['cabang', 'crews', 'submitter'])
            ->where('status', SessionReport::STATUS_REVISION)
            ->where(function ($query) use ($user): void {
                $query->where('submitted_by', $user->karyawan_id)
                    ->orWhereHas('crews', fn ($c) => $c->where('karyawan.karyawan_id', $user->karyawan_id));
            })
            ->orderByDesc('updated_at')
            ->get();

        if ($reports->isEmpty()) {
            return collect();
        }

        return $reports->map(function (SessionReport $report) use ($user): array {
            $reportDateFormatted = $report->report_date
                ? $report->report_date->copy()->locale('id')->translatedFormat('d M Y')
                : '-';

            return [
                'session_report_id' => $report->id,
                'report_number' => $report->report_number,
                'report_date_formatted' => $reportDateFormatted,
                'cabang_name' => $report->cabang?->nama_cabang ?? 'Tanpa Cabang',
                'crew_names' => $report->crew_names,
                'revision_note' => $report->revision_note ?: '-',
                'target_url' => $this->resolveActionUrlForUser($user, $report),
            ];
        })->values();
    }

    /**
     * Tandai notifikasi revisi milik user untuk rekap tertentu saat popup CTA diklik,
     * lalu kembalikan URL tujuan apabila user berhak mengakses.
     */
    public function openRevisionReport(Karyawan $user, int $reportId): ?string
    {
        $report = SessionReport::with(['cabang', 'crews', 'submitter'])->find($reportId);

        if (! $report || ! app(SessionWorkflowService::class)->isOwnReport($user, $report)) {
            return null;
        }

        $this->markReportNotificationsAsRead($user, $report);

        return $this->resolveActionUrlForUser($user, $report);
    }

    /**
     * Ambil daftar revisi yang belum dibaca untuk ditampilkan pada popup akun karyawan.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getUnreadRevisionsForEmployee(Karyawan $user): Collection
    {
        $workflow = app(SessionWorkflowService::class);

        if ($workflow->isSupervisorOrAdmin($user)) {
            return collect();
        }

        $notifications = $user->unreadNotifications()
            ->where(function ($query): void {
                $query->where('type', SessionRevisionDatabaseNotification::class)
                    ->orWhere('data->type', self::TYPE_SESSION_REVISION);
            })
            ->orderByDesc('created_at')
            ->get();

        if ($notifications->isEmpty()) {
            return collect();
        }

        $reportIds = $notifications
            ->map(fn (DatabaseNotification $n): int => (int) data_get($n->data, 'session_report_id'))
            ->filter()
            ->unique()
            ->values();

        if ($reportIds->isEmpty()) {
            return collect();
        }

        $reports = SessionReport::query()
            ->with(['cabang', 'crews', 'submitter'])
            ->whereIn('id', $reportIds)
            ->get()
            ->keyBy('id');

        $timezone = config('session_reports.timezone', 'Asia/Jakarta');

        return $notifications
            ->filter(function (DatabaseNotification $notification) use ($reports, $user, $workflow): bool {
                $reportId = (int) data_get($notification->data, 'session_report_id');
                $report = $reports->get($reportId);

                return $report instanceof SessionReport
                    && $report->isRevision()
                    && $workflow->isOwnReport($user, $report);
            })
            ->unique(fn (DatabaseNotification $n): int => (int) data_get($n->data, 'session_report_id'))
            ->map(function (DatabaseNotification $notification) use ($reports, $user, $timezone): array {
                $reportId = (int) data_get($notification->data, 'session_report_id');
                /** @var SessionReport $report */
                $report = $reports->get($reportId);

                $reportDateFormatted = $report->report_date
                    ? $report->report_date->copy()->locale('id')->translatedFormat('d F Y')
                    : (string) (data_get($notification->data, 'report_date_formatted') ?? '-');

                $revisedAtFormatted = (string) (data_get($notification->data, 'revised_at_formatted') ?? (
                    $notification->created_at
                        ? $notification->created_at->copy()->setTimezone($timezone)->locale('id')->translatedFormat('d M Y, H:i') . ' WIB'
                        : '-'
                ));

                $crewNames = $report->crew_names;
                if ($crewNames === '-' && filled(data_get($notification->data, 'crew_names'))) {
                    $crewNames = (string) data_get($notification->data, 'crew_names');
                }

                return [
                    'notification_id' => $notification->id,
                    'session_report_id' => $report->id,
                    'report_number' => $report->report_number,
                    'report_date_formatted' => $reportDateFormatted,
                    'cabang_name' => $report->cabang?->nama_cabang ?? (string) (data_get($notification->data, 'cabang_name') ?? 'Tanpa Cabang'),
                    'crew_names' => $crewNames,
                    'revision_note' => $report->revision_note ?: (string) (data_get($notification->data, 'revision_note') ?? '-'),
                    'supervisor_name' => (string) (data_get($notification->data, 'supervisor_name') ?? 'Supervisor'),
                    'revised_at_formatted' => $revisedAtFormatted,
                    'target_url' => $this->resolveActionUrlForUser($user, $report),
                    'detail_url' => $this->resolveReportUrl($report, 'view'),
                ];
            })
            ->values();
    }

    /**
     * Tandai notifikasi revisi tertentu (dan notifikasi unread lain untuk rekap yang sama milik user)
     * sebagai sudah dibaca, lalu kembalikan URL tujuan apabila user berhak mengakses.
     */
    public function openRevisionNotification(Karyawan $user, string $notificationId): ?string
    {
        $notification = $user->unreadNotifications()
            ->where('id', $notificationId)
            ->where(function ($query): void {
                $query->where('type', SessionRevisionDatabaseNotification::class)
                    ->orWhere('data->type', self::TYPE_SESSION_REVISION);
            })
            ->first();

        if (! $notification) {
            return null;
        }

        $reportId = (int) data_get($notification->data, 'session_report_id');
        $report = SessionReport::with(['cabang', 'crews', 'submitter'])->find($reportId);

        if (! $report || ! app(SessionWorkflowService::class)->isOwnReport($user, $report)) {
            return null;
        }

        $this->markReportNotificationsAsRead($user, $report);

        return $this->resolveActionUrlForUser($user, $report);
    }

    /**
     * Tandai semua notifikasi revisi milik user pada rekap tertentu sebagai sudah dibaca.
     */
    public function markReportNotificationsAsRead(Karyawan $user, SessionReport $report): int
    {
        $matchingIds = $user->unreadNotifications()
            ->where(function ($query): void {
                $query->where('type', SessionRevisionDatabaseNotification::class)
                    ->orWhere('data->type', self::TYPE_SESSION_REVISION);
            })
            ->get()
            ->filter(fn (DatabaseNotification $n): bool => (int) data_get($n->data, 'session_report_id') === (int) $report->id)
            ->pluck('id')
            ->all();

        if (empty($matchingIds)) {
            return 0;
        }

        return $user->unreadNotifications()
            ->whereIn('id', $matchingIds)
            ->update(['read_at' => now()]);
    }

    /**
     * Tandai seluruh notifikasi revisi unread untuk suatu rekap sebagai sudah dibaca
     * ketika rekap sudah di-submit ulang oleh crew.
     */
    public function markAllRevisionNotificationsAsReadForReport(SessionReport $report): int
    {
        $matchingIds = DatabaseNotification::query()
            ->whereNull('read_at')
            ->where(function ($query): void {
                $query->where('type', SessionRevisionDatabaseNotification::class)
                    ->orWhere('data->type', self::TYPE_SESSION_REVISION);
            })
            ->get()
            ->filter(fn (DatabaseNotification $n): bool => (int) data_get($n->data, 'session_report_id') === (int) $report->id)
            ->pluck('id')
            ->all();

        if (empty($matchingIds)) {
            return 0;
        }

        return DatabaseNotification::query()
            ->whereIn('id', $matchingIds)
            ->update(['read_at' => now()]);
    }

    public function resolveActionUrlForUser(Karyawan $user, SessionReport $report): string
    {
        return $user->can('update', $report)
            ? $this->resolveReportUrl($report, 'edit')
            : $this->resolveReportUrl($report, 'view');
    }

    public function resolveReportUrl(SessionReport $report, string $page = 'view'): string
    {
        try {
            return SessionReportResource::getUrl($page, ['record' => $report], panel: 'admin');
        } catch (\Throwable) {
            return url('/admin/session-reports/' . $report->id . ($page === 'edit' ? '/edit' : ''));
        }
    }
}
