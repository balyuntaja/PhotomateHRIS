<?php

namespace App\Services;

use App\Filament\Resources\EmployeeScheduleResource;
use App\Filament\Resources\SessionReportResource;
use App\Models\Absensi;
use App\Models\Cuti;
use App\Models\EmployeeSchedule;
use App\Models\Izin;
use App\Models\Karyawan;
use App\Models\SessionReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class EmployeeDashboardService
{
    public const APPROACHING_DEADLINE_HOURS = 24;

    public const RECENT_REPORTS_LIMIT = 5;

    public const ATTENTION_LIMIT = 8;

    public function isEmployeeDashboard(?Karyawan $user): bool
    {
        if (! $user instanceof Karyawan) {
            return false;
        }

        if (app(SessionWorkflowService::class)->isSupervisorOrAdmin($user)) {
            return false;
        }

        return $user->role_id === 'R07'
            || strtolower((string) $user->role?->name) === 'karyawan'
            || $user->hasRole(['Karyawan', 'karyawan']);
    }

    /**
     * Query rekap sesi yang hanya milik karyawan yang sedang login (submitter atau crew bertugas).
     */
    public function ownReportsQuery(Karyawan $user): Builder
    {
        return SessionReport::query()->where(function (Builder $query) use ($user): void {
            $query->where('submitted_by', $user->karyawan_id)
                ->orWhereHas('crews', function (Builder $crews) use ($user): void {
                    $crews->where('karyawan.karyawan_id', $user->karyawan_id);
                });
        });
    }

    /**
     * Kumpulkan seluruh data personal untuk dashboard role karyawan.
     *
     * @return array<string, mixed>
     */
    public function buildDashboardData(Karyawan $user): array
    {
        $attention = $this->getNeedsAttention($user);

        return [
            'operational_statuses' => $this->getOperationalStatus($user),
            'needs_attention' => $attention,
            'deadline_info' => $this->getDeadlineInfo($attention['all_items']),
            'recent_reports' => $this->getRecentReports($user),
            'today_activities' => $this->getTodayActivities($user),
        ];
    }

    /**
     * Empat kartu Status Operasional milik karyawan, masing-masing mengarah ke Rekap Sesi terfilter.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOperationalStatus(Karyawan $user): array
    {
        $counts = $this->ownReportsQuery($user)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $waitingCount = (int) ($counts[SessionReport::STATUS_WAITING_APPROVAL] ?? 0);
        $revisionCount = (int) ($counts[SessionReport::STATUS_REVISION] ?? 0);
        $approvedCount = (int) ($counts[SessionReport::STATUS_APPROVED] ?? 0);
        $draftCount = (int) ($counts[SessionReport::STATUS_DRAFT] ?? 0);

        return [
            [
                'key' => SessionReport::STATUS_WAITING_APPROVAL,
                'label' => 'Waiting Approval',
                'count' => $waitingCount,
                'hint' => 'Menunggu pemeriksaan supervisor',
                'tone' => 'warning',
                'dot_color' => '#F59E0B',
                'emphasis' => false,
                'url' => $this->filteredReportsUrl(SessionReport::STATUS_WAITING_APPROVAL),
            ],
            [
                'key' => SessionReport::STATUS_REVISION,
                'label' => 'Perlu Revisi',
                'count' => $revisionCount,
                'hint' => $revisionCount > 0 ? 'Segera perbaiki & submit ulang' : 'Tidak ada revisi tertunda',
                'tone' => 'danger',
                'dot_color' => '#DC2626',
                'emphasis' => true,
                'url' => $this->filteredReportsUrl(SessionReport::STATUS_REVISION),
            ],
            [
                'key' => SessionReport::STATUS_APPROVED,
                'label' => 'Approved',
                'count' => $approvedCount,
                'hint' => 'Rekap Anda yang telah disetujui',
                'tone' => 'success',
                'dot_color' => '#10B981',
                'emphasis' => false,
                'url' => $this->filteredReportsUrl(SessionReport::STATUS_APPROVED),
            ],
            [
                'key' => SessionReport::STATUS_DRAFT,
                'label' => 'Draft',
                'count' => $draftCount,
                'hint' => 'Belum diajukan ke supervisor',
                'tone' => 'neutral',
                'dot_color' => '#94A3B8',
                'emphasis' => false,
                'url' => $this->filteredReportsUrl(SessionReport::STATUS_DRAFT),
            ],
        ];
    }

    /**
     * Daftar pekerjaan pribadi karyawan yang membutuhkan tindakan dengan prioritas:
     * 1. Rekap sesi REVISION
     * 2. Rekap sesi DRAFT yang mendekati atau melewati batas waktu input H+2
     * 3. Rekap sesi DRAFT lainnya yang belum disubmit
     *
     * @return array{items: Collection<int, array<string, mixed>>, all_items: Collection<int, array<string, mixed>>, total: int}
     */
    public function getNeedsAttention(Karyawan $user): array
    {
        $timezone = config('session_reports.timezone', 'Asia/Jakarta');
        $nowWib = Carbon::now($timezone);

        $reports = $this->ownReportsQuery($user)
            ->whereIn('status', [
                SessionReport::STATUS_REVISION,
                SessionReport::STATUS_DRAFT,
            ])
            ->with(['cabang'])
            ->orderByDesc('updated_at')
            ->get();

        $allItems = $reports
            ->map(function (SessionReport $report) use ($user, $nowWib): array {
                $isRevision = $report->isRevision();
                $deadline = $report->inputDeadline();
                $minutesRemaining = $deadline ? $nowWib->diffInMinutes($deadline, false) : null;
                $isPastDeadline = $deadline !== null && $minutesRemaining !== null && $minutesRemaining < 0;
                $isApproachingDeadline = $deadline !== null && $minutesRemaining !== null && $minutesRemaining >= 0 && $minutesRemaining <= (self::APPROACHING_DEADLINE_HOURS * 60);

                $priority = match (true) {
                    $isRevision => 1,
                    $isApproachingDeadline => 2,
                    $isPastDeadline => 3,
                    default => 4,
                };

                $deadlineState = match (true) {
                    $isRevision => 'revision_exempt',
                    $isPastDeadline => 'overdue',
                    $isApproachingDeadline => 'approaching',
                    default => 'open',
                };

                $deadlineBadge = match ($deadlineState) {
                    'revision_exempt' => 'Batas H+2 dikecualikan',
                    'overdue' => 'Melewati Batas',
                    'approaching' => $this->formatRemainingBadge($minutesRemaining ?? 0),
                    default => 'Masih Terbuka',
                };

                $deadlineText = $deadline
                    ? $deadline->copy()->locale('id')->translatedFormat('d M Y') . ' · 23:59:59 WIB'
                    : '-';

                $canUpdate = $user->can('update', $report);
                $viewUrl = $this->reportUrl($report, 'view');
                $editUrl = $this->reportUrl($report, 'edit');

                $actionLabel = match (true) {
                    $isRevision && $canUpdate => 'Perbaiki',
                    $canUpdate => 'Edit & Submit',
                    default => 'Lihat Detail',
                };

                $actionUrl = $canUpdate ? $editUrl : $viewUrl;

                return [
                    'id' => $report->id,
                    'number' => $report->report_number,
                    'date' => $report->report_date?->copy()->locale('id')->translatedFormat('d M Y') ?? '-',
                    'branch' => $report->cabang?->nama_cabang ?? 'Tanpa cabang',
                    'status' => $report->status,
                    'status_label' => $isRevision ? 'Perlu Revisi' : $report->status_label,
                    'status_color' => $report->status_color,
                    'is_revision' => $isRevision,
                    'revision_note' => $isRevision ? $report->revision_note : null,
                    'deadline_text' => $deadlineText,
                    'deadline_state' => $deadlineState,
                    'deadline_badge' => $deadlineBadge,
                    'is_approaching_deadline' => $isApproachingDeadline,
                    'is_past_deadline' => $isPastDeadline,
                    'priority' => $priority,
                    'deadline_timestamp' => $deadline?->getTimestamp() ?? PHP_INT_MAX,
                    'updated_timestamp' => $report->updated_at?->getTimestamp() ?? 0,
                    'updated' => $report->updated_at?->copy()->locale('id')->diffForHumans() ?? '-',
                    'action_label' => $actionLabel,
                    'action_url' => $actionUrl,
                    'detail_url' => $viewUrl,
                    'tone' => $isRevision ? 'danger' : ($isApproachingDeadline || $isPastDeadline ? 'warning' : 'neutral'),
                ];
            })
            ->sort(function (array $a, array $b): int {
                if ($a['priority'] !== $b['priority']) {
                    return $a['priority'] <=> $b['priority'];
                }

                if ($a['priority'] === 1) {
                    return $b['updated_timestamp'] <=> $a['updated_timestamp'];
                }

                return $a['deadline_timestamp'] <=> $b['deadline_timestamp'];
            })
            ->values();

        return [
            'items' => $allItems->take(self::ATTENTION_LIMIT),
            'all_items' => $allItems,
            'total' => $allItems->count(),
        ];
    }

    /**
     * Ringkasan informasi aturan batas waktu input H+2 untuk role karyawan.
     *
     * @param  Collection<int, array<string, mixed>>  $attentionItems
     * @return array<string, mixed>
     */
    public function getDeadlineInfo(Collection $attentionItems): array
    {
        $timezone = config('session_reports.timezone', 'Asia/Jakarta');
        $nowWib = Carbon::now($timezone);
        $earliestDate = SessionReport::earliestInputDate();
        $earliestDeadline = SessionReport::inputDeadlineFor($earliestDate);
        $todayDeadline = SessionReport::inputDeadlineFor($nowWib->toDateString());

        $approachingCount = $attentionItems->where('deadline_state', 'approaching')->count();
        $overdueCount = $attentionItems->where('deadline_state', 'overdue')->count();

        return [
            'window_days' => SessionReport::INPUT_WINDOW_DAYS,
            'rule_text' => 'Tanggal Rekap + ' . SessionReport::INPUT_WINDOW_DAYS . ' hari, pukul 23:59:59 WIB',
            'earliest_date_label' => $earliestDate->copy()->locale('id')->translatedFormat('d F Y'),
            'earliest_deadline_label' => $earliestDeadline
                ? $earliestDeadline->copy()->locale('id')->translatedFormat('d M Y') . ', 23:59:59 WIB'
                : '-',
            'today_date_label' => $nowWib->copy()->locale('id')->translatedFormat('d F Y'),
            'today_deadline_label' => $todayDeadline
                ? $todayDeadline->copy()->locale('id')->translatedFormat('d M Y') . ', 23:59:59 WIB'
                : '-',
            'approaching_count' => $approachingCount,
            'overdue_count' => $overdueCount,
            'has_approaching' => $approachingCount > 0,
            'has_overdue' => $overdueCount > 0,
        ];
    }

    /**
     * Maksimal 5 rekap sesi terakhir milik karyawan yang sedang login.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getRecentReports(Karyawan $user): Collection
    {
        return $this->ownReportsQuery($user)
            ->with(['cabang'])
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->limit(self::RECENT_REPORTS_LIMIT)
            ->get()
            ->map(function (SessionReport $report): array {
                $isManual = $report->usesManualSessionInput();
                $sessionsCount = $isManual
                    ? (int) ($report->wedding_total_sessions ?: $report->total_transactions ?: $report->total_sessions)
                    : (int) ($report->total_transactions > 0 ? $report->total_transactions : $report->total_sessions);

                return [
                    'id' => $report->id,
                    'number' => $report->report_number,
                    'date' => $report->report_date?->copy()->locale('id')->translatedFormat('d M Y') ?? '-',
                    'branch' => $report->cabang?->nama_cabang ?? 'Tanpa cabang',
                    'sessions_count' => $sessionsCount,
                    'sheets_count' => (int) $report->total_sessions,
                    'amount' => (int) $report->grand_total_amount,
                    'show_amount' => ! $isManual,
                    'status' => $report->status,
                    'status_label' => $report->isRevision() ? 'Perlu Revisi' : $report->status_label,
                    'status_color' => $report->status_color,
                    'is_revision' => $report->isRevision(),
                    'detail_url' => $this->reportUrl($report, 'view'),
                ];
            });
    }

    /**
     * Aktivitas hari ini yang disesuaikan dengan hak akses karyawan.
     * Tanpa izin view_any, hanya data milik karyawan yang login yang boleh ditampilkan.
     *
     * @return array<string, mixed>
     */
    public function getTodayActivities(Karyawan $user): array
    {
        $today = Carbon::today();

        $canViewAllSchedules = $user->can('viewAny', EmployeeSchedule::class) || EmployeeScheduleResource::canAccess();
        $canViewAllLeaves = $user->can('viewAny', Cuti::class) || $user->can('view_any_cuti');
        $canViewAllPermissions = $user->can('viewAny', Izin::class) || $user->can('view_any_izin');

        $scheduleQuery = EmployeeSchedule::query()
            ->whereDate('shift_date', $today)
            ->orderBy('shift_type')
            ->orderBy('employee_name');

        if (! $canViewAllSchedules) {
            $scheduleQuery->where('karyawan_id', $user->karyawan_id);
        }

        $scheduleCount = (clone $scheduleQuery)->count();
        $schedules = $scheduleQuery
            ->limit(6)
            ->get()
            ->map(fn (EmployeeSchedule $schedule): array => [
                'name' => $schedule->employee_name ?: $user->nama_lengkap,
                'initials' => $this->initials($schedule->employee_name ?: $user->nama_lengkap),
                'shift' => $schedule->shift_type,
                'location' => $schedule->booth_location,
                'notes' => $schedule->notes,
            ]);

        $leaveQuery = Cuti::query()
            ->with('karyawan')
            ->where('status_cuti', 'Disetujui')
            ->whereDate('tanggal_mulai', '<=', $today)
            ->where(function (Builder $query) use ($today): void {
                $query->whereDate('tanggal_selesai', '>=', $today)
                    ->orWhereNull('tanggal_selesai');
            });

        if (! $canViewAllLeaves) {
            $leaveQuery->where('karyawan_id', $user->karyawan_id);
        }

        $allLeaves = $leaveQuery->get()->filter(fn (Cuti $cuti): bool => $cuti->karyawan !== null);
        $leaveCount = $allLeaves->count();
        $leaves = $allLeaves
            ->take(6)
            ->map(fn (Cuti $cuti): array => [
                'name' => $cuti->karyawan->nama_lengkap,
                'initials' => $this->initials($cuti->karyawan->nama_lengkap),
                'type' => $cuti->jenis_cuti,
                'period' => $this->formatDateRange($cuti->tanggal_mulai, $cuti->tanggal_selesai),
            ])
            ->values();

        $permissionQuery = Izin::query()
            ->with('karyawan')
            ->where('status_izin', 'Disetujui')
            ->whereDate('tanggal_mulai', '<=', $today)
            ->where(function (Builder $query) use ($today): void {
                $query->whereDate('tanggal_selesai', '>=', $today)
                    ->orWhereNull('tanggal_selesai');
            });

        if (! $canViewAllPermissions) {
            $permissionQuery->where('karyawan_id', $user->karyawan_id);
        }

        $allPermissions = $permissionQuery->get()->filter(fn (Izin $izin): bool => $izin->karyawan !== null);
        $permissionCount = $allPermissions->count();
        $permissions = $allPermissions
            ->take(6)
            ->map(fn (Izin $izin): array => [
                'name' => $izin->karyawan->nama_lengkap,
                'initials' => $this->initials($izin->karyawan->nama_lengkap),
                'type' => $izin->jenis_izin,
                'period' => $this->formatDateRange($izin->tanggal_mulai, $izin->tanggal_selesai),
            ])
            ->values();

        $ownAttendance = Absensi::query()
            ->where('karyawan_id', $user->karyawan_id)
            ->whereDate('tanggal', $today)
            ->whereNotNull('waktu_masuk')
            ->first();

        return [
            'schedules' => $schedules,
            'schedule_count' => $scheduleCount,
            'can_view_all_schedules' => $canViewAllSchedules,
            'leaves' => $leaves,
            'leave_count' => $leaveCount,
            'can_view_all_leaves' => $canViewAllLeaves,
            'permissions' => $permissions,
            'permission_count' => $permissionCount,
            'can_view_all_permissions' => $canViewAllPermissions,
            'attendance_label' => $ownAttendance?->waktu_masuk
                ? 'Sudah absen masuk pukul ' . $ownAttendance->waktu_masuk->format('H:i')
                : 'Belum absen masuk hari ini',
        ];
    }

    private function formatRemainingBadge(int $minutesRemaining): string
    {
        if ($minutesRemaining < 60) {
            return 'Mendekati Deadline · < 1 jam';
        }

        $hours = (int) ceil($minutesRemaining / 60);

        return "Mendekati Deadline · sisa {$hours} jam";
    }

    private function filteredReportsUrl(string $status): string
    {
        try {
            return SessionReportResource::getUrl('index', [
                'tableFilters' => [
                    'status' => [
                        'value' => $status,
                    ],
                ],
            ], panel: 'admin');
        } catch (\Throwable) {
            return url('/admin/session-reports?tableFilters[status][value]=' . urlencode($status));
        }
    }

    private function reportUrl(SessionReport $report, string $page): string
    {
        try {
            return SessionReportResource::getUrl($page, ['record' => $report], panel: 'admin');
        } catch (\Throwable) {
            return url('/admin/session-reports/' . $report->id . ($page === 'edit' ? '/edit' : ''));
        }
    }

    private function initials(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '?';
        }

        $words = preg_split('/\s+/', $name) ?: [];
        $initials = '';

        foreach ($words as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));

            if (mb_strlen($initials) >= 2) {
                break;
            }
        }

        return $initials !== '' ? $initials : '?';
    }

    private function formatDateRange(?Carbon $start, ?Carbon $end): string
    {
        if ($start === null) {
            return '-';
        }

        $startLabel = $start->copy()->locale('id')->translatedFormat('d M');

        if ($end === null || $end->isSameDay($start)) {
            return $startLabel;
        }

        return $startLabel . ' – ' . $end->copy()->locale('id')->translatedFormat('d M');
    }
}
