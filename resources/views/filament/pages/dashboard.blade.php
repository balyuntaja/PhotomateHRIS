@php
    use App\Filament\Pages\KeuanganInputTransaksi;
    use App\Filament\Resources\EmployeeScheduleResource;
    use App\Filament\Resources\FinancialTransactionResource;
    use App\Filament\Resources\SessionApprovalResource;
    use App\Filament\Resources\SessionReportResource;
    use App\Utils\RupiahHelper;

    $user = auth()->user();
    $metrics = $this->getDashboardMetrics();
    $statuses = $this->getOperationalStatus();
    $attention = $this->getNeedsAttention();
    $schedules = $this->getTodaySchedules();
    $scheduleCount = $this->getTodayScheduleCount();
    $leaves = $this->getTodayLeaves();
    $leaveCount = $this->getTodayLeaveCount();
    $permissions = $this->getTodayPermissions();
    $permissionCount = $this->getTodayPermissionCount();
    $attendanceCount = $this->getTodayAttendanceCount();

    $reportsUrl = SessionReportResource::getUrl('index');
    $canCreateReport = $user?->can('create', \App\Models\SessionReport::class) ?? false;
    $canInputTransaction = $user?->isSuperAdmin() || ($user?->can('create_keuangan_transaction') ?? false);
    $canApprove = SessionApprovalResource::canAccess();
    $canViewTransactions = $user?->isSuperAdmin() || ($user?->can('view_any_keuangan_transaction') ?? false);

    $paymentTotal = $metrics['qris_revenue'] + $metrics['cash_revenue'];
    $totalPayment = max(1, $paymentTotal);
    $qrisShare = (int) round($metrics['qris_revenue'] / $totalPayment * 100);
    $cashShare = (int) round($metrics['cash_revenue'] / $totalPayment * 100);
@endphp

