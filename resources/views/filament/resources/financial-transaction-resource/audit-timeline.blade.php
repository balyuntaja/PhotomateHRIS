<div class="space-y-3">
    @forelse ($logs as $log)
        <x-filament::card>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <x-filament::badge :color="$log->action_color">
                    {{ $log->action_label }}
                </x-filament::badge>

                <span class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $log->user?->nama_lengkap ?? 'Sistem' }} &middot; {{ $log->created_at?->format('d/m/Y H:i') }}
                </span>
            </div>

            @if (! empty($log->changes))
                <div class="mt-3 space-y-2">
                    @foreach ($log->changes as $change)
                        <div class="text-sm">
                            <div class="font-medium text-gray-700 dark:text-gray-200">{{ $change['label'] }}</div>
                            <div class="text-gray-600 dark:text-gray-300">
                                @if ($change['old'] === null)
                                    <span class="font-medium text-success-600 dark:text-success-400">{{ $change['new'] }}</span>
                                @elseif ($change['new'] === null)
                                    <span class="text-danger-600 dark:text-danger-400" style="text-decoration: line-through;">{{ $change['old'] }}</span>
                                @else
                                    <span class="text-danger-600 dark:text-danger-400" style="text-decoration: line-through;">{{ $change['old'] }}</span>
                                    <span class="mx-1 text-gray-400">&rarr;</span>
                                    <span class="font-medium text-success-600 dark:text-success-400">{{ $change['new'] }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::card>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Belum ada riwayat perubahan untuk transaksi ini.
        </p>
    @endforelse
</div>
