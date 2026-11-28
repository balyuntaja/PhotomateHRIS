@php
    use App\Filament\Resources\SessionReportResource;
    use App\Utils\RupiahHelper;

    $statuses = $employeeData['operational_statuses'] ?? [];
    $attention = $employeeData['needs_attention'] ?? ['items' => collect(), 'total' => 0];
    $deadlineInfo = $employeeData['deadline_info'] ?? [];
    $recentReports = $employeeData['recent_reports'] ?? collect();

    $reportsUrl = SessionReportResource::getUrl('index');
    $canCreateReport = $user?->can('create', \App\Models\SessionReport::class) ?? false;
@endphp

<div class="pm-dashboard">
    {{-- Hero Pribadi Karyawan --}}
    <section class="pm-hero">
        <div class="pm-hero-content">
            <div>
                <span class="pm-hero-eyebrow">
                    <x-filament::icon icon="heroicon-m-sparkles" class="h-3.5 w-3.5" />
                    Workspace Karyawan
                </span>
                <h2 class="pm-hero-title">
                    {{ $this->greeting() }}, {{ $user?->nama_lengkap ?? 'Tim Photomate' }}
                </h2>
                <p class="pm-hero-subtitle">
                    Pantau status rekap sesi pribadi, revisi dari supervisor, dan batas waktu input Anda.
                </p>
            </div>

            <div class="pm-hero-actions">
                @if ($canCreateReport)
                    <a href="{{ SessionReportResource::getUrl('create') }}" class="pm-btn pm-btn-cream">
                        <x-filament::icon icon="heroicon-m-plus" class="h-4 w-4" />
                        Buat Rekap Sesi
                    </a>
                @endif

                <a href="{{ $reportsUrl }}" class="pm-btn pm-btn-ghost-light">
                    <x-filament::icon icon="heroicon-m-camera" class="h-4 w-4" />
                    Lihat Rekap Sesi
                </a>
            </div>
        </div>
    </section>

    {{-- Status Operasional Pribadi --}}
    <section>
        <div class="pm-section-head">
            <div>
                <h3 class="pm-section-title">Status Operasional</h3>
                <p class="pm-section-subtitle">
                    Ringkasan status seluruh rekap sesi milik Anda. Klik kartu untuk memfilter daftar Rekap Sesi.
                </p>
            </div>
            <a href="{{ $reportsUrl }}" class="pm-section-link">
                Semua Rekap Sesi
                <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5" />
            </a>
        </div>

        <div class="pm-status-grid">
            @foreach ($statuses as $status)
                @php
                    $isRevisionAlert = ! empty($status['emphasis']) && $status['count'] > 0;
                @endphp
                <a
                    href="{{ $status['url'] }}"
                    @class([
                        'pm-status-card',
                        'pm-status-card--' . $status['tone'],
                        'pm-status-card--revision-alert' => $isRevisionAlert,
                    ])
                >
                    <div class="pm-status-top">
                        <span class="pm-status-label flex items-center gap-1.5">
                            @if ($isRevisionAlert)
                                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-4 w-4 text-rose-600 dark:text-rose-400" />
                            @endif
                            <span>{{ $status['label'] }}</span>
                        </span>

                        @if ($isRevisionAlert)
                            <span class="inline-flex items-center rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-bold text-white">
                                Tindakan
                            </span>
                        @else
                            <span
                                class="pm-status-dot"
                                style="background-color: {{ $status['dot_color'] }}"
                            ></span>
                        @endif
                    </div>

                    <span class="pm-status-value">{{ number_format($status['count'], 0, ',', '.') }}</span>

                    <span class="pm-status-label flex items-center justify-between gap-2">
                        <span>{{ $status['hint'] }}</span>
                        <x-filament::icon icon="heroicon-m-chevron-right" class="h-3.5 w-3.5 opacity-60" />
                    </span>
                </a>
            @endforeach
        </div>
    </section>

    {{-- Perlu Perhatian + Informasi Deadline Rekap --}}
    <div class="grid gap-5 xl:grid-cols-3">
        {{-- Perlu Perhatian --}}
        <section class="pm-panel xl:col-span-2">
            <div class="pm-panel-header">
                <div>
                    <p class="pm-panel-title">Perlu Perhatian</p>
                    <p class="pm-panel-subtitle">
                        Prioritas tindakan pribadi: revisi supervisor, draft mendekati/melewati batas waktu, dan draft tertunda.
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <x-filament::badge :color="$attention['total'] > 0 ? 'warning' : 'success'">
                        {{ number_format($attention['total'], 0, ',', '.') }} item
                    </x-filament::badge>

                    <a href="{{ $reportsUrl }}" class="pm-section-link">
                        Buka rekap
                        <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5" />
                    </a>
                </div>
            </div>

            @if ($attention['items']->isEmpty())
                <div class="pm-empty">
                    <span class="pm-empty-icon">
                        <x-filament::icon icon="heroicon-o-check-badge" class="h-5 w-5" />
                    </span>
                    <p class="pm-empty-title">Semua pekerjaan Anda sudah tertangani</p>
                    <p class="pm-empty-text">
                        Tidak ada rekap revisi, draft yang mendekati batas waktu, maupun draft tertunda saat ini.
                    </p>
                </div>
            @else
                <div class="pm-attention-list">
                    @foreach ($attention['items'] as $item)
                        <div
                            @class([
                                'pm-attention-item',
                                'pm-attention-item--revision' => $item['is_revision'],
                                'pm-attention-item--' . $item['tone'] => ! $item['is_revision'],
                            ])
                        >
                            <div class="pm-attention-main">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-filament::badge
                                        :color="$item['status_color']"
                                        :icon="$item['is_revision'] ? 'heroicon-m-exclamation-triangle' : null"
                                    >
                                        {{ $item['status_label'] }}
                                    </x-filament::badge>

                                    <span class="pm-attention-title font-mono">{{ $item['number'] }}</span>
                                </div>

                                @if ($item['deadline_state'] === 'approaching')
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-bold text-amber-800 ring-1 ring-inset ring-amber-300 dark:bg-amber-950/70 dark:text-amber-300 dark:ring-amber-800">
                                        <x-filament::icon icon="heroicon-m-clock" class="h-3.5 w-3.5" />
                                        {{ $item['deadline_badge'] }}
                                    </span>
                                @elseif ($item['deadline_state'] === 'overdue')
                                    <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2.5 py-0.5 text-[11px] font-bold text-rose-700 ring-1 ring-inset ring-rose-300 dark:bg-rose-950/70 dark:text-rose-300 dark:ring-rose-800">
                                        <x-filament::icon icon="heroicon-m-exclamation-circle" class="h-3.5 w-3.5" />
                                        {{ $item['deadline_badge'] }}
                                    </span>
                                @elseif ($item['deadline_state'] === 'revision_exempt')
                                    <span class="inline-flex items-center gap-1 rounded-full bg-orange-100 px-2.5 py-0.5 text-[11px] font-semibold text-orange-800 dark:bg-orange-950/60 dark:text-orange-300">
                                        {{ $item['deadline_badge'] }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                        {{ $item['deadline_badge'] }}
                                    </span>
                                @endif
                            </div>

                            <div class="pm-attention-meta">
                                <span>Tanggal: <strong class="text-slate-700 dark:text-slate-200">{{ $item['date'] }}</strong></span>
                                <span aria-hidden="true">·</span>
                                <span>Cabang: <strong class="text-slate-700 dark:text-slate-200">{{ $item['branch'] }}</strong></span>
                                <span aria-hidden="true">·</span>
                                <span>Batas Input: {{ $item['deadline_text'] }}</span>
                            </div>

                            @if ($item['is_revision'] && filled($item['revision_note']))
                                <div class="rounded-lg border-l-4 border-rose-500 bg-rose-50/90 px-3 py-2 text-xs text-rose-900 dark:border-rose-500 dark:bg-rose-950/50 dark:text-rose-100">
                                    <span class="font-bold text-rose-700 dark:text-rose-300">Catatan Supervisor:</span>
                                    "{{ $item['revision_note'] }}"
                                </div>
                            @endif

                            <div class="flex flex-wrap items-center justify-between gap-2 pt-0.5">
                                <span class="text-[11px] text-slate-400 dark:text-slate-500">
                                    Diperbarui {{ $item['updated'] }}
                                </span>

                                <div class="flex flex-wrap items-center gap-2">
                                    <a
                                        href="{{ $item['detail_url'] }}"
                                        class="pm-btn pm-btn-xs pm-btn-secondary"
                                    >
                                        Lihat Detail
                                    </a>

                                    @if ($item['action_url'] !== $item['detail_url'])
                                        <a
                                            href="{{ $item['action_url'] }}"
                                            @class([
                                                'pm-btn pm-btn-xs',
                                                'pm-revision-cta-btn' => $item['is_revision'],
                                                'pm-btn-primary' => ! $item['is_revision'],
                                            ])
                                        >
                                            <x-filament::icon icon="heroicon-m-pencil-square" class="h-3.5 w-3.5" />
                                            {{ $item['action_label'] }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Informasi Deadline Rekap --}}
        <section class="pm-panel">
            <div class="pm-panel-header">
                <div>
                    <p class="pm-panel-title">Informasi Deadline Rekap</p>
                    <p class="pm-panel-subtitle">Ketentuan batas waktu input &amp; edit role Karyawan</p>
                </div>
                <span class="pm-avatar">
                    <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
                </span>
            </div>

            <div class="pm-panel-body space-y-3.5">
                <div class="rounded-xl border border-[#354B6D]/15 bg-[#354B6D]/[0.04] p-3.5 dark:border-slate-700 dark:bg-slate-800/60">
                    <span class="block text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        Aturan Batas Waktu
                    </span>
                    <p class="mt-1 text-sm font-bold text-[#354B6D] dark:text-[#F8E2B9]">
                        {{ $deadlineInfo['rule_text'] ?? 'Tanggal Rekap + 2 hari, pukul 23:59:59 WIB' }}
                    </p>
                </div>

                <div class="space-y-2.5 text-xs">
                    <div class="rounded-lg border border-amber-200/80 bg-amber-50/75 p-3 dark:border-amber-800/60 dark:bg-amber-950/30">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-amber-900 dark:text-amber-200">
                                Tanggal Terlama Terbuka
                            </span>
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-200/80 px-2 py-0.5 text-[10px] font-bold text-amber-900 dark:bg-amber-900 dark:text-amber-200">
                                <x-filament::icon icon="heroicon-m-clock" class="h-3 w-3" />
                                Mendekati Deadline
                            </span>
                        </div>
                        <p class="mt-1 font-bold text-slate-900 dark:text-white">
                            {{ $deadlineInfo['earliest_date_label'] ?? '-' }}
                        </p>
                        <p class="mt-0.5 text-[11px] text-amber-800 dark:text-amber-300">
                            Ditutup: <strong>{{ $deadlineInfo['earliest_deadline_label'] ?? '-' }}</strong>
                        </p>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-slate-50/80 p-3 dark:border-slate-700 dark:bg-slate-800/50">
                        <span class="block font-semibold text-slate-600 dark:text-slate-300">
                            Rekap Tanggal Hari Ini ({{ $deadlineInfo['today_date_label'] ?? '-' }})
                        </span>
                        <p class="mt-1 font-bold text-slate-900 dark:text-white">
                            Batas s/d {{ $deadlineInfo['today_deadline_label'] ?? '-' }}
                        </p>
                    </div>

                    @if (! empty($deadlineInfo['has_approaching']) || ! empty($deadlineInfo['has_overdue']))
                        <div class="rounded-lg border border-rose-200 bg-rose-50/80 p-3 dark:border-rose-800/60 dark:bg-rose-950/30">
                            <p class="font-bold text-rose-800 dark:text-rose-200">
                                Status Draft Anda
                            </p>
                            <ul class="mt-1 space-y-1 text-[11px] text-rose-700 dark:text-rose-300">
                                @if (! empty($deadlineInfo['has_approaching']))
                                    <li>• {{ $deadlineInfo['approaching_count'] }} draft mendekati batas waktu (≤ 24 jam).</li>
                                @endif
                                @if (! empty($deadlineInfo['has_overdue']))
                                    <li>• {{ $deadlineInfo['overdue_count'] }} draft sudah melewati batas input H+2.</li>
                                @endif
                            </ul>
                        </div>
                    @endif

                    <p class="rounded-lg bg-emerald-50/70 px-3 py-2 text-[11px] leading-relaxed text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300">
                        <strong>Catatan:</strong> Rekap berstatus <strong>Perlu Revisi</strong> dikecualikan dari batas H+2 hingga Anda mengirimkannya ulang.
                    </p>
                </div>
            </div>
        </section>
    </div>

    {{-- Rekap Sesi Terbaru (Max 5 milik karyawan) --}}
    <section class="pm-panel">
        <div class="pm-panel-header">
            <div>
                <p class="pm-panel-title">Rekap Sesi Terbaru</p>
                <p class="pm-panel-subtitle">Maksimal 5 rekap sesi terakhir milik Anda</p>
            </div>
            <a href="{{ $reportsUrl }}" class="pm-btn pm-btn-xs pm-btn-secondary">
                Lihat Semua
                <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5" />
            </a>
        </div>

        @if ($recentReports->isEmpty())
            <div class="pm-empty">
                <span class="pm-empty-icon">
                    <x-filament::icon icon="heroicon-o-camera" class="h-5 w-5" />
                </span>
                <p class="pm-empty-title">Belum ada rekap sesi</p>
                <p class="pm-empty-text">Rekap sesi yang Anda buat atau ikuti sebagai crew akan tampil di sini.</p>
                @if ($canCreateReport)
                    <a href="{{ SessionReportResource::getUrl('create') }}" class="pm-btn pm-btn-xs pm-btn-primary mt-2">
                        <x-filament::icon icon="heroicon-m-plus" class="h-3.5 w-3.5" />
                        Buat Rekap Sesi
                    </a>
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-xs dark:divide-slate-800">
                    <thead class="bg-slate-50/80 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">No Rekap</th>
                            <th class="px-4 py-3">Tanggal</th>
                            <th class="px-4 py-3">Cabang</th>
                            <th class="px-4 py-3 text-center">Total Sesi</th>
                            <th class="px-4 py-3 text-right">Total Nominal</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                        @foreach ($recentReports as $report)
                            <tr @class([
                                'transition hover:bg-slate-50/80 dark:hover:bg-white/[0.03]',
                                'pm-revision-row' => $report['is_revision'],
                            ])>
                                <td class="whitespace-nowrap px-4 py-3 font-mono font-bold text-[#354B6D] dark:text-slate-100">
                                    {{ $report['number'] }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-300">
                                    {{ $report['date'] }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-800 dark:text-slate-200">
                                    {{ $report['branch'] }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-center">
                                    <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-[11px] font-semibold text-sky-700 dark:bg-sky-950/60 dark:text-sky-300">
                                        {{ number_format($report['sessions_count'], 0, ',', '.') }} sesi
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-mono font-semibold text-slate-900 dark:text-slate-100">
                                    {{ $report['show_amount'] ? RupiahHelper::format($report['amount']) : '-' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <x-filament::badge
                                        :color="$report['status_color']"
                                        :icon="$report['is_revision'] ? 'heroicon-m-exclamation-triangle' : null"
                                    >
                                        {{ $report['status_label'] }}
                                    </x-filament::badge>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a
                                        href="{{ $report['detail_url'] }}"
                                        class="pm-btn pm-btn-xs pm-btn-secondary"
                                    >
                                        Lihat Detail
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
