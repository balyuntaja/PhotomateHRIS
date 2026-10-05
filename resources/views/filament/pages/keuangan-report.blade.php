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

        {{-- Rincian transaksi (mengikuti filter periode & cabang) --}}
        {{ $this->table }}

        {{-- Total dari transaksi yang sedang ditampilkan --}}
        <x-filament::card>
            <div class="space-y-3">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-500 dark:text-gray-400">Total Pemasukan</span>
                    <span class="font-semibold text-success-600 dark:text-success-400">
                        Rp {{ number_format($report['summary']['income'], 0, ',', '.') }}
                    </span>
                </div>

                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-500 dark:text-gray-400">Total Pengeluaran</span>
                    <span class="font-semibold text-danger-600 dark:text-danger-400">
                        Rp {{ number_format($report['summary']['expense'], 0, ',', '.') }}
                    </span>
                </div>

                <div class="flex items-center justify-between border-t border-gray-100 pt-3 text-sm dark:border-gray-800">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Saldo</span>
                    <span class="text-base font-semibold text-primary-600 dark:text-primary-400">
                        Rp {{ number_format($report['summary']['balance'], 0, ',', '.') }}
                    </span>
                </div>
            </div>
        </x-filament::card>
    </div>
</x-filament-panels::page>
