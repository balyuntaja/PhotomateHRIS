<div class="space-y-4">
    <div class="text-sm text-gray-600 dark:text-gray-300">
        {{ $rekap->transaction_date?->translatedFormat('d M Y') }}
        &middot; {{ $rekap->account?->name ?? '-' }}
        &middot; {{ $rekap->category?->name ?? '-' }}
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <th class="py-2 pr-3">Order ID</th>
                    <th class="py-2 pr-3">Tanggal</th>
                    <th class="py-2 pr-3">Metode</th>
                    <th class="py-2 pr-3">Deskripsi</th>
                    <th class="py-2 pl-3 text-right">Nominal</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($components as $component)
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-2 pr-3">{{ $component->source_id ?: '-' }}</td>
                        <td class="py-2 pr-3">{{ $component->transaction_date?->translatedFormat('d M Y') ?? '-' }}</td>
                        <td class="py-2 pr-3">{{ $component->paymentMethod?->name ?? '-' }}</td>
                        <td class="py-2 pr-3">{{ $component->description ?: '-' }}</td>
                        <td class="py-2 pl-3 text-right font-medium">{{ $component->formatted_amount }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="py-3 text-gray-500 dark:text-gray-400" colspan="5">
                            Tidak ada rincian transaksi penyusun rekap ini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if ($components->isNotEmpty())
                <tfoot>
                    <tr class="border-t border-gray-200 font-semibold dark:border-white/10">
                        <td class="py-2 pr-3" colspan="4">Total ({{ $components->count() }} transaksi)</td>
                        <td class="py-2 pl-3 text-right">{{ \App\Utils\RupiahHelper::format($components->sum('amount')) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
