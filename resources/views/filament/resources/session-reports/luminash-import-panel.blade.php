@php
    $luminashSummary = $this->luminashPreviewSummary;
    [$luminashStatusLabel, $luminashStatusColor] = $this->luminashStatus();
@endphp

<div class="space-y-6">
    <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200">
        <div class="flex items-start gap-3">
            <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 h-5 w-5 shrink-0" />
            <div class="space-y-1">
                <p class="font-semibold">Impor ini hanya berlaku untuk cabang Golio.</p>
                <p>
                    Satu rekap sesi dibuat untuk setiap tanggal di CSV dan langsung berstatus final (tanpa approval Supervisor),
                    sekaligus tercatat sebagai transaksi keuangan dengan kategori <strong>Pendapatan Booth</strong>. Hanya transaksi
                    <strong>Complete</strong> dan <strong>tanpa voucher</strong> yang diproses; transaksi Incomplete, memakai voucher,
                    duplikat, dan data tidak valid otomatis dikecualikan. Crew rekap mengikuti pilihan "Crew yang Bertugas" pada form di atas.
                </p>
            </div>
        </div>
    </div>

    <x-filament::section
        heading="File CSV Luminash"
        description="Unggah file CSV hasil export dari halaman Transactions Luminash (maksimal 10MB)."
    >
        @if ($this->luminashStoredPath === null)
            <label
                for="luminash-upload"
                class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-300 bg-white px-6 py-8 text-center transition hover:border-primary-500 hover:bg-primary-50/40 dark:border-gray-600 dark:bg-gray-900 dark:hover:border-primary-500 dark:hover:bg-primary-950/20"
            >
                <x-filament::icon icon="heroicon-o-arrow-up-tray" class="h-8 w-8 text-gray-400 dark:text-gray-500" />
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Drag &amp; drop file CSV di sini, atau klik untuk memilih</span>
                <span class="text-xs text-gray-500 dark:text-gray-400">Format CSV Luminash · maksimal 10MB</span>
                <span class="text-xs font-medium text-primary-600 dark:text-primary-400" wire:loading wire:target="luminashUpload">
                    Mengunggah file…
                </span>
                <input
                    id="luminash-upload"
                    type="file"
                    wire:model="luminashUpload"
                    accept=".csv,text/csv,text/plain"
                    class="sr-only"
                />
            </label>
        @else
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300 dark:ring-emerald-800">
                        <x-filament::icon icon="heroicon-o-table-cells" class="h-6 w-6" />
                    </span>

                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100" title="{{ $this->luminashFileName }}">
                            {{ $this->luminashFileName }}
                        </p>

                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                            <span>{{ $this->luminashFileSizeLabel() ?? '-' }}</span>
                            <span aria-hidden="true">·</span>
                            <x-filament::badge :color="$luminashStatusColor">{{ $luminashStatusLabel }}</x-filament::badge>
                            <span class="font-medium text-primary-600 dark:text-primary-400" wire:loading wire:target="validasiLuminash">Sedang memvalidasi…</span>
                            <span class="font-medium text-primary-600 dark:text-primary-400" wire:loading wire:target="importLuminash">Sedang mengimpor…</span>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <label
                            for="luminash-upload"
                            class="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                        >
                            Ganti File
                            <input
                                id="luminash-upload"
                                type="file"
                                wire:model="luminashUpload"
                                accept=".csv,text/csv,text/plain"
                                class="sr-only"
                            />
                        </label>

                        <x-filament::button
                            color="danger"
                            size="sm"
                            icon="heroicon-o-trash"
                            wire:click="hapusFileLuminash"
                            wire:confirm="Hapus file ini? Hasil validasi dan preview akan direset."
                        >
                            Hapus
                        </x-filament::button>
                    </div>
                </div>

                @if ($this->luminashErrorMessage)
                    <div class="mt-3 flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-200">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{{ $this->luminashErrorMessage }}</span>
                    </div>
                @endif

                @if ($this->luminashFileState === 'imported' && $this->luminashImportResult)
                    <div class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">
                        Impor berhasil:
                        {{ number_format($this->luminashImportResult['imported_count'], 0, ',', '.') }} transaksi keuangan dicatat,
                        {{ number_format($this->luminashImportResult['session_report_count'], 0, ',', '.') }} rekap sesi Golio dibuat,
                        total {{ \App\Utils\RupiahHelper::format($this->luminashImportResult['total_imported_amount']) }}.
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>

    <div class="flex flex-wrap items-center gap-3">
        <x-filament::button
            color="gray"
            icon="heroicon-o-check-circle"
            wire:click="validasiLuminash"
            wire:loading.attr="disabled"
            wire:target="validasiLuminash"
            :disabled="$this->luminashStoredPath === null"
        >
            Validasi CSV
        </x-filament::button>

        @if (($luminashSummary['imported'] ?? 0) > 0)
            <x-filament::button
                icon="heroicon-o-arrow-up-tray"
                wire:click="importLuminash"
                wire:loading.attr="disabled"
                wire:target="importLuminash"
                wire:confirm="Buat rekap sesi Golio dan catat {{ number_format($luminashSummary['imported'], 0, ',', '.') }} transaksi keuangan dari file ini?"
            >
                Buat Rekap Golio
            </x-filament::button>
        @endif
    </div>

    @if ($luminashSummary)
        @php
            $cards = [
                ['label' => 'Total Baris CSV', 'value' => number_format($luminashSummary['total_rows'], 0, ',', '.'), 'color' => 'text-gray-900 dark:text-gray-100'],
                ['label' => 'Lolos Impor', 'value' => number_format($luminashSummary['imported'], 0, ',', '.'), 'color' => 'text-emerald-600 dark:text-emerald-400'],
                ['label' => 'Dikecualikan: Incomplete', 'value' => number_format($luminashSummary['excluded_incomplete'], 0, ',', '.'), 'color' => 'text-amber-600 dark:text-amber-400'],
                ['label' => 'Dikecualikan: Voucher', 'value' => number_format($luminashSummary['excluded_voucher'], 0, ',', '.'), 'color' => 'text-sky-600 dark:text-sky-400'],
                ['label' => 'Dikecualikan: Status Lain', 'value' => number_format($luminashSummary['excluded_unknown_status'], 0, ',', '.'), 'color' => 'text-amber-600 dark:text-amber-400'],
                ['label' => 'Duplikat', 'value' => number_format($luminashSummary['duplicate'], 0, ',', '.'), 'color' => 'text-gray-500 dark:text-gray-400'],
                ['label' => 'Data Tidak Valid', 'value' => number_format($luminashSummary['invalid'], 0, ',', '.'), 'color' => 'text-rose-600 dark:text-rose-400'],
            ];
        @endphp

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($cards as $card)
                <x-filament::card>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</div>
                    <div class="mt-1 text-2xl font-semibold {{ $card['color'] }}">{{ $card['value'] }}</div>
                </x-filament::card>
            @endforeach

            <x-filament::card>
                <div class="text-sm text-gray-500 dark:text-gray-400">Total Nominal Lolos Impor</div>
                <div class="mt-1 text-2xl font-semibold text-primary-600 dark:text-primary-400">
                    {{ \App\Utils\RupiahHelper::format($luminashSummary['total_amount']) }}
                </div>
            </x-filament::card>

            @foreach ($luminashSummary['by_payment_method'] as $method => $detail)
                <x-filament::card>
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $method === 'CASH' ? 'Tunai' : ucfirst(strtolower($method)) }} · {{ number_format($detail['count'], 0, ',', '.') }} transaksi
                    </div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">
                        {{ \App\Utils\RupiahHelper::format($detail['amount']) }}
                    </div>
                </x-filament::card>
            @endforeach

            <x-filament::card>
                <div class="text-sm text-gray-500 dark:text-gray-400">Total Cetakan</div>
                <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">
                    {{ number_format($luminashSummary['total_prints'], 0, ',', '.') }}
                    <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                        (+{{ number_format($luminashSummary['total_extra_prints'], 0, ',', '.') }} tambahan)
                    </span>
                </div>
            </x-filament::card>

            <x-filament::card>
                <div class="text-sm text-gray-500 dark:text-gray-400">Periode Transaksi di CSV</div>
                <div class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ $luminashSummary['period_start'] && $luminashSummary['period_end']
                        ? \Illuminate\Support\Carbon::parse($luminashSummary['period_start'])->translatedFormat('d M Y') . ' – ' . \Illuminate\Support\Carbon::parse($luminashSummary['period_end'])->translatedFormat('d M Y')
                        : '-' }}
                </div>
            </x-filament::card>
        </div>

        <x-filament::section heading="Preview Transaksi" description="Nominal dan jumlah di atas hanya menghitung transaksi yang lolos seluruh filter dan belum pernah diimpor.">
            @if ($this->luminashTruncated)
                <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                    Menampilkan {{ number_format(\App\Filament\Resources\SessionReportResource\Pages\CreateSessionReport::LUMINASH_PREVIEW_LIMIT, 0, ',', '.') }} baris pertama dari {{ number_format($luminashSummary['total_rows'], 0, ',', '.') }} baris. Ringkasan di atas tetap menghitung seluruh baris.
                </p>
            @endif

            <div class="max-h-[32rem] overflow-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="w-full table-auto divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="sticky top-0 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2">Order ID</th>
                            <th class="px-3 py-2">Timestamp</th>
                            <th class="px-3 py-2">Payment Method</th>
                            <th class="px-3 py-2 text-right">Amount</th>
                            <th class="px-3 py-2 text-center">Print Count</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Voucher Code</th>
                            <th class="px-3 py-2">Hasil Validasi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($this->luminashPreviewRows as $row)
                            <tr class="align-top">
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['order_id'] ?? '-' }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $row['timestamp_raw'] ?? '-' }}</td>
                                <td class="px-3 py-2">{{ $row['payment_method'] ?? '-' }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    {{ $row['amount'] !== null ? \App\Utils\RupiahHelper::format($row['amount']) : ($row['amount_raw'] ?? '-') }}
                                </td>
                                <td class="px-3 py-2 text-center">{{ $row['print_count'] ?? '-' }}{{ $row['extra_prints'] ? ' (+' . $row['extra_prints'] . ')' : '' }}</td>
                                <td class="px-3 py-2">{{ $row['status_raw'] ?? '-' }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['voucher_code'] ?? '-' }}</td>
                                <td class="px-3 py-2">
                                    <x-filament::badge :color="\App\Models\LuminashImportRow::RESULT_COLORS[$row['result']] ?? 'gray'">
                                        {{ \App\Models\LuminashImportRow::RESULT_LABELS[$row['result']] ?? $row['result'] }}
                                    </x-filament::badge>
                                    @if ($row['reason'])
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $row['reason'] }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</div>
