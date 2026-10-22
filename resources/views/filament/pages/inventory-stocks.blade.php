<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-col gap-2">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Stok tersimpan terpisah per cabang</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Jumlah stok hanya dapat berubah melalui transaksi barang masuk, barang keluar, atau penyesuaian.
                Gunakan aksi <span class="font-medium text-gray-700 dark:text-gray-200">Atur Batas Minimum</span>
                untuk menyesuaikan ambang peringatan tiap cabang.
            </p>
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
