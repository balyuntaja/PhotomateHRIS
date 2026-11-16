<x-filament-panels::page>
    @php($report = $this->reportData())

    <div class="space-y-6">
        {{ $this->form }}

        <div class="text-sm text-gray-500 dark:text-gray-400">
            Periode: <span class="font-medium text-gray-700 dark:text-gray-200">{{ $report['period']['label'] }}</span>
            &middot;
            Akun: <span class="font-medium text-gray-700 dark:text-gray-200">{{ $report['accountName'] }}</span>
        </div>

        {{-- Ringkasan Kartu Atas --}}
        <div class="grid gap-4 md:grid-cols-3">
            <x-filament::card>
                <div class="text-sm text-gray-500 dark:text-gray-400">Total Pendapatan</div>
                <div class="mt-2 text-2xl font-semibold text-success-600 dark:text-success-400">
                    {{ \App\Utils\RupiahHelper::format($report['ringkasan']['total_pendapatan']) }}
                </div>
            </x-filament::card>

            <x-filament::card>
                <div class="text-sm text-gray-500 dark:text-gray-400">Total Beban Usaha</div>
                <div class="mt-2 text-2xl font-semibold text-danger-600 dark:text-danger-400">
                    {{ \App\Utils\RupiahHelper::format($report['ringkasan']['total_beban']) }}
                </div>
            </x-filament::card>

            <x-filament::card>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $report['ringkasan']['status_label'] }} Bersih
                </div>
                <div @class([
                    'mt-2 text-2xl font-semibold',
                    'text-success-600 dark:text-success-400' => $report['ringkasan']['status'] === 'laba',
                    'text-danger-600 dark:text-danger-400' => $report['ringkasan']['status'] === 'rugi',
                    'text-gray-600 dark:text-gray-400' => $report['ringkasan']['status'] === 'impas',
                ])>
                    {{ $report['ringkasan']['laba_rugi'] < 0 ? '-' : '' }}{{ \App\Utils\RupiahHelper::format(abs($report['ringkasan']['laba_rugi'])) }}
                </div>
            </x-filament::card>
        </div>

        {{-- Tabel Laporan Laba Rugi Terstruktur --}}
        <x-filament::card>
            <div class="space-y-6">
                {{-- A. Pendapatan Usaha --}}
                <div>
                    <div class="flex items-center justify-between mb-3 border-b border-gray-100 pb-2 dark:border-gray-800">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                            A. Pendapatan Usaha
                        </h3>
                        <span class="text-xs text-gray-400">Dikelompokkan berdasarkan Akun / Cabang</span>
                    </div>
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                        <table class="w-full text-sm divide-y divide-gray-200 dark:divide-gray-800">
                            <thead class="bg-gray-50 dark:bg-gray-800/50">
                                <tr>
                                    <th class="py-2.5 px-4 text-left font-semibold text-gray-700 dark:text-gray-200">Keterangan</th>
                                    <th class="py-2.5 px-4 text-right font-semibold text-gray-700 dark:text-gray-200">Nominal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800/60 bg-white dark:bg-gray-900">
                                @foreach ($report['pendapatan']['rows'] as $branch => $amount)
                                    <tr>
                                        <td class="py-2.5 px-4 text-gray-700 dark:text-gray-300">{{ $branch }}</td>
                                        <td class="py-2.5 px-4 text-right font-medium text-gray-900 dark:text-white">
                                            {{ \App\Utils\RupiahHelper::format($amount) }}
                                        </td>
                                    </tr>
                                @endforeach
                                <tr class="bg-gray-50/80 dark:bg-gray-800/40 font-semibold border-t-2 border-gray-300 dark:border-gray-700">
                                    <td class="py-3 px-4 text-gray-900 dark:text-white">Total Pendapatan</td>
                                    <td class="py-3 px-4 text-right text-success-600 dark:text-success-400 text-base">
                                        {{ \App\Utils\RupiahHelper::format($report['pendapatan']['total']) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- B. Beban Usaha --}}
                <div>
                    <div class="flex items-center justify-between mb-3 border-b border-gray-100 pb-2 dark:border-gray-800">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                            B. Beban Usaha
                        </h3>
                        <span class="text-xs text-gray-400">Dikelompokkan berdasarkan Kategori Utama</span>
                    </div>
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                        <table class="w-full text-sm divide-y divide-gray-200 dark:divide-gray-800">
                            <thead class="bg-gray-50 dark:bg-gray-800/50">
                                <tr>
                                    <th class="py-2.5 px-4 text-left font-semibold text-gray-700 dark:text-gray-200">Keterangan</th>
                                    <th class="py-2.5 px-4 text-right font-semibold text-gray-700 dark:text-gray-200">Nominal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800/60 bg-white dark:bg-gray-900">
                                @forelse ($report['beban']['rows'] as $kategori => $amount)
                                    <tr>
                                        <td class="py-2.5 px-4 text-gray-700 dark:text-gray-300">{{ $kategori }}</td>
                                        <td class="py-2.5 px-4 text-right font-medium text-gray-900 dark:text-white">
                                            {{ \App\Utils\RupiahHelper::format($amount) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="py-2.5 px-4 text-gray-500 dark:text-gray-400">Beban Usaha</td>
                                        <td class="py-2.5 px-4 text-right font-medium text-gray-500 dark:text-gray-400">Rp 0</td>
                                    </tr>
                                @endforelse
                                <tr class="bg-gray-50/80 dark:bg-gray-800/40 font-semibold border-t-2 border-gray-300 dark:border-gray-700">
                                    <td class="py-3 px-4 text-gray-900 dark:text-white">Total Beban Usaha</td>
                                    <td class="py-3 px-4 text-right text-danger-600 dark:text-danger-400 text-base">
                                        {{ \App\Utils\RupiahHelper::format($report['beban']['total']) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- C. Laba / Rugi --}}
                <div>
                    <div class="flex items-center justify-between mb-3 border-b border-gray-100 pb-2 dark:border-gray-800">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                            C. Laba / Rugi
                        </h3>
                        <span class="text-xs text-gray-400">Hasil Akhir: Total Pendapatan - Total Beban Usaha</span>
                    </div>
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                        <table class="w-full text-sm divide-y divide-gray-200 dark:divide-gray-800">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800/60 bg-white dark:bg-gray-900">
                                <tr>
                                    <td class="py-2.5 px-4 text-gray-700 dark:text-gray-300">Total Pendapatan</td>
                                    <td class="py-2.5 px-4 text-right font-medium text-success-600 dark:text-success-400">
                                        {{ \App\Utils\RupiahHelper::format($report['ringkasan']['total_pendapatan']) }}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="py-2.5 px-4 text-gray-700 dark:text-gray-300">Total Beban Usaha</td>
                                    <td class="py-2.5 px-4 text-right font-medium text-danger-600 dark:text-danger-400">
                                        {{ \App\Utils\RupiahHelper::format($report['ringkasan']['total_beban']) }}
                                    </td>
                                </tr>
                                <tr @class([
                                    'font-bold border-t-2 text-base',
                                    'bg-emerald-50/70 dark:bg-emerald-950/30 border-emerald-500' => $report['ringkasan']['status'] === 'laba',
                                    'bg-rose-50/70 dark:bg-rose-950/30 border-rose-500' => $report['ringkasan']['status'] === 'rugi',
                                    'bg-gray-50/80 dark:bg-gray-800/50 border-gray-400' => $report['ringkasan']['status'] === 'impas',
                                ])>
                                    <td class="py-3 px-4 text-gray-900 dark:text-white">
                                        Laba / Rugi Bersih ({{ $report['ringkasan']['status_label'] }})
                                    </td>
                                    <td @class([
                                        'py-3 px-4 text-right text-lg',
                                        'text-success-600 dark:text-success-400' => $report['ringkasan']['status'] === 'laba',
                                        'text-danger-600 dark:text-danger-400' => $report['ringkasan']['status'] === 'rugi',
                                        'text-gray-700 dark:text-gray-300' => $report['ringkasan']['status'] === 'impas',
                                    ])>
                                        {{ $report['ringkasan']['laba_rugi'] < 0 ? '-' : '' }}{{ \App\Utils\RupiahHelper::format(abs($report['ringkasan']['laba_rugi'])) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </x-filament::card>
    </div>
</x-filament-panels::page>
