@php
    $record = isset($getRecord) ? $getRecord() : ($record ?? null);
    if ($record) {
        $record->loadMissing(['recaps.allocations', 'recaps.sessionReport.cabang', 'recaps.sessionReport.crews', 'auditLogs.user']);
        $hppService = app(\App\Services\ExpressEventHppService::class);
        $preview = $hppService->calculatePreview($record);
        $allocationService = app(\App\Services\ExpressEventAllocationService::class);
    } else {
        $preview = ['period_breakdown' => []];
    }
@endphp

<div class="space-y-6">
    {{-- 1. Rekap Terhubung & Rincian Alokasi --}}
    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Rekap Sesi Terhubung & Rincian Alokasi</h3>
                <p class="text-xs text-gray-500">Daftar rekap sesi operasional Express yang dialokasikan ke event ini</p>
            </div>
            <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                {{ $record->recaps->count() }} Rekap Terhubung
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-gray-600 dark:text-gray-300">
                <thead class="border-b border-gray-200 bg-gray-50 text-xs uppercase text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-3">Rekap & Cabang</th>
                        <th class="px-4 py-3">Tanggal & Crew</th>
                        <th class="px-4 py-3 text-right">Kapasitas Sumber</th>
                        <th class="px-4 py-3 text-right">Alokasi Sesi</th>
                        <th class="px-4 py-3 text-right">Alokasi Lembar</th>
                        <th class="px-4 py-3 text-right">Alokasi Omzet</th>
                        <th class="px-4 py-3 text-right">Sisa di Sumber</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    @forelse ($record->recaps as $recap)
                        @php
                            $report = $recap->sessionReport;
                            $alloc = $recap->latestAllocation;
                            $source = $report ? $allocationService->getSourceTotals($report) : ['sessions' => 0, 'sheets' => 0, 'revenue' => 0];
                            $remaining = $report ? $allocationService->getRemainingTotals($report) : ['sessions' => 0, 'sheets' => 0, 'revenue' => 0];
                            $allocatedElsewhere = $report ? $allocationService->getAllocatedTotals($report, $record->id) : ['sessions' => 0, 'sheets' => 0, 'revenue' => 0];
                        @endphp
                        <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900 dark:text-gray-100">{{ $report?->report_number ?? '-' }}</div>
                                <div class="text-xs text-gray-500">{{ $report?->cabang?->nama_cabang ?? '-' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-xs text-gray-900 dark:text-gray-100">{{ $report?->report_date?->format('d/m/Y') ?? '-' }}</div>
                                <div class="text-xs text-gray-500">{{ $report?->crew_names ?? '-' }}</div>
                            </td>
                            <td class="px-4 py-3 text-right text-xs">
                                <div>{{ $source['sessions'] }} sesi · {{ $source['sheets'] }} lbr</div>
                                <div class="font-medium text-gray-800 dark:text-gray-200">{{ \App\Utils\RupiahHelper::format($source['revenue']) }}</div>
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-gray-900 dark:text-gray-100">
                                {{ (int) ($alloc?->allocated_sessions ?? 0) }} sesi
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-gray-900 dark:text-gray-100">
                                {{ (int) ($alloc?->allocated_sheets ?? 0) }} lbr
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-blue-600 dark:text-blue-400">
                                {{ \App\Utils\RupiahHelper::format((int) ($alloc?->allocated_revenue ?? 0)) }}
                            </td>
                            <td class="px-4 py-3 text-right text-xs">
                                <div class="{{ $remaining['sheets'] > 0 ? 'text-amber-600 font-semibold' : 'text-gray-400' }}">
                                    {{ $remaining['sheets'] }} lbr tersisa
                                </div>
                                <div class="text-gray-500">
                                    {{ \App\Utils\RupiahHelper::format($remaining['revenue']) }}
                                </div>
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
                        <td colspan="3" class="px-4 py-3 uppercase text-xs">Total Alokasi Event</td>
                        <td class="px-4 py-3 text-right">{{ $record->total_allocated_sessions }} sesi</td>
                        <td class="px-4 py-3 text-right">{{ $record->total_allocated_sheets }} lbr</td>
                        <td class="px-4 py-3 text-right text-blue-600 dark:text-blue-400">{{ \App\Utils\RupiahHelper::format($record->total_allocated_revenue) }}</td>
                        <td class="px-4 py-3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- 2. Rincian Perhitungan Per Periode (Bila Event Lintas Bulan) --}}
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

    {{-- 3. Riwayat Perubahan & Audit Trail --}}
    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-4">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Riwayat Perubahan & Audit Trail</h3>
            <p class="text-xs text-gray-500">Seluruh pencatatan alokasi, konfirmasi HPP, perubahan tarif, serta pembukaan kembali</p>
        </div>

        <div class="flow-root">
            <ul role="list" class="-mb-8">
                @forelse ($record->auditLogs as $idx => $log)
                    <li>
                        <div class="relative pb-8">
                            @if (!$loop->last)
                                <span class="absolute left-4 top-4 -ml-px h-full w-0.5 bg-gray-200 dark:bg-gray-700" aria-hidden="true"></span>
                            @endif
                            <div class="relative flex space-x-3">
                                <div>
                                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100 ring-8 ring-white dark:bg-blue-900 dark:ring-gray-900">
                                        <svg class="h-4 w-4 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                        </svg>
                                    </span>
                                </div>
                                <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                            {{ $log->action }}
                                            <span class="font-normal text-gray-500">oleh {{ $log->user?->nama_lengkap ?? 'Sistem' }}</span>
                                        </p>
                                        @if ($log->notes)
                                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ $log->notes }}</p>
                                        @endif
                                    </div>
                                    <div class="whitespace-nowrap text-right text-xs text-gray-500">
                                        {{ $log->created_at?->format('d/m/Y H:i') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </li>
                @empty
                    <li class="py-4 text-center text-xs text-gray-400">Belum ada catatan log aktivitas.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
