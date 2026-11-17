<div class="space-y-4 py-1">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Silakan pilih format laporan keuangan yang ingin Anda cetak:
    </p>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <!-- Format 1: Laporan Detail Transaksi -->
        <a href="{{ route('keuangan.laporan.cetak', $paramsDetail) }}"
           target="_blank"
           class="group relative flex flex-col justify-between p-4 rounded-xl border border-gray-200 bg-white hover:border-primary-500 hover:ring-2 hover:ring-primary-500/20 dark:border-gray-800 dark:bg-gray-900 dark:hover:border-primary-500 transition shadow-sm hover:shadow">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <div class="p-2.5 rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-950/60 dark:text-primary-400">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                        </svg>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        A4 Landscape
                    </span>
                </div>
                <h4 class="font-semibold text-gray-900 dark:text-white group-hover:text-primary-600 dark:group-hover:text-primary-400 transition">
                    Laporan Detail Transaksi
                </h4>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
                    Format detail dengan seluruh transaksi per baris beserta akun, kategori, metode, nominal, dan ringkasan saldo.
                </p>
            </div>
            <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-800 flex items-center justify-between text-xs font-medium text-primary-600 dark:text-primary-400 group-hover:translate-x-0.5 transition">
                <span>Cetak PDF Detail</span>
                <svg class="w-4 h-4 ml-1" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                </svg>
            </div>
        </a>

        <!-- Format 2: Laporan Laba Rugi Booth -->
        <a href="{{ route('keuangan.laporan.cetak', $paramsLabaRugi) }}"
           target="_blank"
           class="group relative flex flex-col justify-between p-4 rounded-xl border border-gray-200 bg-white hover:border-emerald-500 hover:ring-2 hover:ring-emerald-500/20 dark:border-gray-800 dark:bg-gray-900 dark:hover:border-emerald-500 transition shadow-sm hover:shadow">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <div class="p-2.5 rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125-1.125-1.125V4.125Z" />
                        </svg>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                        A4 Portrait
                    </span>
                </div>
                <h4 class="font-semibold text-gray-900 dark:text-white group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition">
                    Laporan Laba Rugi Booth
                </h4>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
                    Menampilkan pendapatan per booth (Express, Janus, Golio), rincian beban usaha per kategori, dan laba rugi akhir.
                </p>
            </div>
            <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-800 flex items-center justify-between text-xs font-medium text-emerald-600 dark:text-emerald-400 group-hover:translate-x-0.5 transition">
                <span>Cetak Laba Rugi</span>
                <svg class="w-4 h-4 ml-1" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                </svg>
            </div>
        </a>
    </div>
</div>