<x-filament-panels::page class="fi-dashboard-page">
    <div class="pm-dashboard">
        {{-- Hero --}}
        <section class="pm-hero">
            <div class="pm-hero-content">
                <div>
                    <span class="pm-hero-eyebrow">
                        <x-filament::icon icon="heroicon-m-sparkles" class="h-3.5 w-3.5" />
                        Operational overview
                    </span>
                    <h2 class="pm-hero-title">
                        {{ $this->greeting() }}, {{ $user?->nama_lengkap ?? 'Tim Photomate' }}
                    </h2>
                    <p class="pm-hero-subtitle">
                        Berikut ringkasan performa bisnis dan pekerjaan prioritas Anda hari ini.
                    </p>
                </div>

                <div class="pm-hero-actions">
                    @if ($canCreateReport)
                        <a href="{{ SessionReportResource::getUrl('create') }}" class="pm-btn pm-btn-cream">
                            <x-filament::icon icon="heroicon-m-plus" class="h-4 w-4" />
                            Buat Rekap Sesi
                        </a>
                    @endif

                    @if ($canInputTransaction)
                        <a href="{{ KeuanganInputTransaksi::getUrl() }}" class="pm-btn pm-btn-ghost-light">
                            <x-filament::icon icon="heroicon-m-banknotes" class="h-4 w-4" />
                            Input Transaksi
                        </a>
                    @endif

                    @if ($canApprove)
                        <a href="{{ SessionApprovalResource::getUrl('index') }}" class="pm-btn pm-btn-ghost-light">
                            <x-filament::icon icon="heroicon-m-check-badge" class="h-4 w-4" />
                            Review Approval
                            @if ($statuses[0]['count'] > 0)
                                <span class="inline-flex min-w-5 items-center justify-center rounded-full bg-amber-400 px-1.5 py-0.5 text-[10px] font-bold text-amber-950">
                                    {{ $statuses[0]['count'] }}
                                </span>
                            @endif
                        </a>
                    @endif
                </div>
            </div>
        </section>

        {{-- Business summary --}}
        <section>
            <div class="pm-section-head">
                <div>
                    <h3 class="pm-section-title">Ringkasan Bisnis</h3>
                    <p class="pm-section-subtitle">
                        Performa {{ $metrics['period_label'] }} berdasarkan rekap sesi yang telah disetujui.
                    </p>
                </div>
                <a href="{{ $reportsUrl }}" class="pm-section-link">
                    Lihat rekap
                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5" />
                </a>
            </div>

            <div class="pm-stat-grid">
                <div class="pm-stat-card pm-stat-card--primary">
                    <span class="pm-stat-label">
                        <x-filament::icon icon="heroicon-m-banknotes" class="h-3.5 w-3.5" />
                        Total Pendapatan
                    </span>
                    <p class="pm-stat-value">{{ RupiahHelper::format($metrics['revenue']) }}</p>
                    <p class="pm-stat-meta">
                        {{ number_format($metrics['transactions'], 0, ',', '.') }} transaksi ·
                        {{ RupiahHelper::format($metrics['average_per_session']) }} rata-rata/sesi
                    </p>
                    <span class="pm-stat-icon">
                        <x-filament::icon icon="heroicon-o-chart-bar" class="h-4 w-4" />
                    </span>
                </div>

                <div class="pm-stat-card">
                    <span class="pm-stat-label">
                        <x-filament::icon icon="heroicon-m-camera" class="h-3.5 w-3.5" />
                        Total Sesi
                    </span>
                    <p class="pm-stat-value">{{ number_format($metrics['sessions'], 0, ',', '.') }}</p>
                    <p class="pm-stat-meta">Sesi foto dari rekap approved</p>
                    <span class="pm-stat-icon">
                        <x-filament::icon icon="heroicon-o-camera" class="h-4 w-4" />
                    </span>
                </div>

                <div class="pm-stat-card">
                    <span class="pm-stat-label">
                        <x-filament::icon icon="heroicon-m-users" class="h-3.5 w-3.5" />
                        Karyawan
                    </span>
                    <p class="pm-stat-value">{{ number_format($metrics['employees'], 0, ',', '.') }}</p>
                    <p class="pm-stat-meta">Karyawan aktif terdaftar</p>
                    <span class="pm-stat-icon">
                        <x-filament::icon icon="heroicon-o-users" class="h-4 w-4" />
                    </span>
                </div>

                <div class="pm-stat-card">
                    <span class="pm-stat-label">
                        <x-filament::icon icon="heroicon-m-building-office-2" class="h-3.5 w-3.5" />
                        Cabang
                    </span>
                    <p class="pm-stat-value">{{ number_format($metrics['branches'], 0, ',', '.') }}</p>
                    <p class="pm-stat-meta">
                        {{ number_format($metrics['companies'], 0, ',', '.') }} perusahaan terdaftar
                    </p>
                    <span class="pm-stat-icon">
                        <x-filament::icon icon="heroicon-o-map-pin" class="h-4 w-4" />
                    </span>
                </div>
            </div>
        </section>

        {{-- Revenue chart + payment summary --}}
        <div class="grid gap-5 xl:grid-cols-3">
            <div class="pm-panel xl:col-span-2">
                <div class="pm-panel-header">
                    <div>
                        <p class="pm-panel-title">Tren Pendapatan</p>
                        <p class="pm-panel-subtitle">Rekap sesi approved · 6 bulan terakhir</p>
                    </div>
                    <span class="hidden rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-600 sm:inline-flex">
                        {{ $metrics['period_label'] }}
                    </span>
                </div>

                <div class="pm-chart-shell">
                    @if ($this->hasRevenueHistory())
                        @livewire(\App\Filament\Widgets\DashboardRevenueChart::class)
                    @else
                        <div class="pm-empty">
                            <span class="pm-empty-icon">
                                <x-filament::icon icon="heroicon-o-chart-bar" class="h-5 w-5" />
                            </span>
                            <p class="pm-empty-title">Belum ada tren pendapatan</p>
                            <p class="pm-empty-text">Grafik akan muncul setelah rekap sesi disetujui.</p>
                        </div>
                    @endif
                </div>
            </div>

            <div class="pm-panel">
                <div class="pm-panel-header">
                    <div>
                        <p class="pm-panel-title">Ringkasan Pembayaran</p>
                        <p class="pm-panel-subtitle">Komposisi pendapatan {{ $metrics['period_label'] }}</p>
                    </div>
                    @if ($canViewTransactions)
                        <a href="{{ FinancialTransactionResource::getUrl('index') }}" class="pm-section-link">
                            Transaksi
                            <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5" />
                        </a>
                    @endif
                </div>

                <div class="pm-panel-body">
                    <div class="pm-payment-row">
                        <div class="pm-payment-top">
                            <span class="pm-payment-label">
                                <x-filament::icon icon="heroicon-m-qr-code" class="h-4 w-4 text-sky-600" />
                                QRIS
                            </span>
                            <span class="pm-payment-amount">{{ RupiahHelper::format($metrics['qris_revenue']) }}</span>
                        </div>
                        <div class="pm-bar">
                            <div class="pm-bar-fill" style="width: {{ $qrisShare }}%"></div>
                        </div>
                        <span class="pm-payment-meta">
                            {{ $qrisShare }}% · {{ number_format($metrics['qris_sessions'], 0, ',', '.') }} sesi ·
                            {{ number_format($metrics['qris_transactions'], 0, ',', '.') }} transaksi
                        </span>
                    </div>

                    <div class="pm-payment-row">
                        <div class="pm-payment-top">
                            <span class="pm-payment-label">
                                <x-filament::icon icon="heroicon-m-banknotes" class="h-4 w-4 text-emerald-600" />
                                Tunai
                            </span>
                            <span class="pm-payment-amount">{{ RupiahHelper::format($metrics['cash_revenue']) }}</span>
                        </div>
                        <div class="pm-bar">
                            <div class="pm-bar-fill pm-bar-fill--cream" style="width: {{ $cashShare }}%"></div>
                        </div>
                        <span class="pm-payment-meta">
                            {{ $cashShare }}% · {{ number_format($metrics['cash_sessions'], 0, ',', '.') }} sesi ·
                            {{ number_format($metrics['cash_transactions'], 0, ',', '.') }} transaksi
                        </span>
                    </div>

                    <div class="mt-3 flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2.5 dark:bg-white/5">
                        <span class="text-xs font-medium text-slate-500 dark:text-slate-400">Total QRIS + tunai</span>
                        <span class="text-sm font-bold tabular-nums text-slate-900 dark:text-white">
                            {{ RupiahHelper::format($paymentTotal) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Operational status --}}
        <section>
            <div class="pm-section-head">
                <div>
                    <h3 class="pm-section-title">Status Operasional</h3>
                    <p class="pm-section-subtitle">
                        Antrean approval/revisi terkini dan status rekap {{ $metrics['period_label'] }}.
                    </p>
                </div>
            </div>

            <div class="pm-status-grid">
                @foreach ($statuses as $status)
                    <a href="{{ $status['url'] }}" class="pm-status-card pm-status-card--{{ $status['tone'] }}">
                        <div class="pm-status-top">
                            <span class="pm-status-label">{{ $status['label'] }}</span>
                            <span
                                class="pm-status-dot"
                                style="background-color: {{ match ($status['tone']) {
                                    'warning' => '#F59E0B',
                                    'danger' => '#F43F5E',
                                    'success' => '#10B981',
                                    default => '#94A3B8',
                                } }}"
                            ></span>
                        </div>
                        <span class="pm-status-value">{{ number_format($status['count'], 0, ',', '.') }}</span>
                        <span class="pm-status-label">{{ $status['hint'] }}</span>
                    </a>
                @endforeach
            </div>
        </section>

        {{-- Needs attention --}}
        <section class="pm-panel">
            <div class="pm-panel-header">
                <div>
                    <p class="pm-panel-title">Perlu Perhatian</p>
                    <p class="pm-panel-subtitle">
                        Pekerjaan yang menunggu tindakan Anda saat ini.
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <x-filament::badge :color="$attention['total'] > 0 ? 'warning' : 'success'">
                        {{ number_format($attention['total'], 0, ',', '.') }} item
                    </x-filament::badge>

                    @if ($canApprove)
                        <a href="{{ SessionApprovalResource::getUrl('index') }}" class="pm-section-link">
                            Buka approval
                            <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5" />
                        </a>
                    @endif
                </div>
            </div>

            @if ($attention['items']->isEmpty())
                <div class="pm-empty">
                    <span class="pm-empty-icon">
                        <x-filament::icon icon="heroicon-o-check-badge" class="h-5 w-5" />
                    </span>
                    <p class="pm-empty-title">Semua pekerjaan sudah tertangani</p>
                    <p class="pm-empty-text">
                        Tidak ada rekap yang menunggu approval atau revisi saat ini.
                    </p>
                </div>
            @else
                <div class="pm-attention-list">
                    @foreach ($attention['items'] as $item)
                        <div class="pm-attention-item pm-attention-item--{{ $item['tone'] }}">
                            <div class="pm-attention-main">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-filament::badge :color="$item['status_color']">
                                        {{ $item['status_label'] }}
                                    </x-filament::badge>
                                    <span class="pm-attention-title">{{ $item['number'] }}</span>
                                </div>
                                <span class="pm-attention-amount">{{ RupiahHelper::format($item['amount']) }}</span>
                            </div>

                            <div class="pm-attention-meta">
                                <span>{{ $item['date'] }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ $item['branch'] }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ $item['crew'] }}</span>
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-[11px] text-slate-400 dark:text-slate-500">
                                    Diperbarui {{ $item['updated'] }}
                                </span>
                                <a href="{{ $item['url'] }}" class="pm-section-link">
                                    {{ $item['action'] }}
                                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5" />
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Daily activities --}}
        <section>
            <div class="pm-section-head">
                <div>
                    <h3 class="pm-section-title">Aktivitas Hari Ini</h3>
                    <p class="pm-section-subtitle">
                        {{ $this->todayLabel() }} · {{ number_format($attendanceCount, 0, ',', '.') }} karyawan sudah absen
                    </p>
                </div>
            </div>

            <div class="grid gap-4 xl:grid-cols-3">
                {{-- Schedules --}}
                <div class="pm-panel">
                    <div class="pm-panel-header">
                        <div>
                            <p class="pm-panel-title">Jadwal Karyawan</p>
                            <p class="pm-panel-subtitle">{{ $scheduleCount }} shift hari ini</p>
                        </div>
                        @if (EmployeeScheduleResource::canAccess())
                            <a href="{{ EmployeeScheduleResource::getUrl('index') }}" class="pm-section-link">
                                Kelola
                                <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5" />
                            </a>
                        @endif
                    </div>

                    @if ($schedules->isEmpty())
                        <div class="pm-empty">
                            <span class="pm-empty-icon">
                                <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
                            </span>
                            <p class="pm-empty-title">Belum ada jadwal</p>
                            <p class="pm-empty-text">Jadwal shift hari ini belum diinput.</p>
                        </div>
                    @else
                        <div class="pm-activity-list">
                            @foreach ($schedules as $schedule)
                                <div class="pm-activity-item">
                                    <span class="pm-avatar">{{ $schedule['initials'] }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="pm-activity-title truncate">{{ $schedule['name'] }}</p>
                                        <p class="pm-activity-meta">
                                            {{ $schedule['shift'] ?: '-' }}
                                            @if ($schedule['location'])
                                                · {{ $schedule['location'] }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Cuti --}}
                <div class="pm-panel">
                    <div class="pm-panel-header">
                        <div>
                            <p class="pm-panel-title">Karyawan Cuti</p>
                            <p class="pm-panel-subtitle">{{ $leaveCount }} karyawan cuti hari ini</p>
                        </div>
                        <span class="pm-avatar">C</span>
                    </div>

                    @if ($leaves->isEmpty())
                        <div class="pm-empty">
                            <span class="pm-empty-icon">
                                <x-filament::icon icon="heroicon-o-sun" class="h-5 w-5" />
                            </span>
                            <p class="pm-empty-title">Tidak ada cuti</p>
                            <p class="pm-empty-text">Semua karyawan masuk sesuai jadwal.</p>
                        </div>
                    @else
                        <div class="pm-activity-list">
                            @foreach ($leaves as $leave)
                                <div class="pm-activity-item">
                                    <span class="pm-avatar">{{ $leave['initials'] }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="pm-activity-title truncate">{{ $leave['name'] }}</p>
                                        <p class="pm-activity-meta">{{ $leave['period'] }}</p>
                                    </div>
                                    @if ($leave['type'])
                                        <x-filament::badge color="gray">{{ $leave['type'] }}</x-filament::badge>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Izin --}}
                <div class="pm-panel">
                    <div class="pm-panel-header">
                        <div>
                            <p class="pm-panel-title">Karyawan Izin</p>
                            <p class="pm-panel-subtitle">{{ $permissionCount }} karyawan izin hari ini</p>
                        </div>
                        <span class="pm-avatar">I</span>
                    </div>

                    @if ($permissions->isEmpty())
                        <div class="pm-empty">
                            <span class="pm-empty-icon">
                                <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5" />
                            </span>
                            <p class="pm-empty-title">Tidak ada izin</p>
                            <p class="pm-empty-text">Tidak ada pengajuan izin yang aktif hari ini.</p>
                        </div>
                    @else
                        <div class="pm-activity-list">
                            @foreach ($permissions as $permission)
                                <div class="pm-activity-item">
                                    <span class="pm-avatar">{{ $permission['initials'] }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="pm-activity-title truncate">{{ $permission['name'] }}</p>
                                        <p class="pm-activity-meta">{{ $permission['period'] }}</p>
                                    </div>
                                    @if ($permission['type'])
                                        <x-filament::badge color="gray">{{ $permission['type'] }}</x-filament::badge>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </section>
    </div>
</x-filament-panels::page>
