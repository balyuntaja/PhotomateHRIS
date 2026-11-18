<?php

namespace App\Filament\Pages;

use App\Filament\Resources\SessionApprovalResource;
use App\Filament\Resources\SessionReportResource;
use App\Filament\Widgets\SessionOverviewWidget;
use App\Models\Absensi;
use App\Models\Cabang;
use App\Models\Cuti;
use App\Models\EmployeeSchedule;
use App\Models\FinancialTransaction;
use App\Models\Izin;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\SessionReport;
use App\Services\BranchInputModeService;
use App\Services\SessionReportFinancialService;
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
        $widgetData = $widget->getViewData();

        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        // 1. Total Pendapatan & Ringkasan Pembayaran dari Transaksi Keuangan (Pendapatan Booth)
        $boothTransactions = FinancialTransaction::query()
            ->boothIncome()
            ->periode($start, $end)
            ->with('paymentMethod')
            ->get();

        $revenue = (int) $boothTransactions->sum('amount');
        $financialTransactionsCount = $boothTransactions->count();

        $qrisRevenue = 0;
        $qrisTransactions = 0;
        $cashRevenue = 0;
        $cashTransactions = 0;
        $otherRevenue = 0;
        $otherTransactions = 0;

        // Ambil referensi rekap sesi jika ada transaksi hasil konsolidasi rekap sesi
        $sessionReportSourceIds = $boothTransactions
            ->where('source_type', SessionReportFinancialService::SOURCE)
            ->pluck('source_id')
            ->filter()
            ->unique();

        $sessionReports = $sessionReportSourceIds->isNotEmpty()
            ? SessionReport::query()->whereIn('id', $sessionReportSourceIds)->get()->keyBy(fn ($r) => (string) $r->id)
            : collect();

        foreach ($boothTransactions as $trx) {
            $methodName = $trx->paymentMethod ? strtoupper(trim($trx->paymentMethod->name)) : null;

            if ($methodName === 'QRIS') {
                $qrisRevenue += (int) $trx->amount;
                $qrisTransactions += 1;
            } elseif (in_array($methodName, ['CASH', 'TUNAI'], true)) {
                $cashRevenue += (int) $trx->amount;
                $cashTransactions += 1;
            } elseif ($methodName !== null) {
                $otherRevenue += (int) $trx->amount;
                $otherTransactions += 1;
            } else {
                // payment_method_id null: cek apakah hasil konsolidasi rekap sesi
                $report = $sessionReports->get((string) $trx->source_id);
                if ($report) {
                    $repQris = (int) $report->total_qris_amount;
                    $repCash = (int) $report->total_cash_amount;
                    $repOther = max(0, (int) $trx->amount - ($repQris + $repCash));

                    $qrisRevenue += $repQris;
                    $cashRevenue += $repCash;
                    $otherRevenue += $repOther;

                    if ($repQris > 0) {
                        $qrisTransactions += max(1, (int) $report->total_qris_transactions);
                    }
                    if ($repCash > 0) {
                        $cashTransactions += max(1, (int) $report->total_cash_transactions);
                    }
                    if ($repOther > 0) {
                        $otherTransactions += 1;
                    }
                } elseif ($trx->source_type === 'luminash') {
                    $qrisRevenue += (int) $trx->amount;
                    $qrisTransactions += 1;
                } else {
                    $otherRevenue += (int) $trx->amount;
                    $otherTransactions += 1;
                }
            }
        }

        $effectiveTotalTransactions = max(
            $financialTransactionsCount,
            $qrisTransactions + $cashTransactions + $otherTransactions
        );

        // 2. Total Sesi dari Rekap Sesi yang sudah Approved
        $approvedReports = SessionReport::query()
            ->where('status', SessionReport::STATUS_APPROVED)
            ->whereBetween('report_date', [$start->toDateString(), $end->toDateString()])
            ->with('cabang')
            ->get();

        $inputModeService = app(BranchInputModeService::class);
        $totalSessions = 0;

        foreach ($approvedReports as $report) {
            if ($report->cabang && $inputModeService->usesManualSessionInput($report->cabang)) {
                $totalSessions += (int) ($report->wedding_total_sessions ?: $report->total_transactions ?: $report->total_sessions);
            } else {
                $detailSessionSum = (int) $report->transactions()->sum('session_count');
                $totalSessions += max((int) $report->total_sessions, $detailSessionSum);
            }
        }

        return [
            'period_label' => MonthHelper::formatPeriod((int) now()->month, (int) now()->year),
            'revenue' => $revenue,
            'sessions' => $totalSessions,
            'transactions' => $effectiveTotalTransactions,
            'qris_revenue' => $qrisRevenue,
            'qris_transactions' => $qrisTransactions,
            'cash_revenue' => $cashRevenue,
            'cash_transactions' => $cashTransactions,
            'other_revenue' => $otherRevenue,
            'other_transactions' => $otherTransactions,
            'employees' => Karyawan::query()->count(),
            'branches' => Cabang::query()->count(),
            'companies' => Perusahaan::query()->count(),
            'average_per_session' => $totalSessions > 0
                ? (int) round($revenue / $totalSessions)
                : 0,
            'waiting_approval' => (int) $widgetData['waitingApprovalCount'],
            'revision' => (int) $widgetData['revisionCount'],
            'approved' => (int) $widgetData['approvedCount'],
            'draft' => (int) $widgetData['draftCount'],
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
        return FinancialTransaction::query()
            ->boothIncome()
            ->whereDate('transaction_date', '>=', now()->startOfMonth()->subMonthsNoOverflow(5))
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
