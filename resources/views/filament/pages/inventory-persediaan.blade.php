<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="max-w-xl">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Persediaan Barang</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Kelola master barang dan pantau stok setiap cabang dalam satu halaman.
                    </p>
                </div>

                <x-filament::tabs label="Bagian persediaan">
                    <x-filament::tabs.item
                        :active="$activeTab === 'barang'"
                        icon="heroicon-m-archive-box"
                        wire:click="setActiveTab('barang')"
                    >
                        Daftar Barang
                    </x-filament::tabs.item>

                    <x-filament::tabs.item
                        :active="$activeTab === 'cabang'"
                        icon="heroicon-m-building-storefront"
                        wire:click="setActiveTab('cabang')"
                    >
                        Stok per Cabang
                    </x-filament::tabs.item>
                </x-filament::tabs>
            </div>
        </x-filament::section>

        <section>
            <div class="pm-section-head">
                <div>
                    <h3 class="pm-section-title">
                        {{ $activeTab === 'cabang' ? 'Stok per Cabang' : 'Daftar Barang' }}
                    </h3>

                    <p class="pm-section-subtitle">
                        @if ($activeTab === 'cabang')
                            Stok tersimpan terpisah untuk setiap kombinasi barang dan cabang.
                            Barang yang belum didaftarkan pada suatu cabang tidak dianggap memiliki stok 0 atau stok habis.
                        @else
                            Kelola nama, kategori, satuan, dan status barang. Total stok dihitung dari seluruh cabang tempat barang terdaftar.
                        @endif
                    </p>
                </div>
            </div>

            {{ $this->table }}
        </section>
    </div>
</x-filament-panels::page>
