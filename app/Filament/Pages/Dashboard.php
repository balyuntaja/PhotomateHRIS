<?php

namespace App\Filament\Pages;

use App\Filament\Resources\SessionApprovalResource;
use App\Filament\Resources\SessionReportResource;
use App\Filament\Widgets\SessionOverviewWidget;
use App\Models\Absensi;
use App\Models\Cabang;
use App\Models\Cuti;
use App\Models\EmployeeSchedule;
use App\Models\Izin;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\SessionReport;
use App\Services\SessionWorkflowService;
use App\Utils\MonthHelper;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class Dashboard extends BaseDashboard
{
    protected static string $view = 'filament.pages.dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $navigationIcon = 'heroicon-o-home';

    public function getSubheading(): ?string
    {
        return 'Ringkasan operasional Photomate · '.$this->todayLabel();
    }

    /**
     * @return array<string, mixed>
     */
    public function getDashboardMetrics(): array
    {
        $widget = app(SessionOverviewWidget::class);
        $widget->filter = 'this_month';

        $data = $widget->getViewData();
        $approved = $widget->getFilteredReportsQuery()
            ->where('status', SessionReport::STATUS_APPROVED);

        return [
            'period_label' => MonthHelper::formatPeriod((int) now()->month, (int) now()->year),
            'revenue' => (int) $data['grandTotalRevenue'],
            'sessions' => (int) $data['totalSessions'],
            'transactions' => (int) (clone $approved)->sum('total_transactions'),
            'qris_revenue' => (int) $data['qrisRevenue'],
            'qris_sessions' => (int) $data['qrisSessions'],
            'qris_transactions' => (int) (clone $approved)->sum('total_qris_transactions'),
            'cash_revenue' => (int) $data['cashRevenue'],
            'cash_sessions' => (int) $data['cashSessions'],
            'cash_transactions' => (int) (clone $approved)->sum('total_cash_transactions'),
            'employees' => Karyawan::query()->count(),
            'branches' => Cabang::query()->count(),
            'companies' => Perusahaan::query()->count(),
            'average_per_session' => (int) $data['totalSessions'] > 0
                ? (int) round((int) $data['grandTotalRevenue'] / (int) $data['totalSessions'])
                : 0,
            'waiting_approval' => (int) $data['waitingApprovalCount'],
            'revision' => (int) $data['revisionCount'],
            'approved' => (int) $data['approvedCount'],
            'draft' => (int) $data['draftCount'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOperationalStatus(): array
    {
        $metrics = $this->getDashboardMetrics();
        $reportsUrl = SessionReportResource::getUrl('index');
        $approvalUrl = SessionApprovalResource::canAccess()
            ? SessionApprovalResource::getUrl('index')
            : $reportsUrl;

        return [
            [
                'label' => 'Waiting Approval',
                'count' => SessionReport::query()
                    ->where('status', SessionReport::STATUS_WAITING_APPROVAL)
                    ->count(),
                'hint' => 'Menunggu pemeriksaan',
                'tone' => 'warning',
                'url' => $approvalUrl,
            ],
            [
                'label' => 'Perlu Revisi',
                'count' => SessionReport::query()
                    ->where('status', SessionReport::STATUS_REVISION)
                    ->count(),
                'hint' => 'Menunggu perbaikan crew',
                'tone' => 'danger',
                'url' => $reportsUrl,
            ],
            [
                'label' => 'Approved',
                'count' => $metrics['approved'],
                'hint' => 'Rekap final bulan ini',
                'tone' => 'success',
                'url' => $reportsUrl,
            ],
            [
                'label' => 'Draft',
                'count' => $metrics['draft'],
                'hint' => 'Belum diajukan',
                'tone' => 'neutral',
                'url' => $reportsUrl,
            ],
        ];
    }

    /**
     * Pekerjaan yang memerlukan tindakan pengguna saat ini.
     *
     * @return array{items: Collection<int, array<string, mixed>>, total: int}
     */
    public function getNeedsAttention(): array
    {
        $user = Auth::user();

        if (! $user instanceof Karyawan) {
            return ['items' => collect(), 'total' => 0];
        }

        $workflow = app(SessionWorkflowService::class);
        $isSupervisor = $workflow->isSupervisorOrAdmin($user);

        $statuses = $isSupervisor
            ? [SessionReport::STATUS_WAITING_APPROVAL, SessionReport::STATUS_REVISION]
            : [SessionReport::STATUS_REVISION];

        $query = SessionReport::query()->whereIn('status', $statuses);

        if (! $isSupervisor) {
            $query->where(function ($query) use ($user): void {
                $query->where('submitted_by', $user->karyawan_id)
                    ->orWhereHas('crews', function ($crews) use ($user): void {
                        $crews->where('karyawan.karyawan_id', $user->karyawan_id);
                    });
            });
        }

        $total = (clone $query)->count();

        $items = $query
            ->with(['cabang', 'crews'])
            ->latest('updated_at')
            ->limit(6)
            ->get()
            ->map(function (SessionReport $report) use ($user): array {
                $canUpdate = $user->can('update', $report);
                $isWaiting = $report->status === SessionReport::STATUS_WAITING_APPROVAL;

                $url = $isWaiting && SessionApprovalResource::canAccess()
                    ? SessionApprovalResource::getUrl('index')
                    : ($canUpdate
                        ? SessionReportResource::getUrl('edit', ['record' => $report])
                        : SessionReportResource::getUrl('view', ['record' => $report]));

                return [
                    'id' => $report->id,
                    'number' => $report->report_number,
                    'date' => $report->report_date?->locale('id')->translatedFormat('d M Y') ?? '-',
                    'branch' => $report->cabang?->nama_cabang ?? 'Tanpa cabang',
                    'crew' => $report->crew_names,
                    'amount' => (int) $report->grand_total_amount,
                    'status' => $report->status,
                    'status_label' => $report->status_label,
                    'status_color' => $report->status_color,
                    'url' => $url,
                    'action' => $isWaiting ? 'Tinjau' : 'Perbaiki',
                    'tone' => $isWaiting ? 'warning' : 'danger',
                    'updated' => $report->updated_at?->locale('id')->diffForHumans(),
                ];
            });

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getTodaySchedules(): Collection
    {
        return EmployeeSchedule::query()
            ->whereDate('shift_date', Carbon::today())
            ->orderBy('shift_type')
            ->orderBy('employee_name')
            ->limit(6)
            ->get()
            ->map(fn (EmployeeSchedule $schedule): array => [
                'name' => $schedule->employee_name,
                'initials' => $this->initials($schedule->employee_name),
                'shift' => $schedule->shift_type,
                'location' => $schedule->booth_location,
                'notes' => $schedule->notes,
            ]);
    }

    public function getTodayScheduleCount(): int
    {
        return EmployeeSchedule::query()->whereDate('shift_date', Carbon::today())->count();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getTodayLeaves(): Collection
    {
        $today = Carbon::today();

        return Cuti::query()
            ->with('karyawan')
            ->where('status_cuti', 'Disetujui')
            ->whereDate('tanggal_mulai', '<=', $today)
            ->where(function ($query) use ($today): void {
                $query->whereDate('tanggal_selesai', '>=', $today)
                    ->orWhereNull('tanggal_selesai');
            })
            ->get()
            ->filter(fn (Cuti $cuti): bool => $cuti->karyawan !== null)
            ->take(6)
            ->map(fn (Cuti $cuti): array => [
                'name' => $cuti->karyawan->nama_lengkap,
                'initials' => $this->initials($cuti->karyawan->nama_lengkap),
                'type' => $cuti->jenis_cuti,
                'period' => $this->formatDateRange($cuti->tanggal_mulai, $cuti->tanggal_selesai),
            ])
            ->values();
    }

    public function getTodayLeaveCount(): int
    {
        $today = Carbon::today();

        return Cuti::query()
            ->where('status_cuti', 'Disetujui')
            ->whereDate('tanggal_mulai', '<=', $today)
            ->where(function ($query) use ($today): void {
                $query->whereDate('tanggal_selesai', '>=', $today)
                    ->orWhereNull('tanggal_selesai');
            })
            ->count();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getTodayPermissions(): Collection
    {
        $today = Carbon::today();

        return Izin::query()
            ->with('karyawan')
            ->where('status_izin', 'Disetujui')
            ->whereDate('tanggal_mulai', '<=', $today)
            ->where(function ($query) use ($today): void {
                $query->whereDate('tanggal_selesai', '>=', $today)
                    ->orWhereNull('tanggal_selesai');
            })
            ->get()
            ->filter(fn (Izin $izin): bool => $izin->karyawan !== null)
            ->take(6)
            ->map(fn (Izin $izin): array => [
                'name' => $izin->karyawan->nama_lengkap,
                'initials' => $this->initials($izin->karyawan->nama_lengkap),
                'type' => $izin->jenis_izin,
                'period' => $this->formatDateRange($izin->tanggal_mulai, $izin->tanggal_selesai),
            ])
            ->values();
    }

    public function getTodayPermissionCount(): int
    {
        $today = Carbon::today();

        return Izin::query()
            ->where('status_izin', 'Disetujui')
            ->whereDate('tanggal_mulai', '<=', $today)
            ->where(function ($query) use ($today): void {
                $query->whereDate('tanggal_selesai', '>=', $today)
                    ->orWhereNull('tanggal_selesai');
            })
            ->count();
    }

    public function getTodayAttendanceCount(): int
    {
        return Absensi::query()
            ->whereDate('tanggal', Carbon::today())
            ->whereNotNull('waktu_masuk')
            ->count();
    }

    public function hasRevenueHistory(): bool
    {
        return SessionReport::query()
            ->where('status', SessionReport::STATUS_APPROVED)
            ->whereDate('report_date', '>=', now()->startOfMonth()->subMonthsNoOverflow(5))
            ->exists();
    }

    public function greeting(): string
    {
        $hour = (int) now()->format('H');

        return match (true) {
            $hour < 11 => 'Selamat pagi',
            $hour < 15 => 'Selamat siang',
            $hour < 19 => 'Selamat sore',
            default => 'Selamat malam',
        };
    }

    public function todayLabel(): string
    {
        return now()->locale('id')->translatedFormat('l, d F Y');
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

        $startLabel = $start->locale('id')->translatedFormat('d M');

        if ($end === null || $end->isSameDay($start)) {
            return $startLabel;
        }

        return $startLabel.' – '.$end->locale('id')->translatedFormat('d M');
    }
}
