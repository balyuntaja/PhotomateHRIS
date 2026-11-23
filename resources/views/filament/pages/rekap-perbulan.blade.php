@php
    use App\Utils\RupiahHelper;

    $report = $this->reportData();
    $period = $report['period'];
    $summary = $report['summary'];
    $boothTable = $report['booth_table'];
    $utangTable = $report['utang_table'];
    $expenseMatrix = $report['expense_matrix'];
    $donutChart = $report['donut_chart'];

    $month = (int) ($this->data['bulan'] ?? now()->month);
    $year = (int) ($this->data['tahun'] ?? now()->year);
@endphp

<x-filament-panels::page class="fi-rekap-perbulan-page">
    <div class="space-y-6">
        {{-- Filter Form --}}
        <div class="p-4 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
            {{ $this->form }}
        </div>

        {{-- Section 1: Visualisasi Performa Bisnis (2 Charts Side-by-Side) --}}
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
            {{-- 1. Donut Chart: Komposisi Pendapatan 3 Booth --}}
            <div class="lg:col-span-5 bg-white dark:bg-gray-800 p-5 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 flex flex-col justify-between">
                {{-- A. Header Section: Judul, Deskripsi & Legend Sederhana --}}
                <div>
                    <h3 class="text-sm font-bold tracking-tight text-gray-800 dark:text-gray-100 uppercase mb-1">
                        Komposisi Pendapatan 3 Booth
                    </h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                        Kontribusi pendapatan dari masing-masing cabang periode {{ $period['label'] }}
                    </p>

                    {{-- Simple Clean Legend Bar --}}
                    <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1.5 py-1 px-2.5 bg-gray-50/70 dark:bg-gray-900/40 rounded-lg border border-gray-100 dark:border-gray-800 text-xs">
                        @foreach($boothTable['rows'] as $bName => $bData)
                            @php
                                $bPct = $boothTable['total']['income'] > 0 
                                    ? round(($bData['income'] / $boothTable['total']['income']) * 100, 1) 
                                    : 0;
                                $dotColor = match($bName) {
                                    'Janus' => 'bg-blue-500',
                                    'Golio' => 'bg-amber-500',
                                    'Express' => 'bg-purple-500',
                                    default => 'bg-gray-400',
                                };
                            @endphp
                            <div class="inline-flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full {{ $dotColor }} ring-2 ring-white dark:ring-gray-800"></span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $bName }}</span>
                                <span class="font-bold text-gray-900 dark:text-white">{{ $bPct }}%</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- B. Visualisasi Donut Chart (Tepat di Tengah, 1:1 Aspect Ratio, Diameter Proporsional) --}}
                <div class="my-4 py-1 flex items-center justify-center">
                    @livewire(\App\Filament\Widgets\RekapPerbulanDonutChart::class, [
                        'month' => $month,
                        'year' => $year,
                    ], key('donut-chart-' . $month . '-' . $year))
                </div>

                {{-- C. Summary Pendapatan 3 Booth (3 Cards Sejajar di Bawah) --}}
                <div class="pt-3 border-t border-gray-100 dark:border-gray-700/60">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                        @foreach($boothTable['rows'] as $bName => $bData)
                            @php
                                $bPct = $boothTable['total']['income'] > 0 
                                    ? round(($bData['income'] / $boothTable['total']['income']) * 100, 1) 
                                    : 0;
                                $theme = match($bName) {
                                    'Janus' => [
                                        'dot' => 'bg-blue-500',
                                        'pill' => 'bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 border-blue-200/70 dark:border-blue-800',
                                    ],
                                    'Golio' => [
                                        'dot' => 'bg-amber-500',
                                        'pill' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 border-amber-200/70 dark:border-amber-800',
                                    ],
                                    'Express' => [
                                        'dot' => 'bg-purple-500',
                                        'pill' => 'bg-purple-50 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 border-purple-200/70 dark:border-purple-800',
                                    ],
                                    default => [
                                        'dot' => 'bg-gray-500',
                                        'pill' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 border-gray-200 dark:border-gray-700',
                                    ],
                                };
                            @endphp
                            <div class="bg-gray-50/80 dark:bg-gray-900/40 border border-gray-200/70 dark:border-gray-700/60 rounded-xl p-2.5 flex flex-col justify-between transition-colors hover:border-gray-300 dark:hover:border-gray-600">
                                <div class="flex items-center justify-between gap-1 mb-1.5">
                                    <div class="flex items-center gap-1.5 truncate">
                                        <span class="w-2 h-2 rounded-full {{ $theme['dot'] }} shrink-0"></span>
                                        <span class="text-xs font-semibold text-gray-700 dark:text-gray-200 truncate">{{ $bName }}</span>
                                    </div>
                                    <span class="inline-block text-[10px] font-bold px-1.5 py-0.5 rounded border {{ $theme['pill'] }} shrink-0">
                                        {{ $bPct }}%
                                    </span>
                                </div>
                                <div class="text-xs font-bold text-gray-900 dark:text-white font-mono tracking-tight">
                                    {{ RupiahHelper::format($bData['income']) }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- 2. Line Chart: Perbandingan Pendapatan Booth --}}
            <div class="lg:col-span-7 bg-white dark:bg-gray-800 p-5 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 flex flex-col justify-between">
                <div>
                    <h3 class="text-sm font-bold tracking-tight text-gray-800 dark:text-gray-100 uppercase mb-1">
                        Pendapatan 3 Booth Dibandingkan
                    </h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                        Tren pendapatan bulanan 3 booth selama 6 bulan terakhir
                    </p>
                </div>

                <div class="my-2">
                    @livewire(\App\Filament\Widgets\RekapPerbulanTrendChart::class, [
                        'month' => $month,
                        'year' => $year,
                    ], key('trend-chart-' . $month . '-' . $year))
                </div>

                <div class="pt-3 border-t border-gray-100 dark:border-gray-700/60 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                    <span>* Sumber: transaksi kategori Pendapatan Booth</span>
                    <span class="font-medium text-gray-700 dark:text-gray-300">Periode: {{ $period['label'] }}</span>
                </div>
            </div>
        </div>

        {{-- Center Banner: Bulan Terpilih (Spreadsheet Style Header) --}}
        <div class="w-full bg-sky-200 dark:bg-sky-950/80 text-sky-950 dark:text-sky-100 border border-sky-300 dark:border-sky-800 rounded-lg py-2.5 px-4 text-center font-extrabold tracking-wider text-base uppercase shadow-sm">
            {{ strtoupper($period['month_name']) }} {{ $period['year'] }}
        </div>

        {{-- Section 2: Middle Tables (Summary Keuangan & Income Per Booth) --}}
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
            {{-- Left Table: SUMMARY BULAN --}}
            <div class="lg:col-span-4 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                {{-- Purple Header --}}
                <div class="bg-purple-200 dark:bg-purple-900/60 text-purple-950 dark:text-purple-100 font-bold px-4 py-2.5 text-center text-xs uppercase tracking-wider border-b border-purple-300 dark:border-purple-800">
                    SUMMARY {{ strtoupper($period['month_name']) }}
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs border-collapse">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-700/50 text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700 font-semibold">
                                <th class="py-2 px-3 text-left">Keterangan</th>
                                <th class="py-2 px-3 text-right">Nominal</th>
                                <th class="py-2 px-3 text-center">Persentase</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                            @foreach($summary as $row)
                                @php
                                    $badgeClasses = match($row['tone']) {
                                        'success' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800',
                                        'warning' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300 border border-amber-200 dark:border-amber-800',
                                        'danger'  => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300 border border-rose-200 dark:border-rose-800',
                                        default   => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
                                    };
                                @endphp
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20 transition-colors">
                                    <td class="py-2.5 px-3 font-semibold text-gray-800 dark:text-gray-200">
                                        {{ $row['keterangan'] }}
                                    </td>
                                    <td class="py-2.5 px-3 text-right font-medium text-gray-900 dark:text-gray-100 font-mono">
                                        {{ RupiahHelper::format($row['nominal']) }}
                                    </td>
                                    <td class="py-2.5 px-3 text-center">
                                        <span class="inline-block px-2 py-0.5 rounded text-[11px] font-bold {{ $badgeClasses }} min-w-[58px]">
                                            {{ number_format($row['persentase'], 2, ',', '.') }}%
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Right Table: INCOME PER BOOTH --}}
            <div class="lg:col-span-8 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                {{-- Green Header --}}
                <div class="bg-emerald-200 dark:bg-emerald-900/60 text-emerald-950 dark:text-emerald-100 font-bold px-4 py-2.5 text-center text-xs uppercase tracking-wider border-b border-emerald-300 dark:border-emerald-800">
                    INCOME {{ strtoupper($period['month_name']) }}
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs border-collapse">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-700/50 text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700 font-semibold text-[11px]">
                                <th class="py-2.5 px-2.5 text-left">Booth</th>
                                <th class="py-2.5 px-2.5 text-right">Income</th>
                                <th class="py-2.5 px-2 text-right">HPP</th>
                                <th class="py-2.5 px-1.5 text-center">% HPP</th>
                                <th class="py-2.5 px-2.5 text-right">Nett Profit</th>
                                <th class="py-2.5 px-1.5 text-center">% Profit</th>
                                <th class="py-2.5 px-2 text-center">Total Lembar</th>
                                <th class="py-2.5 px-2 text-center">Total Sesi</th>
                                <th class="py-2.5 px-2.5 text-right">Laba Ditahan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                            @foreach($boothTable['rows'] as $bName => $bData)
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20 transition-colors">
                                    <td class="py-2.5 px-2.5 font-bold text-gray-800 dark:text-gray-200">
                                        {{ $bName }}
                                    </td>
                                    <td class="py-2.5 px-2.5 text-right font-medium text-gray-900 dark:text-gray-100 font-mono">
                                        {{ RupiahHelper::format($bData['income']) }}
                                    </td>
                                    <td class="py-2.5 px-2 text-right text-gray-700 dark:text-gray-300 font-mono">
                                        {{ RupiahHelper::format($bData['hpp']) }}
                                    </td>
                                    <td class="py-2.5 px-1.5 text-center">
                                        <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300 border border-amber-200/60 dark:border-amber-800">
                                            {{ number_format($bData['hpp_percentage'], 2, ',', '.') }}%
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-2.5 text-right font-semibold text-emerald-700 dark:text-emerald-400 font-mono">
                                        {{ RupiahHelper::format($bData['nett_profit']) }}
                                    </td>
                                    <td class="py-2.5 px-1.5 text-center">
                                        <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-800 dark:bg-rose-900/30 dark:text-rose-300 border border-rose-200/60 dark:border-rose-800">
                                            {{ number_format($bData['nett_profit_percentage'], 2, ',', '.') }}%
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-2 text-center font-medium text-gray-700 dark:text-gray-300 font-mono">
                                        {{ number_format($bData['total_lembar'], 0, ',', '.') }}
                                    </td>
                                    <td class="py-2.5 px-2 text-center font-medium text-gray-700 dark:text-gray-300 font-mono">
                                        {{ number_format($bData['total_sesi'], 0, ',', '.') }}
                                    </td>
                                    <td class="py-2.5 px-2.5 text-right font-semibold text-purple-700 dark:text-purple-400 font-mono">
                                        {{ RupiahHelper::format($bData['laba_ditahan']) }}
                                    </td>
                                </tr>
                            @endforeach
                            {{-- TOTAL ROW --}}
                            <tr class="bg-gray-100/80 dark:bg-gray-700/60 font-bold border-t-2 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white">
                                <td class="py-2.5 px-2.5 uppercase tracking-wider">
                                    {{ $boothTable['total']['booth'] }}
                                </td>
                                <td class="py-2.5 px-2.5 text-right font-mono">
                                    {{ RupiahHelper::format($boothTable['total']['income']) }}
                                </td>
                                <td class="py-2.5 px-2 text-right font-mono">
                                    {{ RupiahHelper::format($boothTable['total']['hpp']) }}
                                </td>
                                <td class="py-2.5 px-1.5 text-center text-[10px]">
                                    {{ number_format($boothTable['total']['hpp_percentage'], 2, ',', '.') }}%
                                </td>
                                <td class="py-2.5 px-2.5 text-right font-mono text-emerald-800 dark:text-emerald-300">
                                    {{ RupiahHelper::format($boothTable['total']['nett_profit']) }}
                                </td>
                                <td class="py-2.5 px-1.5 text-center text-[10px]">
                                    {{ number_format($boothTable['total']['nett_profit_percentage'], 2, ',', '.') }}%
                                </td>
                                <td class="py-2.5 px-2 text-center font-mono">
                                    {{ number_format($boothTable['total']['total_lembar'], 0, ',', '.') }}
                                </td>
                                <td class="py-2.5 px-2 text-center font-mono">
                                    {{ number_format($boothTable['total']['total_sesi'], 0, ',', '.') }}
                                </td>
                                <td class="py-2.5 px-2.5 text-right font-mono text-purple-800 dark:text-purple-300">
                                    {{ RupiahHelper::format($boothTable['total']['laba_ditahan']) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Section 3: Bottom Tables (Utang & Expenses Per Cabang) --}}
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
            {{-- Left Table: TABEL UTANG --}}
            <div class="lg:col-span-4 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                {{-- Salmon/Pink Header --}}
                <div class="bg-rose-200 dark:bg-rose-900/60 text-rose-950 dark:text-rose-100 font-bold px-4 py-2.5 text-center text-xs uppercase tracking-wider border-b border-rose-300 dark:border-rose-800">
                    UTANG
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs border-collapse">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-700/50 text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700 font-semibold">
                                <th class="py-2 px-3 text-left">Keterangan</th>
                                <th class="py-2 px-2 text-center">Sisa Waktu</th>
                                <th class="py-2 px-3 text-right">Total Utang</th>
                                <th class="py-2 px-3 text-right">Perbulan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                            @forelse($utangTable['rows'] as $utang)
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20 transition-colors">
                                    <td class="py-2 px-3 font-medium text-gray-800 dark:text-gray-200">
                                        {{ $utang['keterangan'] }}
                                    </td>
                                    <td class="py-2 px-2 text-center text-gray-600 dark:text-gray-400 font-mono">
                                        {{ $utang['sisa_waktu'] }} bln
                                    </td>
                                    <td class="py-2 px-3 text-right text-gray-800 dark:text-gray-200 font-mono">
                                        {{ RupiahHelper::format($utang['total_utang']) }}
                                    </td>
                                    <td class="py-2 px-3 text-right font-semibold text-gray-900 dark:text-gray-100 font-mono">
                                        {{ RupiahHelper::format($utang['perbulan']) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-4 px-3 text-center text-gray-400 dark:text-gray-500 italic">
                                        Tidak ada catatan kewajiban utang aktif
                                    </td>
                                </tr>
                            @endforelse
                            {{-- Footer Row: Bayar per bulan --}}
                            <tr class="bg-gray-100/80 dark:bg-gray-700/60 font-bold border-t-2 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white">
                                <td colspan="3" class="py-2.5 px-3 text-left">
                                    Bayar per bulan
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono text-rose-700 dark:text-rose-400">
                                    {{ RupiahHelper::format($utangTable['total_perbulan']) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Right Table: EXPENSES PER CABANG --}}
            <div class="lg:col-span-8 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                {{-- Yellow/Amber Header --}}
                <div class="bg-amber-200 dark:bg-amber-900/60 text-amber-950 dark:text-amber-100 font-bold px-4 py-2.5 text-center text-xs uppercase tracking-wider border-b border-amber-300 dark:border-amber-800">
                    EXPENSES {{ strtoupper($period['month_name']) }}
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs border-collapse">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-700/50 text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700 font-semibold">
                                <th class="py-2.5 px-3 text-left min-w-[130px]">Kategori</th>
                                <th class="py-2.5 px-3 text-right">Janus</th>
                                <th class="py-2.5 px-3 text-right">Golio</th>
                                <th class="py-2.5 px-3 text-right">Express</th>
                                <th class="py-2.5 px-3 text-right font-bold bg-amber-50/50 dark:bg-amber-950/20">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                            @foreach($expenseMatrix['categories'] as $cat)
                                @php
                                    $rowMatrix = $expenseMatrix['matrix'][$cat] ?? ['Janus' => 0, 'Golio' => 0, 'Express' => 0, 'total' => 0];
                                @endphp
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20 transition-colors">
                                    <td class="py-2 px-3 font-semibold text-gray-800 dark:text-gray-200">
                                        {{ $cat }}
                                    </td>
                                    <td class="py-2 px-3 text-right text-gray-700 dark:text-gray-300 font-mono">
                                        {{ RupiahHelper::format($rowMatrix['Janus'] ?? 0) }}
                                    </td>
                                    <td class="py-2 px-3 text-right text-gray-700 dark:text-gray-300 font-mono">
                                        {{ RupiahHelper::format($rowMatrix['Golio'] ?? 0) }}
                                    </td>
                                    <td class="py-2 px-3 text-right text-gray-700 dark:text-gray-300 font-mono">
                                        {{ RupiahHelper::format($rowMatrix['Express'] ?? 0) }}
                                    </td>
                                    <td class="py-2 px-3 text-right font-bold text-gray-900 dark:text-gray-100 font-mono bg-amber-50/30 dark:bg-amber-950/10">
                                        {{ RupiahHelper::format($rowMatrix['total'] ?? 0) }}
                                    </td>
                                </tr>
                            @endforeach
                            {{-- GRAND TOTAL ROW --}}
                            <tr class="bg-gray-100/90 dark:bg-gray-700/70 font-extrabold border-t-2 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white">
                                <td class="py-2.5 px-3 uppercase tracking-wider">
                                    TOTAL
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono">
                                    {{ RupiahHelper::format($expenseMatrix['column_totals']['Janus'] ?? 0) }}
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono">
                                    {{ RupiahHelper::format($expenseMatrix['column_totals']['Golio'] ?? 0) }}
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono">
                                    {{ RupiahHelper::format($expenseMatrix['column_totals']['Express'] ?? 0) }}
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono text-amber-900 dark:text-amber-300 bg-amber-100/60 dark:bg-amber-900/40">
                                    {{ RupiahHelper::format($expenseMatrix['grand_total']) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
