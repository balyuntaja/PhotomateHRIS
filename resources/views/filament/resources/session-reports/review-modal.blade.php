@php
    $report = $record ?? (isset($getRecord) ? $getRecord() : ($this->record ?? null));
    $transactions = $report ? $report->transactions : collect();
    $logs = $report ? $report->approvalLogs : collect();

    $viewer = \Illuminate\Support\Facades\Auth::user();
    $viewerIsCrew = $viewer instanceof \App\Models\Karyawan
        && !app(\App\Services\SessionWorkflowService::class)->isSupervisorOrAdmin($viewer);
    $inputDeadline = $report && $viewerIsCrew ? $report->inputDeadlineDescription() : null;
    $inputClosed = $inputDeadline && $report->isPastInputDeadline();
@endphp

@if($report)
<div class="space-y-5 text-sm text-gray-800 dark:text-gray-200">
    <!-- Header info -->
    <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 space-y-3">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; padding-bottom: 8px; border-bottom: 1px solid rgba(156, 163, 175, 0.2);">
            <div>
                <span class="text-xs uppercase tracking-wider text-gray-500 font-bold">Nomor Rekap</span>
                <div class="font-mono font-bold text-base text-primary-600 dark:text-primary-400">{{ $report->report_number }}</div>
            </div>
            <div style="text-align: right;">
                <span class="text-xs uppercase tracking-wider text-gray-500 font-bold block mb-1">Status</span>
                @php
                    $badgeClasses = match($report->status) {
                        'APPROVED' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
                        'WAITING_APPROVAL' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
                        'REVISION' => 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
                        default => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300',
                    };
                @endphp
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $badgeClasses }}">
                    {{ $report->status_label }}
                </span>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;" class="text-xs">
            <div>
                <span class="text-gray-500 block">Tanggal Event/Sesi:</span>
                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $report->report_date ? $report->report_date->translatedFormat('d F Y') : '-' }}</span>
            </div>
            <div>
                <span class="text-gray-500 block">Cabang Photomate:</span>
                <span class="font-semibold text-primary-600 dark:text-primary-400">{{ $report->cabang?->nama_cabang ?? '-' }}</span>
            </div>
            <div>
                <span class="text-gray-500 block">Crew Bertugas:</span>
                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $report->crew_names }}</span>
            </div>
            <div>
                <span class="text-gray-500 block">Diajukan Oleh:</span>
                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $report->submitter?->nama_lengkap ?? '-' }}</span>
            </div>
            <div>
                <span class="text-gray-500 block">Waktu Pengajuan:</span>
                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $report->submitted_at ? $report->submitted_at->translatedFormat('d F Y, H:i') : '-' }}</span>
            </div>
        </div>
    </div>

    @if($inputDeadline)
    <div class="p-3.5 border-l-4 rounded-r-lg {{ $inputClosed ? 'bg-rose-50 dark:bg-rose-950/40 border-rose-500' : 'bg-sky-50 dark:bg-sky-950/40 border-sky-500' }}">
        <h4 class="font-bold flex items-center gap-1.5 text-xs {{ $inputClosed ? 'text-rose-800 dark:text-rose-300' : 'text-sky-800 dark:text-sky-300' }}">
            <svg class="w-4 h-4 shrink-0 {{ $inputClosed ? 'text-rose-600 dark:text-rose-400' : 'text-sky-600 dark:text-sky-400' }}" fill="currentColor" viewBox="0 0 20 20">
                @if($inputClosed)
                    <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
                @else
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
                @endif
            </svg>
            {{ $inputClosed ? 'Rekap Sudah Ditutup' : 'Periode Input Rekap Masih Terbuka' }}
        </h4>
        <p class="mt-1 text-xs font-medium {{ $inputClosed ? 'text-rose-700 dark:text-rose-200' : 'text-sky-700 dark:text-sky-200' }}">
            Batas input dan perubahan rekap untuk tanggal {{ $report->report_date->translatedFormat('d F Y') }} adalah {{ $inputDeadline }}.
        </p>
    </div>
    @endif

    @if($report->status === 'REVISION' && $report->revision_note)
    <div class="p-3.5 bg-rose-50 dark:bg-rose-950/40 border-l-4 border-rose-500 rounded-r-lg">
        <h4 class="font-bold text-rose-800 dark:text-rose-300 flex items-center gap-1.5 text-xs">
            <svg class="w-4 h-4 text-rose-600 dark:text-rose-400 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
            Catatan Revisi Supervisor:
        </h4>
        <p class="text-rose-700 dark:text-rose-200 mt-1 text-xs font-medium">{{ $report->revision_note }}</p>
    </div>
    @endif

    <!-- Session Summary -->
    <div>
        <h3 class="text-xs uppercase tracking-wider font-bold text-gray-500 dark:text-gray-400 mb-2">Ringkasan Sesi & Pendapatan</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;">
            <div class="p-3 bg-gray-50 dark:bg-gray-800/80 rounded-xl border border-gray-200 dark:border-gray-700">
                <span class="text-xs text-gray-500 block">Total Lembar</span>
                <div class="text-base sm:text-lg font-bold text-gray-900 dark:text-gray-100">{{ number_format($report->total_sessions) }} <span class="text-xs font-normal text-gray-500">Lembar</span></div>
                <span class="text-[11px] text-gray-400">{{ $report->total_transactions }} Sesi</span>
            </div>
            <div class="p-3 bg-gray-50 dark:bg-gray-800/80 rounded-xl border border-gray-200 dark:border-gray-700">
                <span class="text-xs text-gray-500 block">Tunai (Cash)</span>
                <div class="text-base sm:text-lg font-bold text-emerald-600 dark:text-emerald-400 font-mono">Rp {{ number_format($report->total_cash_amount, 0, ',', '.') }}</div>
                <span class="text-[11px] text-gray-400">{{ $report->total_cash_sessions }} Lembar tunai</span>
            </div>
            <div class="p-3 bg-gray-50 dark:bg-gray-800/80 rounded-xl border border-gray-200 dark:border-gray-700">
                <span class="text-xs text-gray-500 block">QRIS</span>
                <div class="text-base sm:text-lg font-bold text-purple-600 dark:text-purple-400 font-mono">Rp {{ number_format($report->total_qris_amount, 0, ',', '.') }}</div>
                <span class="text-[11px] text-gray-400">{{ $report->total_qris_sessions }} Lembar non-tunai</span>
            </div>
            <div class="p-3 bg-primary-50 dark:bg-primary-950/40 rounded-xl border border-primary-200 dark:border-primary-800/60">
                <span class="text-xs text-primary-700 dark:text-primary-300 font-medium block">Total Revenue</span>
                <div class="text-base sm:text-lg font-bold text-primary-700 dark:text-primary-300 font-mono">Rp {{ number_format($report->grand_total_amount, 0, ',', '.') }}</div>
                <span class="text-[11px] text-primary-600/80 dark:text-primary-400/80">{{ $report->total_sessions }} Total lembar</span>
            </div>
        </div>
    </div>

    <!-- Bonus Crew Newspaper Janus (Khusus Cabang Newspaper Janus) -->
    @if($report->isNewspaperJanus())
    @php
        $bonusDetails = $report->getBonusDetails();
        $isTargetReached = $bonusDetails['target_reached'];
        $totalBonus = $bonusDetails['total_bonus'];
        $bonusPerCrew = $bonusDetails['bonus_per_crew'];
        $crewBreakdown = $bonusDetails['crew_breakdown'];
        $crewCount = $bonusDetails['crew_count'];
    @endphp
    <div>
        <h3 class="text-xs uppercase tracking-wider font-bold text-gray-500 dark:text-gray-400 mb-2">Bonus Crew Cabang Newspaper Janus</h3>
        @if($isTargetReached)
        <div class="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-800 space-y-3">
            <div class="flex items-center justify-between flex-wrap gap-2">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-600 text-white font-bold text-xs">✓</span>
                    <span class="font-bold text-sm text-emerald-800 dark:text-emerald-200">TARGET HARIAN TERCAPAI (≥ 30 SESI)</span>
                </div>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200 border border-emerald-300">
                    Bonus Aktif Rp 50.000
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2 border-t border-emerald-200 dark:border-emerald-800/60 text-xs">
                <div class="p-2.5 bg-white/70 dark:bg-gray-900/50 rounded-lg border border-emerald-200 dark:border-emerald-800/40">
                    <span class="text-gray-500 block">Total Sesi Hari Ini:</span>
                    <span class="text-base font-bold text-gray-900 dark:text-gray-100">{{ $bonusDetails['day_total_sessions'] }} Sesi</span>
                    <span class="text-[10px] text-emerald-600 block">(Target: ≥ 30 sesi)</span>
                </div>
                <div class="p-2.5 bg-white/70 dark:bg-gray-900/50 rounded-lg border border-emerald-200 dark:border-emerald-800/40">
                    <span class="text-gray-500 block">Total Bonus Cabang:</span>
                    <span class="text-base font-bold font-mono text-emerald-600 dark:text-emerald-400">Rp {{ number_format($totalBonus, 0, ',', '.') }}</span>
                    <span class="text-[10px] text-gray-400 block">Untuk crew bertugas</span>
                </div>
                <div class="p-2.5 bg-white/70 dark:bg-gray-900/50 rounded-lg border border-emerald-200 dark:border-emerald-800/40">
                    <span class="text-gray-500 block">Bonus per Crew ({{ $crewCount }} orang):</span>
                    <span class="text-base font-bold font-mono text-emerald-600 dark:text-emerald-400">Rp {{ number_format($bonusPerCrew, 0, ',', '.') }}</span>
                    <span class="text-[10px] text-gray-400 block">
                        @if($crewCount === 1)
                            (1 crew: Rp 50.000)
                        @elseif($crewCount === 2)
                            (Rp 50.000 ÷ 2 = Rp 25.000)
                        @elseif($crewCount > 2)
                            (Rp 50.000 ÷ {{ $crewCount }})
                        @else
                            -
                        @endif
                    </span>
                </div>
            </div>

            @if(!empty($crewBreakdown))
            <div class="pt-2">
                <span class="text-xs font-semibold text-emerald-900 dark:text-emerald-100 block mb-1.5">Penghasilan Bonus per Crew Bertugas:</span>
                <div class="overflow-x-auto rounded-lg border border-emerald-200 dark:border-emerald-800 bg-white dark:bg-gray-900">
                    <table class="min-w-full divide-y divide-emerald-200 dark:divide-emerald-800 text-xs">
                        <thead class="bg-emerald-50/70 dark:bg-emerald-950/50 text-emerald-900 dark:text-emerald-200 font-semibold">
                            <tr>
                                <th class="px-3 py-1.5 text-left">Nama Crew</th>
                                <th class="px-3 py-1.5 text-center">Status</th>
                                <th class="px-3 py-1.5 text-right">Bonus Diterima</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-emerald-100 dark:divide-emerald-900/50">
                            @foreach($crewBreakdown as $c)
                            <tr>
                                <td class="px-3 py-1.5 font-medium text-gray-900 dark:text-gray-100">{{ $c['nama_lengkap'] }}</td>
                                <td class="px-3 py-1.5 text-center text-emerald-600 font-semibold">Bertugas</td>
                                <td class="px-3 py-1.5 text-right font-mono font-bold text-emerald-600 dark:text-emerald-400">Rp {{ number_format($c['bonus'], 0, ',', '.') }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif
        </div>
        @else
        <div class="p-3.5 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 text-xs text-amber-900 dark:text-amber-100 space-y-1.5">
            <div class="flex items-center justify-between flex-wrap gap-2">
                <div class="flex items-center gap-1.5 font-bold text-amber-800 dark:text-amber-200">
                    <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-amber-400 text-amber-900 font-bold text-xs">!</span>
                    <span>BELUM MENCAPAI TARGET BONUS ({{ $report->total_transactions }} / 30 SESI)</span>
                </div>
                <span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200">
                    Bonus: Rp 0
                </span>
            </div>
            <p class="text-amber-700 dark:text-amber-300">
                Target 30 sesi belum terpenuhi (kurang <strong>{{ max(0, 30 - $report->total_transactions) }} sesi lagi</strong>). Bonus total Rp 50.000 hanya aktif apabila sesi pada hari tersebut mencapai minimal 30 sesi.
            </p>
        </div>
        @endif
    </div>
    @endif

    <!-- Detail Transaksi -->
    <div>
        <h3 class="text-xs uppercase tracking-wider font-bold text-gray-500 dark:text-gray-400 mb-2">Detail Transaksi ({{ $transactions->count() }})</h3>
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-left text-xs">
                <thead class="bg-gray-50 dark:bg-gray-800 font-semibold text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-2.5 w-12 text-center">No</th>
                        <th class="px-3 py-2.5">Metode</th>
                        <th class="px-3 py-2.5 text-center">Jumlah Sesi</th>
                        <th class="px-3 py-2.5 text-right">Nominal</th>
                        <th class="px-3 py-2.5">Catatan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800 bg-white dark:bg-gray-900">
                    @forelse($transactions as $index => $trx)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td class="px-3 py-2 text-center text-gray-500">{{ $index + 1 }}</td>
                        <td class="px-3 py-2 font-medium">
                            @if($trx->payment_method === 'QRIS')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-purple-100 text-purple-800 dark:bg-purple-900/60 dark:text-purple-300">QRIS</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300">Tunai</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-center font-semibold text-gray-900 dark:text-gray-100">{{ $trx->session_count }}</td>
                        <td class="px-3 py-2 text-right font-mono font-medium text-gray-900 dark:text-gray-100">
                            Rp {{ number_format($trx->amount, 0, ',', '.') }}
                        </td>
                        <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $trx->notes ?: '-' }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-4 py-4 text-center text-gray-500">Belum ada transaksi</td>
                    </tr>
                    @endforelse
                </tbody>
                <tfoot class="bg-gray-50 dark:bg-gray-800 font-bold border-t border-gray-200 dark:border-gray-700">
                    <tr>
                        <td colspan="2" class="px-3 py-2.5 text-right">Total:</td>
                        <td class="px-3 py-2.5 text-center text-gray-900 dark:text-gray-100">{{ $report->total_sessions }} Lembar</td>
                        <td class="px-3 py-2.5 text-right font-mono text-primary-600 dark:text-primary-400">
                            Rp {{ number_format($report->grand_total_amount, 0, ',', '.') }}
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Validasi Otomatis Sistem -->
    <div>
        <h3 class="text-xs uppercase tracking-wider font-bold text-gray-500 dark:text-gray-400 mb-2">Validasi Otomatis Sistem</h3>
        @if($report->validation_status === 'VALID')
        <div style="background-color: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 10px; padding: 10px 14px;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;">
                <div style="display: flex; align-items: center; gap: 7px;">
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 50%; background-color: #10b981; color: white; font-size: 11px; font-weight: bold; flex-shrink: 0;">✓</span>
                    <span style="font-weight: 700; font-size: 12px; color: #065f46;">STATUS VALID</span>
                    <span style="font-size: 12px; color: #047857;">— Semua data transaksi terverifikasi sistem</span>
                </div>
                <span style="display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 700; background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0;">
                    Lolos Verifikasi
                </span>
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                <span style="display: inline-flex; align-items: center; gap: 5px; background-color: #ffffff; border: 1px solid #a7f3d0; border-radius: 6px; padding: 3px 9px; font-size: 11px; color: #065f46;">
                    <span style="color: #10b981; font-weight: bold;">✓</span> Metode Pembayaran (Cash/QRIS)
                </span>
                <span style="display: inline-flex; align-items: center; gap: 5px; background-color: #ffffff; border: 1px solid #a7f3d0; border-radius: 6px; padding: 3px 9px; font-size: 11px; color: #065f46;">
                    <span style="color: #10b981; font-weight: bold;">✓</span> Jumlah Sesi (&ge; 1 Sesi)
                </span>
                <span style="display: inline-flex; align-items: center; gap: 5px; background-color: #ffffff; border: 1px solid #a7f3d0; border-radius: 6px; padding: 3px 9px; font-size: 11px; color: #065f46;">
                    <span style="color: #10b981; font-weight: bold;">✓</span> Pricing Rule Photomate
                </span>
            </div>
        </div>
        @else
        <div style="background-color: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 10px; padding: 10px 14px;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 6px;">
                <div style="display: flex; align-items: center; gap: 7px;">
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 50%; background-color: #f59e0b; color: white; font-size: 11px; font-weight: bold; flex-shrink: 0;">!</span>
                    <span style="font-weight: 700; font-size: 12px; color: #92400e;">PERHATIAN</span>
                    <span style="font-size: 12px; color: #b45309;">— Terdapat catatan validasi sistem</span>
                </div>
                <span style="display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 700; background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a;">
                    Perlu Tinjauan
                </span>
            </div>
            <div style="display: flex; flex-direction: column; gap: 4px; padding-left: 25px;">
                @foreach($report->validation_issues ?? [] as $issue)
                <div style="font-size: 11px; color: #92400e; display: flex; align-items: center; gap: 6px;">
                    <span style="color: #d97706; font-weight: bold;">•</span>
                    <span>{{ $issue }}</span>
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    <!-- Audit Trail & Riwayat Approval -->
    @if($logs->isNotEmpty())
    <div>
        <h3 class="text-xs uppercase tracking-wider font-bold text-gray-500 dark:text-gray-400 mb-2">Audit Trail & Riwayat Approval</h3>
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-left text-xs">
                <thead class="bg-gray-50 dark:bg-gray-800 font-semibold text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-2.5 whitespace-nowrap" style="width: 170px;">Waktu</th>
                        <th class="px-3 py-2.5 whitespace-nowrap" style="width: 190px;">Aksi</th>
                        <th class="px-3 py-2.5 whitespace-nowrap" style="width: 160px;">Oleh</th>
                        <th class="px-3 py-2.5">Catatan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800 bg-white dark:bg-gray-900">
                    @foreach($logs as $log)
                    @php
                        $badgeClasses = match($log->action) {
                            'APPROVED' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
                            'SUBMITTED', 'RESUBMITTED' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
                            'REVISION_REQUESTED', 'REJECTED' => 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
                            'DRAFT_UPDATED' => 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
                            'DRAFT_CREATED' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300',
                            default => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300',
                        };

                        $userName = $log->user?->nama_lengkap ?? ($log->user_id ? 'Karyawan #' . $log->user_id : 'Sistem');
                    @endphp
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td class="px-3 py-2 text-gray-500 dark:text-gray-400 whitespace-nowrap">
                            {{ $log->created_at->translatedFormat('d M Y, H:i') }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold {{ $badgeClasses }}">
                                {{ $log->action_label }}
                            </span>
                        </td>
                        <td class="px-3 py-2 font-medium text-gray-900 dark:text-gray-100 whitespace-nowrap">
                            {{ $userName }}
                        </td>
                        <td class="px-3 py-2 text-gray-600 dark:text-gray-300">
                            @if($log->note)
                                @if($log->action === 'REVISION_REQUESTED')
                                    <span class="text-rose-700 dark:text-rose-300 font-medium">"{{ $log->note }}"</span>
                                @else
                                    <span class="italic">"{{ $log->note }}"</span>
                                @endif
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
@endif
