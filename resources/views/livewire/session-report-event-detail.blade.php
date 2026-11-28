@php
    $displayEventName = $report?->resolved_event_name;
    $hasEventName = filled($displayEventName);
    $hasEventDate = $report?->event_date !== null;
    $hasAnyEvent = $hasEventName || $hasEventDate;
    $isComplete = $hasEventName && $hasEventDate;
@endphp

<div class="pt-3 border-t border-gray-200/80 dark:border-gray-700/80 space-y-3">
    <div class="flex items-center justify-between flex-wrap gap-2">
        <div class="flex items-center gap-2 flex-wrap">
            <span class="text-xs uppercase tracking-wider font-bold text-gray-500 dark:text-gray-400">
                Informasi Event
            </span>

            @if (! $hasAnyEvent)
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200 border border-amber-300 dark:border-amber-700">
                    Belum Ada Data Event
                </span>
            @elseif (! $isComplete)
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200 border border-amber-300 dark:border-amber-700">
                    Data Event Belum Lengkap
                </span>
            @else
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200 border border-emerald-300 dark:border-emerald-700">
                    Data Event Lengkap
                </span>
            @endif
        </div>

        @if ($canEdit && ! $isEditing)
            <x-filament::button
                size="xs"
                color="primary"
                outlined
                icon="heroicon-m-pencil-square"
                wire:click="openEdit"
                type="button"
            >
                Edit Detail Event
            </x-filament::button>
        @endif
    </div>

    @if ($isEditing && $canEdit)
        <form wire:submit="save" class="p-3.5 rounded-xl bg-white dark:bg-gray-900 border border-primary-200 dark:border-primary-800 shadow-sm space-y-3">
            {{ $this->form }}

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-gray-100 dark:border-gray-800">
                <x-filament::button
                    size="xs"
                    color="gray"
                    outlined
                    wire:click="cancelEdit"
                    type="button"
                >
                    Batal
                </x-filament::button>

                <x-filament::button
                    size="xs"
                    color="primary"
                    icon="heroicon-m-check"
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="save"
                >
                    <span wire:loading.remove wire:target="save">Simpan</span>
                    <span wire:loading wire:target="save">Menyimpan...</span>
                </x-filament::button>
            </div>
        </form>
    @else
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;" class="text-xs">
            <div>
                <span class="text-gray-500 block">Nama Event:</span>
                @if ($hasEventName)
                    <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $displayEventName }}</span>
                    @if ($report?->invoice)
                        <span class="block text-[11px] text-gray-500 dark:text-gray-400">
                            Invoice: {{ $report->invoice->invoice_number }}
                        </span>
                    @endif
                @else
                    <span class="italic text-gray-400 dark:text-gray-500">Belum diisi</span>
                @endif
            </div>

            <div>
                <span class="text-gray-500 block">Tanggal Event:</span>
                @if ($hasEventDate)
                    <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $report->event_date->translatedFormat('d F Y') }}</span>
                @else
                    <span class="italic text-gray-400 dark:text-gray-500">Belum diisi</span>
                @endif
            </div>
        </div>
    @endif
</div>
