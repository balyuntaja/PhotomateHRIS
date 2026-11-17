<div style="text-align: right;" x-data="{
    showApproveModal: @entangle('showApproveModal'),
    showRevisionModal: @entangle('showRevisionModal')
}">
    <span class="text-xs uppercase tracking-wider text-gray-500 font-bold block mb-1">Status</span>

    @if($report)
        @php
            $badgeClasses = match($report->status) {
                'APPROVED' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
                'WAITING_APPROVAL' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
                'REVISION' => 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
                default => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300',
            };
        @endphp

        <div class="flex items-center justify-end gap-2 flex-wrap">
            @if($canApprove)
                <x-filament::button
                    size="xs"
                    color="gray"
                    outlined
                    icon="heroicon-m-arrow-path"
                    x-on:click="showRevisionModal = true"
                    type="button"
                >
                    Revisi
                </x-filament::button>

                <x-filament::button
                    size="xs"
                    color="success"
                    icon="heroicon-m-check"
                    x-on:click="showApproveModal = true"
                    type="button"
                >
                    Approve
                </x-filament::button>
            @endif

            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $badgeClasses }}">
                {{ $report->status_label }}
            </span>
        </div>

        @if($canApprove)
            {{-- Modal Konfirmasi Approve --}}
            <div
                x-cloak
                x-show="showApproveModal"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-gray-950/60 p-4"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                x-on:click.self="showApproveModal = false"
                @keydown.escape.window="showApproveModal = false"
            >
                <div
                    class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-left space-y-4"
                    x-transition:enter="ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="ease-in duration-150"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                >
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400">
                            <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                                Setujui Rekap Sesi
                            </h3>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Apakah Anda yakin ingin menyetujui rekap sesi <span class="font-bold text-gray-900 dark:text-gray-100">#{{ $report->report_number }}</span> untuk tanggal <span class="font-semibold">{{ $report->report_date ? $report->report_date->translatedFormat('d F Y') : '-' }}</span>? Setelah disetujui, status rekap akan menjadi Approved, data bersifat tetap (immutable), dan transaksi keuangan akan otomatis dicatat.
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-gray-100 dark:border-gray-700">
                        <x-filament::button
                            color="gray"
                            size="sm"
                            outlined
                            x-on:click="showApproveModal = false"
                            type="button"
                        >
                            Batal
                        </x-filament::button>

                        <x-filament::button
                            color="success"
                            size="sm"
                            icon="heroicon-m-check"
                            wire:click="approve"
                            wire:loading.attr="disabled"
                            wire:target="approve"
                            type="button"
                        >
                            <span wire:loading.remove wire:target="approve">Ya, Setujui</span>
                            <span wire:loading wire:target="approve">Memproses...</span>
                        </x-filament::button>
                    </div>
                </div>
            </div>

            {{-- Modal Konfirmasi Revisi --}}
            <div
                x-cloak
                x-show="showRevisionModal"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-gray-950/60 p-4"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                x-on:click.self="showRevisionModal = false"
                @keydown.escape.window="showRevisionModal = false"
            >
                <div
                    class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-left space-y-4"
                    x-transition:enter="ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="ease-in duration-150"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                >
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-400">
                            <x-filament::icon icon="heroicon-o-arrow-path" class="h-6 w-6" />
                        </div>
                        <div class="flex-1">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                                Kembalikan Rekap untuk Revisi
                            </h3>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Rekap <span class="font-bold text-gray-900 dark:text-gray-100">#{{ $report->report_number }}</span> akan dikembalikan kepada crew bertugas untuk diperbaiki.
                            </p>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300">
                            Catatan / Alasan Revisi <span class="text-rose-500">*</span>
                        </label>
                        <textarea
                            wire:model="revisionReason"
                            rows="3"
                            placeholder="Contoh: Jumlah sesi QRIS tidak sesuai dengan bukti mutasi rekening event..."
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-xs text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-primary-500 focus:ring-1 focus:ring-primary-500"
                        ></textarea>
                        @error('revisionReason')
                            <p class="text-xs text-rose-600 dark:text-rose-400 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-gray-100 dark:border-gray-700">
                        <x-filament::button
                            color="gray"
                            size="sm"
                            outlined
                            x-on:click="showRevisionModal = false"
                            type="button"
                        >
                            Batal
                        </x-filament::button>

                        <x-filament::button
                            color="danger"
                            size="sm"
                            icon="heroicon-m-arrow-path"
                            wire:click="requestRevision"
                            wire:loading.attr="disabled"
                            wire:target="requestRevision"
                            type="button"
                        >
                            <span wire:loading.remove wire:target="requestRevision">Kirim Permintaan Revisi</span>
                            <span wire:loading wire:target="requestRevision">Mengirim...</span>
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
