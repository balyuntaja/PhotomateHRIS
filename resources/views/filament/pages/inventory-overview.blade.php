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

        @if ($summary['active_master_items'] === 0)
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
        @elseif ($summary['total_items'] === 0)
            <x-filament::section>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Belum ada barang pada cabang ini</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Master barang sudah tersedia, tetapi belum ada yang didaftarkan pada cabang yang dipilih.
                            Barang yang belum terdaftar tidak dihitung sebagai stok habis.
                        </p>
                    </div>

                    <x-filament::button
                        tag="a"
                        href="{{ \App\Filament\Resources\InventoryItemResource::getUrl('index') }}"
                        icon="heroicon-m-archive-box"
                        color="gray"
                    >
                        Kelola Barang
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

                    @if ($summary['stock_by_unit'] === [])
                        <p class="pm-stat-value">0</p>
                    @else
                        <div class="mt-2 space-y-0.5">
                            @foreach ($summary['stock_by_unit'] as $satuan => $total)
                                <p class="pm-stat-value !text-xl">
                                    {{ number_format($total, 0, ',', '.') }}
                                    <span class="text-xs font-medium uppercase tracking-wide text-white/60">{{ $satuan }}</span>
                                </p>
                            @endforeach
                        </div>
                    @endif

                    <p class="pm-stat-meta">
                        {{ $summary['cabang_id'] ? 'Akumulasi cabang terpilih' : 'Akumulasi seluruh cabang' }}
                        · stok lintas barang dipecah per satuan
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
                    <p class="pm-stat-meta">Master barang aktif unik pada cakupan filter</p>
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
                    <p class="pm-stat-meta">Lokasi barang-cabang dengan stok di atas 0 dan pada/di bawah minimum</p>
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
                    <p class="pm-stat-meta">Lokasi barang-cabang terdaftar dengan stok 0</p>
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
                        Hanya barang yang sudah terdaftar pada cabang yang dihitung; barang yang belum
                        terdaftar tidak dianggap stok habis.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <a href="{{ \App\Filament\Pages\InventoryStocks::getUrl() }}" class="pm-section-link">
                        Distribusi per cabang
                        <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5" />
                    </a>

                    <a href="{{ \App\Filament\Resources\InventoryTransactionResource::getUrl('create') }}" class="pm-section-link">
                        Catat transaksi
                        <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5" />
                    </a>
                </div>
            </div>

            {{ $this->table }}
        </section>
    </div>
</x-filament-panels::page>
