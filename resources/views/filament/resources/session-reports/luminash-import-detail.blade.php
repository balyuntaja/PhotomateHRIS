@php
    $summaryItems = [
        'File' => $batch->file_name,
        'Waktu Impor' => $batch->imported_at_label,
        'Diimpor Oleh' => $batch->importer?->nama_lengkap ?? '-',
        'Cabang Tujuan' => $batch->cabang?->nama_cabang ?? '-',
        'Periode Transaksi' => $batch->period_label,
        'Total Baris Dibaca' => number_format($batch->total_rows, 0, ',', '.'),
        'Berhasil Diimpor' => number_format($batch->imported_count, 0, ',', '.'),
        'Rekap Sesi Dibuat' => number_format($batch->session_report_count, 0, ',', '.'),
        'Dikecualikan (Incomplete)' => number_format($batch->excluded_incomplete_count, 0, ',', '.'),
        'Dikecualikan (Voucher)' => number_format($batch->excluded_voucher_count, 0, ',', '.'),
        'Dikecualikan (Status Lain)' => number_format($batch->excluded_unknown_status_count, 0, ',', '.'),
        'Duplikat' => number_format($batch->duplicate_count, 0, ',', '.'),
        'Gagal Diproses' => number_format($batch->invalid_count, 0, ',', '.'),
        'Total Nominal Impor' => \App\Utils\RupiahHelper::format($batch->total_imported_amount),
    ];
@endphp

<div class="space-y-4">
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($summaryItems as $label => $value)
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="max-h-[26rem] overflow-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="w-full table-auto divide-y divide-gray-200 text-sm dark:divide-gray-700">
            <thead class="sticky top-0 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                <tr>
                    <th class="px-3 py-2">Order ID</th>
                    <th class="px-3 py-2">Timestamp</th>
                    <th class="px-3 py-2">Metode</th>
                    <th class="px-3 py-2 text-right">Nominal</th>
                    <th class="px-3 py-2 text-center">Cetak</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Voucher</th>
                    <th class="px-3 py-2">Hasil</th>
                    <th class="px-3 py-2">Rekap Sesi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($batch->rows->sortBy('id') as $row)
                    <tr class="align-top">
                        <td class="px-3 py-2 font-mono text-xs">{{ $row->order_id }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $row->transaction_at?->format('d M Y H:i') ?? '-' }}</td>
                        <td class="px-3 py-2">{{ $row->payment_method ?? '-' }}</td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">{{ $row->formatted_amount }}</td>
                        <td class="px-3 py-2 text-center">{{ $row->print_count ?? '-' }}{{ $row->extra_prints ? ' (+' . $row->extra_prints . ')' : '' }}</td>
                        <td class="px-3 py-2">{{ $row->source_status ?? '-' }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $row->voucher_label }}</td>
                        <td class="px-3 py-2">
                            <x-filament::badge :color="$row->result_color">{{ $row->result_label }}</x-filament::badge>
                            @if ($row->reason)
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $row->reason }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2 font-mono text-xs">
                            @if ($row->session_report_id)
                                <a
                                    class="text-primary-600 underline dark:text-primary-400"
                                    href="{{ \App\Filament\Resources\SessionReportResource::getUrl('view', ['record' => $row->session_report_id]) }}"
                                    target="_blank"
                                >
                                    {{ $row->sessionReport?->report_number ?? '#' . $row->session_report_id }}
                                </a>
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
