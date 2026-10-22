<x-filament-panels::page>
    @php($summary = $this->getSummaryData())

    <div class="space-y-6">
        <x-filament::section>
            <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                <div class="max-w-xl">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Filter Cabang</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Pilih seluruh cabang atau cabang tertentu untuk melihat kondisi persediaan secara terpusat.
                    </p>
                </div>

                <div class="w-full max-w-xs">
                    {{ $this->form }}
                </div>
            </div>
        </x-filament::section>

        @if ($summary['total_items'] === 0)
            <x-filament::section>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Belum ada barang</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Tambahkan master barang terlebih dahulu untuk mulai memantau persediaan.
                        </p>
                    </div>

                    <x-filament::button
                        tag="a"
                        href="{{ \App\Filament\Resources\InventoryItemResource::getUrl('create') }}"
                        icon="heroicon-m-plus"
                    >
                        Tambah Barang
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endif

        <section>
            <div class="pm-section-head">
                <div>
                    <h3 class="pm-section-title">Ringkasan Persediaan</h3>
                    <p class="pm-section-subtitle">
                        {{ $summary['cabang_id'] ? 'Kondisi cabang terpilih' : 'Kondisi seluruh cabang' }}
                        · dihitung dari data stok aktual.
                    </p>
                </div>

                <a href="{{ \App\Filament\Resources\InventoryItemResource::getUrl('index') }}" class="pm-section-link">
                    Kelola barang
                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5" />
                </a>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="pm-stat-card pm-stat-card--primary">
                    <span class="pm-stat-label">
                        <x-filament::icon icon="heroicon-m-cube" class="h-3.5 w-3.5" />
                        Total Stok Tersedia
                    </span>
                    <p class="pm-stat-value">{{ number_format($summary['total_stock'], 0, ',', '.') }}</p>
                    <p class="pm-stat-meta">
                        {{ $summary['cabang_id'] ? 'Akumulasi cabang terpilih' : 'Akumulasi seluruh cabang' }}
                    </p>
                    <span class="pm-stat-icon">
                        <x-filament::icon icon="heroicon-o-cube" class="h-4 w-4" />
                    </span>
                </div>

                <div class="pm-stat-card">
                    <span class="pm-stat-label">
                        <x-filament::icon icon="heroicon-m-archive-box" class="h-3.5 w-3.5" />
                        Jenis Barang Aktif
                    </span>
                    <p class="pm-stat-value">{{ number_format($summary['total_items'], 0, ',', '.') }}</p>
                    <p class="pm-stat-meta">Barang aktif yang dipantau</p>
                    <span class="pm-stat-icon">
                        <x-filament::icon icon="heroicon-o-archive-box" class="h-4 w-4" />
                    </span>
                </div>

                <div class="pm-stat-card">
                    <span class="pm-stat-label">
                        <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-3.5 w-3.5" />
                        Stok Menipis
                    </span>
                    <p class="pm-stat-value text-warning-600 dark:text-warning-400">
                        {{ number_format($summary['low_stock'], 0, ',', '.') }}
                    </p>
                    <p class="pm-stat-meta">Jenis barang pada atau di bawah batas minimum</p>
                    <span class="pm-stat-icon">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4" />
                    </span>
                </div>

                <div class="pm-stat-card">
                    <span class="pm-stat-label">
                        <x-filament::icon icon="heroicon-m-x-circle" class="h-3.5 w-3.5" />
                        Stok Habis
                    </span>
                    <p class="pm-stat-value text-danger-600 dark:text-danger-400">
                        {{ number_format($summary['out_of_stock'], 0, ',', '.') }}
                    </p>
                    <p class="pm-stat-meta">Jenis barang tanpa stok tersedia</p>
                    <span class="pm-stat-icon">
                        <x-filament::icon icon="heroicon-o-x-circle" class="h-4 w-4" />
                    </span>
                </div>
            </div>
        </section>

        <section>
            <div class="pm-section-head">
                <div>
                    <h3 class="pm-section-title">Stok yang Memerlukan Perhatian</h3>
                    <p class="pm-section-subtitle">
                        Barang dengan stok habis atau berada pada/di bawah batas minimum per cabang.
                    </p>
                </div>

                <a href="{{ \App\Filament\Resources\InventoryTransactionResource::getUrl('create') }}" class="pm-section-link">
                    Catat transaksi
                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5" />
                </a>
            </div>

            {{ $this->table }}
        </section>
    </div>
</x-filament-panels::page>
