<x-filament-panels::page>
    @php($report = $this->reportData())

    <div class="space-y-6">
        {{ $this->form }}

        <div class="text-sm text-gray-500 dark:text-gray-400">
            Periode: <span class="font-medium text-gray-700 dark:text-gray-200">{{ $report['period']['label'] }}</span>
            &middot;
            Cabang: <span class="font-medium text-gray-700 dark:text-gray-200">{{ $report['cabangName'] }}</span>
        </div>

        {{-- Ringkasan --}}
        <div class="grid gap-4 md:grid-cols-3">
            <x-filament::card>
                <div class="text-sm text-gray-500 dark:text-gray-400">Total Pemasukan</div>
                <div class="mt-2 text-2xl font-semibold text-success-600 dark:text-success-400">
                    Rp {{ number_format($report['summary']['income'], 0, ',', '.') }}
                </div>
            </x-filament::card>

            <x-filament::card>
                <div class="text-sm text-gray-500 dark:text-gray-400">Total Pengeluaran</div>
                <div class="mt-2 text-2xl font-semibold text-danger-600 dark:text-danger-400">
                    Rp {{ number_format($report['summary']['expense'], 0, ',', '.') }}
                </div>
            </x-filament::card>

            <x-filament::card>
                <div class="text-sm text-gray-500 dark:text-gray-400">Saldo</div>
                <div class="mt-2 text-2xl font-semibold text-primary-600 dark:text-primary-400">
                    Rp {{ number_format($report['summary']['balance'], 0, ',', '.') }}
                </div>
            </x-filament::card>
        </div>

        @if ($report['summary']['income'] === 0 && $report['summary']['expense'] === 0)
            <x-filament::card>
                <div class="text-center text-sm text-gray-500 dark:text-gray-400">
                    Belum ada transaksi pada periode ini. Mulai catat pemasukan dan pengeluaran Photomate di menu Transaksi.
                </div>
            </x-filament::card>
        @else
            {{-- Rincian pemasukan & pengeluaran --}}
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach (['income' => 'Pemasukan', 'expense' => 'Pengeluaran'] as $type => $typeLabel)
                    <x-filament::card>
                        <div class="flex items-center justify-between">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ $typeLabel }}
                            </h3>
                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                Rp {{ number_format($report['categoryBreakdown'][$type]['total'], 0, ',', '.') }}
                            </span>
                        </div>

                        <div class="mt-4 space-y-4">
                            @forelse ($report['categoryBreakdown'][$type]['groups'] as $group)
                                <div class="space-y-2">
                                    @if ($group['label'])
                                        <div class="flex items-center justify-between text-sm font-semibold text-gray-700 dark:text-gray-200">
                                            <span>{{ $group['label'] }}</span>
                                            <span>Rp {{ number_format($group['total'], 0, ',', '.') }}</span>
                                        </div>
                                    @endif

                                    @foreach ($group['items'] as $item)
                                        <div>
                                            <div class="flex items-center justify-between text-sm text-gray-700 dark:text-gray-200">
                                                <span>{{ $item['name'] }}</span>
                                                <span>Rp {{ number_format($item['total'], 0, ',', '.') }}</span>
                                            </div>
                                            <div class="mt-1 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                                                <span>{{ $item['percentage'] }}% dari total {{ strtolower($typeLabel) }}</span>
                                            </div>
                                            <div class="mt-1 h-2 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                                                <div
                                                    class="h-2 rounded-full {{ $type === 'income' ? 'bg-success-500' : 'bg-danger-500' }}"
                                                    style="width: {{ min(100, max(0, $item['percentage'])) }}%;"
                                                ></div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @empty
                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                    Belum ada {{ strtolower($typeLabel) }} pada periode ini.
                                </div>
                            @endforelse
                        </div>
                    </x-filament::card>
                @endforeach
            </div>

            {{-- Selisih kas per cabang --}}
            <x-filament::card>
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Per Cabang</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Selisih kas = pemasukan dikurangi pengeluaran per cabang (bukan laba akuntansi).
                </p>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-2 pe-4 font-medium">Cabang</th>
                                <th class="py-2 pe-4 text-right font-medium">Pemasukan</th>
                                <th class="py-2 pe-4 text-right font-medium">Pengeluaran</th>
                                <th class="py-2 text-right font-medium">Selisih Kas</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['branchBreakdown'] as $branch)
                                <tr class="border-t border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pe-4 font-medium text-gray-700 dark:text-gray-200">{{ $branch['branch'] }}</td>
                                    <td class="py-2 pe-4 text-right text-success-600 dark:text-success-400">
                                        Rp {{ number_format($branch['income'], 0, ',', '.') }}
                                    </td>
                                    <td class="py-2 pe-4 text-right text-danger-600 dark:text-danger-400">
                                        Rp {{ number_format($branch['expense'], 0, ',', '.') }}
                                    </td>
                                    <td class="py-2 text-right font-semibold text-primary-600 dark:text-primary-400">
                                        Rp {{ number_format($branch['net'], 0, ',', '.') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-2 text-center text-gray-500 dark:text-gray-400">
                                        Belum ada data cabang pada periode ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-gray-200 font-semibold dark:border-gray-700">
                                <td class="py-2 pe-4 text-gray-700 dark:text-gray-200">Total</td>
                                <td class="py-2 pe-4 text-right text-success-600 dark:text-success-400">
                                    Rp {{ number_format($report['summary']['income'], 0, ',', '.') }}
                                </td>
                                <td class="py-2 pe-4 text-right text-danger-600 dark:text-danger-400">
                                    Rp {{ number_format($report['summary']['expense'], 0, ',', '.') }}
                                </td>
                                <td class="py-2 text-right text-primary-600 dark:text-primary-400">
                                    Rp {{ number_format($report['summary']['balance'], 0, ',', '.') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </x-filament::card>
        @endif
    </div>
</x-filament-panels::page>
