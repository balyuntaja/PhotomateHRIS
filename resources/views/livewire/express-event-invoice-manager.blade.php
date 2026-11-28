<div>
    @if ($requiresInvoice && $event)
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Alokasi Invoice Event (Invoice Terkait)</h3>
                        @if ($linkedInvoices->isNotEmpty())
                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                                Invoice Terhubung ({{ $linkedInvoices->count() }})
                            </span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                Invoice Belum Terhubung
                            </span>
                        @endif
                    </div>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        Sumber harga komersial untuk {{ $event->operational_type_label }} diambil dari total nilai kontrak invoice yang terhubung.
                    </p>
                </div>

                @if ($canManage)
                    <x-filament::button
                        size="sm"
                        color="primary"
                        icon="heroicon-m-plus"
                        wire:click="openModal"
                        type="button"
                    >
                        + Hubungkan Invoice
                    </x-filament::button>
                @endif
            </div>

            @if ($linkedInvoices->isEmpty())
                <div class="rounded-lg border border-dashed border-amber-300 bg-amber-50/60 p-6 text-center dark:border-amber-700/70 dark:bg-amber-950/20">
                    <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                        Belum Ada Invoice yang Dihubungkan
                    </p>
                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                        Event {{ $event->operational_type_label }} belum dapat dianggap siap dikonfirmasi HPP sebelum minimal satu invoice dihubungkan.
                    </p>
                    @if ($canManage)
                        <div class="mt-3">
                            <x-filament::button
                                size="xs"
                                color="warning"
                                wire:click="openModal"
                                type="button"
                            >
                                + Hubungkan Invoice Sekarang
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-gray-600 dark:text-gray-300">
                        <thead class="border-b border-gray-200 bg-gray-50 text-xs uppercase text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                            <tr>
                                <th class="px-4 py-3">Nomor Invoice</th>
                                <th class="px-4 py-3">Customer / Klien</th>
                                <th class="px-4 py-3">Nama & Tanggal Event</th>
                                <th class="px-4 py-3">Tanggal Invoice</th>
                                <th class="px-4 py-3">Status Invoice</th>
                                <th class="px-4 py-3 text-right">Nilai Kontrak Invoice</th>
                                @if ($canManage)
                                    <th class="px-4 py-3 text-right">Aksi</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
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
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/50">
                                    <td class="px-4 py-3 font-mono font-semibold text-gray-900 dark:text-gray-100">
                                        {{ $invoice->invoice_number }}
                                    </td>
                                    <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-200">
                                        {{ $invoice->client_name }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ $invoice->event_name }}</div>
                                        <div class="text-xs text-gray-500">
                                            @if ($invoice->event_date)
                                                {{ $invoice->event_date->format('d/m/Y') }}
                                                @if ($invoice->event_end_date && $invoice->event_end_date->ne($invoice->event_date))
                                                    - {{ $invoice->event_end_date->format('d/m/Y') }}
                                                @endif
                                            @else
                                                -
                                            @endif
                                        </div>
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
                                                DP tercatat: {{ \App\Utils\RupiahHelper::format($invoice->down_payment) }} (nilai kontrak penuh dipakai)
                                            </div>
                                        @endif
                                    </td>
                                    @if ($canManage)
                                        <td class="px-4 py-3 text-right">
                                            <x-filament::button
                                                size="xs"
                                                color="danger"
                                                outlined
                                                wire:click="detachInvoice({{ $invoice->id }})"
                                                wire:confirm="Lepas invoice {{ $invoice->invoice_number }} dari event HPP ini? Data invoice asli tidak akan dihapus."
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
                                    Total Harga Invoice ({{ $linkedInvoices->count() }} Invoice Terhubung)
                                </td>
                                <td class="px-4 py-3 text-right text-blue-600 dark:text-blue-400">
                                    {{ \App\Utils\RupiahHelper::format($event->total_invoice_amount) }}
                                </td>
                                @if ($canManage)
                                    <td class="px-4 py-3"></td>
                                @endif
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>

        {{-- Modal Pemilihan Invoice --}}
        @if ($isModalOpen)
            <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-gray-900/60 p-4 backdrop-blur-xs">
                <div class="w-full max-w-5xl rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-800">
                        <div>
                            <h3 class="text-base font-bold text-gray-900 dark:text-white">
                                Hubungkan Invoice ke Event: {{ $event->event_name }}
                            </h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Pilih satu atau beberapa invoice dari modul Invoice Photomate. Nilai kontrak penuh (Total Nilai Invoice) akan dijumlahkan sebagai harga event.
                            </p>
                        </div>
                        <button
                            wire:click="closeModal"
                            type="button"
                            class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                        >
                            <x-heroicon-m-x-mark class="h-5 w-5" />
                        </button>
                    </div>

                    {{-- Filter Bar --}}
                    <div class="grid grid-cols-1 gap-3 border-b border-gray-200 bg-gray-50/70 px-6 py-3.5 sm:grid-cols-2 lg:grid-cols-4 dark:border-gray-800 dark:bg-gray-800/50">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Cari Invoice</label>
                            <input
                                type="text"
                                wire:model.live.debounce.250ms="search"
                                placeholder="Nomor invoice, klien, event..."
                                class="w-full rounded-lg border-gray-300 px-3 py-1.5 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Status Invoice</label>
                            <select
                                wire:model.live="statusFilter"
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

                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Dari Tanggal Event</label>
                            <input
                                type="date"
                                wire:model.live="dateFrom"
                                class="w-full rounded-lg border-gray-300 px-3 py-1.5 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Sampai Tanggal Event</label>
                            <input
                                type="date"
                                wire:model.live="dateTo"
                                class="w-full rounded-lg border-gray-300 px-3 py-1.5 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                            />
                        </div>
                    </div>

                    {{-- Daftar Invoice --}}
                    <div class="max-h-96 overflow-y-auto px-6 py-3">
                        <table class="w-full text-left text-xs text-gray-600 dark:text-gray-300">
                            <thead class="sticky top-0 bg-white text-[11px] uppercase text-gray-700 border-b border-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:border-gray-700">
                                <tr>
                                    <th class="py-2.5 pr-3">Pilih</th>
                                    <th class="px-3 py-2.5">Nomor Invoice</th>
                                    <th class="px-3 py-2.5">Nama Customer / Klien</th>
                                    <th class="px-3 py-2.5">Nama Event</th>
                                    <th class="px-3 py-2.5">Tanggal Event</th>
                                    <th class="px-3 py-2.5">Tanggal Invoice</th>
                                    <th class="px-3 py-2.5 text-right">Total Nilai Invoice</th>
                                    <th class="px-3 py-2.5">Status Invoice</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse ($selectableInvoices as $inv)
                                    @php
                                        $isDisabled = $inv['used_by_other'] || $inv['linked_to_current'];
                                        $isChecked = in_array($inv['id'], $selectedIds, true) || $inv['linked_to_current'];
                                    @endphp
                                    <tr class="{{ $inv['used_by_other'] ? 'opacity-60 bg-gray-50/70 dark:bg-gray-800/40' : 'hover:bg-gray-50 dark:hover:bg-gray-800/50' }}">
                                        <td class="py-3 pr-3">
                                            <input
                                                type="checkbox"
                                                @checked($isChecked)
                                                @disabled($isDisabled)
                                                wire:click="toggleInvoice({{ $inv['id'] }})"
                                                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 disabled:cursor-not-allowed"
                                            />
                                        </td>
                                        <td class="px-3 py-3">
                                            <div class="font-mono font-semibold text-gray-900 dark:text-white">{{ $inv['invoice_number'] }}</div>
                                            @if ($inv['used_by_other'])
                                                <span class="mt-1 inline-flex items-center rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold text-rose-800 dark:bg-rose-900/50 dark:text-rose-200">
                                                    Sudah digunakan: {{ $inv['other_event_name'] }}
                                                </span>
                                            @elseif ($inv['linked_to_current'])
                                                <span class="mt-1 inline-flex items-center rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200">
                                                    Sudah terhubung ke event ini
                                                </span>
                                            @elseif ($inv['referenced_by_recap'])
                                                <span class="mt-1 inline-flex items-center rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold text-blue-800 dark:bg-blue-900/50 dark:text-blue-200">
                                                    Sesuai Rekap Sesi
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 font-medium text-gray-800 dark:text-gray-200">
                                            {{ $inv['client_name'] }}
                                        </td>
                                        <td class="px-3 py-3 text-gray-900 dark:text-gray-100">
                                            {{ $inv['event_name'] }}
                                        </td>
                                        <td class="px-3 py-3">
                                            {{ $inv['event_date_label'] }}
                                        </td>
                                        <td class="px-3 py-3">
                                            {{ $inv['invoice_date_label'] }}
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
                                        <td class="px-3 py-3">
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                                                {{ $inv['status'] }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="py-8 text-center text-xs text-gray-400">
                                            Tidak ada invoice yang sesuai dengan kriteria pencarian/filter.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- Footer Modal --}}
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-800 dark:bg-gray-800/60 rounded-b-2xl">
                        <div class="text-xs text-gray-600 dark:text-gray-300">
                            Dipilih baru: <span class="font-bold text-gray-900 dark:text-white">{{ count($selectedIds) }} invoice</span>
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
                                wire:click="closeModal"
                                type="button"
                            >
                                Batal
                            </x-filament::button>

                            <x-filament::button
                                size="sm"
                                color="primary"
                                icon="heroicon-m-link"
                                wire:click="attachSelectedInvoices"
                                :disabled="count($selectedIds) === 0"
                                type="button"
                            >
                                Hubungkan Invoice ({{ count($selectedIds) }})
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
