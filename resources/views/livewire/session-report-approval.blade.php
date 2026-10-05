<div style="text-align: right;">
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

        <div class="flex items-center justify-end gap-2">
            @if($canApprove)
                <x-filament::button
                    size="xs"
                    color="success"
                    icon="heroicon-m-check"
                    wire:click="approve"
                    wire:loading.attr="disabled"
                    wire:target="approve"
                >
                    Approve
                </x-filament::button>
            @endif

            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $badgeClasses }}">
                {{ $report->status_label }}
            </span>
        </div>
    @endif
</div>
