@php
    $record = isset($getRecord) ? $getRecord() : ($record ?? null);
    if ($record) {
        $record->loadMissing(['recaps.allocations', 'recaps.sessionReport.cabang', 'recaps.sessionReport.crews', 'invoices', 'auditLogs.user']);
        $hppService = app(\App\Services\ExpressEventHppService::class);
        $preview = $hppService->calculatePreview($record);
        $allocationService = app(\App\Services\ExpressEventAllocationService::class);
    } else {
        $preview = ['period_breakdown' => []];
    }
@endphp

<div class="space-y-6">
    @if ($record && $record->needs_admin_verification)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700/70 dark:bg-amber-950/30 dark:text-amber-200">
            <div class="font-bold">⚠️ Perlu Verifikasi Admin</div>
            <p class="mt-1 text-xs text-amber-800 dark:text-amber-300">
                {{ $record->verification_note ?: 'Data historis event ini belum memiliki identitas event operasional unik yang cukup. Sistem tidak melakukan penggabungan otomatis — silakan periksa rekap sesi terkait lalu klik "Tandai Terverifikasi" atau sesuaikan melalui Edit Event.' }}
            </p>
        </div>
    @endif

    {{-- A. Alokasi Rekap Sesi Express --}}
    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Alokasi Rekap Sesi Express</h3>
                <p class="text-xs text-gray-500">Daftar seluruh rekap sesi operasional yang terhubung secara individual (hanya rekap berstatus Approved yang masuk ke perhitungan HPP)</p>
            </div>
            <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                {{ $record->recaps->where('status', 'active')->count() }} Rekap Terhubung
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-gray-600 dark:text-gray-300">
                <thead class="border-b border-gray-200 bg-gray-50 text-xs uppercase text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-3">Nomor Rekap</th>
                        <th class="px-4 py-3">Tanggal Operasional</th>
                        <th class="px-4 py-3">Jenis Express</th>
                        <th class="px-4 py-3">Status Approval</th>
                        <th class="px-4 py-3 text-right">Jumlah Sesi</th>
                        <th class="px-4 py-3 text-right">Jumlah Lembar</th>
                        <th class="px-4 py-3 text-right">Alokasi Omzet</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    @forelse ($record->recaps->where('status', 'active') as $recap)
                        @php
                            $report = $recap->sessionReport;
                            $alloc = $recap->latestAllocation;
                            $isApproved = $report && $report->status === \App\Models\SessionReport::STATUS_APPROVED;
                            $branchLabel = $report?->cabang?->nama_cabang ?? '-';
                        @endphp
                        <tr class="{{ $isApproved ? 'hover:bg-gray-50/50 dark:hover:bg-gray-800/50' : 'opacity-65 bg-amber-50/30 dark:bg-amber-950/10' }}">
                            <td class="px-4 py-3">
                                <div class="font-mono font-semibold text-gray-900 dark:text-gray-100">{{ $report?->report_number ?? '-' }}</div>
                                <div class="text-xs text-gray-500">Crew: {{ $report?->crew_names ?? '-' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-xs font-medium text-gray-900 dark:text-gray-100">
                                    {{ $report?->report_date?->format('d/m/Y') ?? '-' }}
                                </div>
                                @if ($report?->event_date && $report->event_date->toDateString() !== $report?->report_date?->toDateString())
                                    <div class="text-[11px] text-gray-500">Tgl Event: {{ $report->event_date->format('d/m/Y') }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                                    {{ $branchLabel }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                @if ($isApproved)
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                                        Approved
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                        {{ $report?->status_label ?? 'Belum Approved' }} (Tidak Dihitung)
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-gray-900 dark:text-gray-100">
                                {{ $isApproved ? (int) ($alloc?->allocated_sessions ?? 0) : 0 }} sesi
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-gray-900 dark:text-gray-100">
                                {{ $isApproved ? (int) ($alloc?->allocated_sheets ?? 0) : 0 }} lbr
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-blue-600 dark:text-blue-400">
                                @if ($record->requiresInvoice())
                                    <span class="text-xs font-normal text-gray-500">Dari Invoice Event</span>
                                @else
                                    {{ \App\Utils\RupiahHelper::format($isApproved ? (int) ($alloc?->allocated_revenue ?? 0) : 0) }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-gray-400">
                                Belum ada rekap sesi yang terhubung ke event ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot class="border-t-2 border-gray-300 bg-gray-50 font-semibold text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                    <tr>
                        <td colspan="4" class="px-4 py-3 text-xs uppercase">Total Akumulasi Rekap Approved</td>
                        <td class="px-4 py-3 text-right">{{ $record->total_allocated_sessions }} sesi</td>
                        <td class="px-4 py-3 text-right">{{ $record->total_allocated_sheets }} lbr</td>
                        <td class="px-4 py-3 text-right text-blue-600 dark:text-blue-400">
                            @if ($record->requiresInvoice())
                                {{ \App\Utils\RupiahHelper::format($record->total_invoice_amount) }}
                            @else
                                {{ \App\Utils\RupiahHelper::format($record->total_allocated_revenue) }}
                            @endif
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- B. Alokasi Invoice Event --}}
    @if ($record && $record->requiresInvoice())
        @livewire('express-event-invoice-manager', ['eventId' => $record->id], key('express-event-invoice-manager-' . $record->id))
    @endif

    {{-- C. Rincian Perhitungan Per Periode (Bila Event Lintas Bulan) --}}
    @if (!empty($preview['period_breakdown']) && count($preview['period_breakdown']) > 1)
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-3">
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Rincian Pengakuan HPP Per Periode Akuntansi</h3>
                <p class="text-xs text-gray-500">Event berlangsung lintas bulan: HPP diakui berdasarkan tanggal operasional masing-masing rekap sesi</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-600 dark:text-gray-300">
                    <thead class="border-b border-gray-200 bg-gray-50 text-xs uppercase text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                        <tr>
                            <th class="px-4 py-2.5">Periode Laporan</th>
                            <th class="px-4 py-2.5 text-right">Sesi</th>
                            <th class="px-4 py-2.5 text-right">Lembar</th>
                            <th class="px-4 py-2.5 text-right">Omzet Alokasi</th>
                            <th class="px-4 py-2.5 text-right">Tarif HPP</th>
                            <th class="px-4 py-2.5 text-right">Total HPP</th>
                            <th class="px-4 py-2.5 text-right">Laba Kotor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($preview['period_breakdown'] as $pb)
                            <tr>
                                <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-gray-100">{{ $pb['label'] }}</td>
                                <td class="px-4 py-2.5 text-right">{{ $pb['sessions'] }}</td>
                                <td class="px-4 py-2.5 text-right">{{ $pb['sheets'] }}</td>
                                <td class="px-4 py-2.5 text-right font-medium text-blue-600 dark:text-blue-400">{{ \App\Utils\RupiahHelper::format($pb['revenue']) }}</td>
                                <td class="px-4 py-2.5 text-right">{{ \App\Utils\RupiahHelper::format($preview['hpp_rate_per_sheet'] ?? 0) }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold text-amber-600 dark:text-amber-400">{{ \App\Utils\RupiahHelper::format($pb['hpp']) }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold {{ $pb['gross_profit'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    {{ \App\Utils\RupiahHelper::format($pb['gross_profit']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- D. Riwayat Perubahan & Audit Trail --}}
    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-4">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Riwayat Perubahan & Audit Trail</h3>
            <p class="text-xs text-gray-500">Seluruh pencatatan alokasi, konfirmasi HPP, perubahan tarif, serta pembukaan kembali</p>
        </div>

        <div class="flow-root">
            <ul role="list" class="-mb-8">
                @forelse ($record->auditLogs as $idx => $log)
                    <li>
                        <div class="relative pb-6">
                            @if (! $loop->last)
                                <span class="absolute left-3.5 top-4 -ml-px h-full w-0.5 bg-gray-200 dark:bg-gray-700" aria-hidden="true"></span>
                            @endif
                            <div class="relative flex space-x-3">
                                <div>
                                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-primary-50 ring-4 ring-white dark:bg-primary-950 dark:ring-gray-900">
                                        <x-heroicon-m-clock class="h-4 w-4 text-primary-600 dark:text-primary-400" />
                                    </span>
                                </div>
                                <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1">
                                    <div>
                                        <p class="text-xs font-semibold text-gray-900 dark:text-gray-100">
                                            {{ $log->action }}
                                            <span class="font-normal text-gray-500">oleh {{ $log->user?->nama_lengkap ?? 'Sistem' }}</span>
                                        </p>
                                        @if ($log->notes)
                                            <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">{{ $log->notes }}</p>
                                        @endif
                                    </div>
                                    <div class="whitespace-nowrap text-right text-xs text-gray-400">
                                        {{ $log->created_at?->format('d/m/Y H:i') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </li>
                @empty
                    <li class="py-4 text-center text-xs text-gray-400">Belum ada riwayat perubahan.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
