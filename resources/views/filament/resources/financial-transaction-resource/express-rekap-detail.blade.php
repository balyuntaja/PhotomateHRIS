<div class="space-y-4">
    <div class="text-sm text-gray-600 dark:text-gray-300">
        {{ $rekap->transaction_date?->translatedFormat('d M Y') }}
        &middot; {{ $rekap->account?->name ?? '-' }}
        &middot; {{ $rekap->category?->name ?? '-' }}
        @if ($report)
            &middot; {{ $report->report_number }}
        @endif
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <th class="py-2 pr-3">Metode</th>
                    <th class="py-2 pr-3">Transaksi</th>
                    <th class="py-2 pr-3">Sesi</th>
                    <th class="py-2 pr-3">Keterangan</th>
                    <th class="py-2 pl-3 text-right">Nominal</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-2 pr-3">{{ $row['method'] ?? '-' }}</td>
                        <td class="py-2 pr-3">{{ $row['transactions'] ?? '-' }}</td>
                        <td class="py-2 pr-3">{{ $row['sessions'] ?? '-' }}</td>
                        <td class="py-2 pr-3">{{ $row['description'] ?? '-' }}</td>
                        <td class="py-2 pl-3 text-right font-medium">
                            {{ \App\Utils\RupiahHelper::format($row['amount'] ?? 0) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="py-3 text-gray-500 dark:text-gray-400" colspan="5">
                            Tidak ada rincian per metode untuk rekap ini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if (count($rows) > 0)
                <tfoot>
                    <tr class="border-t border-gray-200 font-semibold dark:border-white/10">
                        <td class="py-2 pr-3" colspan="4">Total ({{ count($rows) }} metode)</td>
                        <td class="py-2 pl-3 text-right">{{ \App\Utils\RupiahHelper::format($total) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
