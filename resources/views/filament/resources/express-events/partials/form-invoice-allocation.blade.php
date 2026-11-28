@php
    /** @var \Livewire\Component&\App\Filament\Resources\ExpressEventResource\Concerns\InteractsWithEventInvoices $livewire */
    $livewire = $getLivewire();
    $context = $livewire->getInvoiceFormContext();
    $isSharingProfit = $context['is_sharing_profit'];
    $linkedInvoices = $context['linked_invoices'];
    $totalInvoiceRevenue = $context['invoice_total_revenue'];
    $operationalTypeLabel = $context['operational_type_label'];
    $record = $livewire->getCurrentEventRecord();
    $isLocked = $record?->isFinalized() ?? false;

    $isModalOpen = $livewire->isInvoiceModalOpen ?? false;
    $selectableInvoices = $isModalOpen ? $livewire->getModalSelectableInvoices() : collect();
    $modalSelectedIds = collect($livewire->modalSelectedInvoiceIds ?? [])
        ->map(fn ($id): int => (int) $id)
        ->unique()
        ->values()
        ->all();
    $selectedPreviewTotal = (int) $selectableInvoices
        ->whereIn('id', $modalSelectedIds)
        ->sum('contract_amount');
@endphp

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $isSharingProfit ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300' }}">
                {{ $operationalTypeLabel }}
            </span>

            @if ($isSharingProfit)
                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    Omzet Bersumber dari Rekap Sesi
                </span>
            @elseif ($linkedInvoices->isNotEmpty())
                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                    {{ $linkedInvoices->count() }} Invoice Terhubung
                </span>
            @else
                <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                    Belum Ada Invoice Terhubung
                </span>
            @endif
        </div>

        @if (! $isSharingProfit && ! $isLocked)
            <x-filament::button
                size="sm"
                color="primary"
                icon="heroicon-m-link"
                wire:click="openInvoiceModal"
                type="button"
            >
                Hubungkan Invoice
            </x-filament::button>
        @endif
    </div>

    @if ($isSharingProfit)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50/60 p-4 text-xs text-emerald-900 dark:border-emerald-800/60 dark:bg-emerald-950/20 dark:text-emerald-200">
            <p class="font-semibold">Express Sharing Profit — Tidak Memerlukan Penghubungan Invoice</p>
            <p class="mt-1 text-emerald-700 dark:text-emerald-300">
                Total Omzet event Sharing Profit tetap dihitung otomatis berdasarkan alokasi omzet pada Rekap Sesi Express di atas.
            </p>
        </div>
    @elseif ($linkedInvoices->isEmpty())
        <div class="rounded-lg border border-dashed border-amber-300 bg-amber-50/50 p-6 text-center dark:border-amber-700/70 dark:bg-amber-950/20">
            <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                Belum Ada Invoice yang Dihubungkan ke Event Ini
            </p>
            <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                Untuk Express Self Run dan Express Sewa, Total Omzet bersumber dari total nilai kontrak seluruh invoice yang terhubung.
            </p>
            @if (! $isLocked)
                <div class="mt-3">
                    <x-filament::button
                        size="xs"
                        color="warning"
                        icon="heroicon-m-plus"
                        wire:click="openInvoiceModal"
                        type="button"
                    >
                        Hubungkan Invoice
                    </x-filament::button>
                </div>
            @endif
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="w-full text-left text-sm text-gray-600 dark:text-gray-300">
                <thead class="border-b border-gray-200 bg-gray-50 text-xs uppercase text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-3">Nomor Invoice</th>
                        <th class="px-4 py-3">Nama Klien / Event</th>
                        <th class="px-4 py-3">Jenis Express</th>
                        <th class="px-4 py-3">Tanggal Invoice</th>
                        <th class="px-4 py-3">Status Pembayaran</th>
                        <th class="px-4 py-3 text-right">Total Nilai Invoice</th>
                        @if (! $isLocked)
                            <th class="px-4 py-3 text-right">Aksi</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                    @foreach ($linkedInvoices as $invoice)
                        @php
                            $statusColor = match ($invoice->status) {
                                'Lunas' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
                                'Sebagian Dibayar' => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
                                'Belum Dibayar' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
                                'Dibatalkan' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
                                default => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300',
                            };
                        @endphp
                        <tr class="hover:bg-gray-50/60 dark:hover:bg-gray-800/50">
                            <td class="px-4 py-3 font-mono font-semibold text-gray-900 dark:text-gray-100">
                                {{ $invoice->invoice_number }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900 dark:text-gray-100">{{ $invoice->client_name }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $invoice->event_name }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                                    {{ $invoice->express_type_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ $invoice->invoice_date?->format('d/m/Y') ?? '-' }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $statusColor }}">
                                    {{ $invoice->status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="font-semibold text-blue-600 dark:text-blue-400">
                                    {{ \App\Utils\RupiahHelper::format($invoice->contract_amount) }}
                                </div>
                                @if ((float) ($invoice->down_payment ?? 0) > 0)
                                    <div class="text-[11px] text-gray-500">
                                        DP: {{ \App\Utils\RupiahHelper::format($invoice->down_payment) }} (nilai kontrak penuh dipakai)
                                    </div>
                                @endif
                            </td>
                            @if (! $isLocked)
                                <td class="px-4 py-3 text-right">
                                    <x-filament::button
                                        size="xs"
                                        color="danger"
                                        outlined
                                        wire:click="detachFormInvoice({{ $invoice->id }})"
                                        type="button"
                                    >
                                        Lepas
                                    </x-filament::button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t-2 border-gray-300 bg-gray-50 font-semibold text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                    <tr>
                        <td colspan="5" class="px-4 py-3 text-xs uppercase">
                            Total Nilai Kontrak Invoice ({{ $linkedInvoices->count() }} Invoice Terhubung)
                        </td>
                        <td class="px-4 py-3 text-right text-blue-600 dark:text-blue-400">
                            {{ \App\Utils\RupiahHelper::format($totalInvoiceRevenue) }}
                        </td>
                        @if (! $isLocked)
                            <td class="px-4 py-3"></td>
                        @endif
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    {{-- Modal Hubungkan Invoice --}}
    @if ($isModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-gray-900/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-5xl rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-800">
                    <div>
                        <h3 class="text-base font-bold text-gray-900 dark:text-white">
                            Hubungkan Invoice Event Express
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Pilih satu atau lebih invoice Express. Perhitungan menggunakan Total Nilai Invoice (nilai kontrak penuh, bukan DP).
                        </p>
                    </div>
                    <button
                        wire:click="closeInvoiceModal"
                        type="button"
                        class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                    >
                        <x-heroicon-m-x-mark class="h-5 w-5" />
                    </button>
                </div>

                {{-- Bar Pencarian & Filter --}}
                <div class="grid grid-cols-1 gap-3 border-b border-gray-200 bg-gray-50/70 px-6 py-3.5 sm:grid-cols-3 dark:border-gray-800 dark:bg-gray-800/50">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">
                            Cari Nomor Invoice atau Nama Klien / Event
                        </label>
                        <input
                            type="text"
                            wire:model.live.debounce.250ms="invoiceSearch"
                            placeholder="Ketik nomor invoice, nama klien, atau nama event..."
                            class="w-full rounded-lg border-gray-300 px-3 py-1.5 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        />
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">
                            Status Pembayaran
                        </label>
                        <select
                            wire:model.live="invoiceStatusFilter"
                            class="w-full rounded-lg border-gray-300 px-3 py-1.5 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        >
                            <option value="">Semua Status</option>
                            <option value="Draft">Draft</option>
                            <option value="Belum Dibayar">Belum Dibayar</option>
                            <option value="Sebagian Dibayar">Sebagian Dibayar (DP)</option>
                            <option value="Lunas">Lunas</option>
                            <option value="Dibatalkan">Dibatalkan</option>
                        </select>
                    </div>
                </div>

                {{-- Tabel Pilihan Invoice --}}
                <div class="max-h-96 overflow-y-auto px-6 py-3">
                    <table class="w-full text-left text-xs text-gray-600 dark:text-gray-300">
                        <thead class="sticky top-0 border-b border-gray-200 bg-white text-[11px] uppercase text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            <tr>
                                <th class="py-2.5 pr-3">Pilih</th>
                                <th class="px-3 py-2.5">Nomor Invoice</th>
                                <th class="px-3 py-2.5">Nama Klien / Event</th>
                                <th class="px-3 py-2.5">Jenis Express</th>
                                <th class="px-3 py-2.5">Tanggal Invoice</th>
                                <th class="px-3 py-2.5">Status Pembayaran</th>
                                <th class="px-3 py-2.5 text-right">Total Nilai Invoice</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($selectableInvoices as $inv)
                                @php
                                    $isDisabled = $inv['used_by_other'] || $inv['linked_to_current'];
                                    $isChecked = in_array($inv['id'], $modalSelectedIds, true) || $inv['linked_to_current'];
                                @endphp
                                <tr class="{{ $inv['used_by_other'] ? 'opacity-60 bg-gray-50/70 dark:bg-gray-800/40' : 'hover:bg-gray-50 dark:hover:bg-gray-800/50' }}">
                                    <td class="py-3 pr-3">
                                        <input
                                            type="checkbox"
                                            @checked($isChecked)
                                            @disabled($isDisabled)
                                            wire:click="toggleModalInvoice({{ $inv['id'] }})"
                                            class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 disabled:cursor-not-allowed"
                                        />
                                    </td>
                                    <td class="px-3 py-3">
                                        <div class="font-mono font-semibold text-gray-900 dark:text-white">{{ $inv['invoice_number'] }}</div>
                                        @if ($inv['used_by_other'])
                                            <div class="mt-1 flex flex-col items-start gap-1">
                                                <span class="inline-flex items-center rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold text-rose-800 dark:bg-rose-900/50 dark:text-rose-200">
                                                    Sudah terhubung: {{ $inv['other_event_name'] }}{{ !empty($inv['other_event_period']) ? ' (' . $inv['other_event_period'] . ')' : '' }}
                                                </span>
                                                @if ($record && !$record->isPosted() && !empty($inv['can_merge_into_other']) && !empty($inv['other_event_id']))
                                                    <button
                                                        type="button"
                                                        wire:click="mergeIntoInvoiceOwnerEvent({{ $inv['other_event_id'] }})"
                                                        wire:confirm="Apabila baris ini merupakan satu event operasional yang sama dengan '{{ e($inv['other_event_name']) }}', seluruh rekap sesi akan digabungkan menjadi 1 Event HPP dengan 1 invoice (tanpa duplikasi omzet). Lanjutkan penggabungan?"
                                                        class="inline-flex items-center gap-1 rounded bg-primary-600 px-2 py-0.5 text-[10px] font-semibold text-white shadow-xs hover:bg-primary-500"
                                                    >
                                                        Gabungkan ke Event Ini →
                                                    </button>
                                                @endif
                                            </div>
                                        @elseif ($inv['linked_to_current'])
                                            <span class="mt-1 inline-flex items-center rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200">
                                                Terhubung di event ini
                                            </span>
                                        @elseif ($inv['referenced_by_recap'])
                                            <span class="mt-1 inline-flex items-center rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold text-blue-800 dark:bg-blue-900/50 dark:text-blue-200">
                                                Sesuai Rekap Sesi
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3">
                                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ $inv['client_name'] }}</div>
                                        <div class="text-[11px] text-gray-500">{{ $inv['event_name'] }} ({{ $inv['event_date_label'] }})</div>
                                    </td>
                                    <td class="px-3 py-3">
                                        <span class="inline-flex items-center rounded bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                                            {{ $inv['express_type_label'] }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3">
                                        {{ $inv['invoice_date_label'] }}
                                    </td>
                                    <td class="px-3 py-3">
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                                            {{ $inv['status'] }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 text-right">
                                        <div class="font-semibold text-blue-600 dark:text-blue-400">
                                            {{ \App\Utils\RupiahHelper::format($inv['contract_amount']) }}
                                        </div>
                                        @if ($inv['down_payment'] > 0)
                                            <div class="text-[10px] text-gray-500">
                                                DP: {{ \App\Utils\RupiahHelper::format($inv['down_payment']) }}
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-8 text-center text-xs text-gray-400">
                                        Tidak ada invoice cabang Express yang sesuai dengan pencarian.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Footer Modal --}}
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-b-2xl border-t border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-800 dark:bg-gray-800/60">
                    <div class="text-xs text-gray-600 dark:text-gray-300">
                        Invoice dipilih: <span class="font-bold text-gray-900 dark:text-white">{{ count($modalSelectedIds) }} invoice</span>
                        · Nilai kontrak dipilih:
                        <span class="font-bold text-primary-600 dark:text-primary-400">
                            {{ \App\Utils\RupiahHelper::format($selectedPreviewTotal) }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-filament::button
                            size="sm"
                            color="gray"
                            outlined
                            wire:click="closeInvoiceModal"
                            type="button"
                        >
                            Batal
                        </x-filament::button>

                        <x-filament::button
                            size="sm"
                            color="primary"
                            icon="heroicon-m-link"
                            wire:click="confirmAttachModalInvoices"
                            :disabled="count($modalSelectedIds) === 0"
                            type="button"
                        >
                            Hubungkan Invoice ({{ count($modalSelectedIds) }})
                        </x-filament::button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
