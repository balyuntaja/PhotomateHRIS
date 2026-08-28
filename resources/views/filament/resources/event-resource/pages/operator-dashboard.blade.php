<x-filament::page>
    <div wire:poll.3s class="space-y-6">
        <!-- Event Status Controls & Actions -->
        <x-filament::card class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h3 class="text-sm font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Status Antrean Event</h3>
                <div class="flex items-center gap-2 mt-1">
                    <span class="inline-block w-3.5 h-3.5 rounded-full {{ 
                        $record->status === 'OPEN' ? 'bg-emerald-500 animate-pulse' :
                        ($record->status === 'PAUSED' ? 'bg-amber-500' : 'bg-red-500')
                    }}"></span>
                    <span class="font-extrabold text-gray-900 dark:text-white text-lg">
                        {{ $record->status === 'DRAFT' ? 'DRAFT (Belum Dibuka)' :
                           ($record->status === 'OPEN' ? 'OPEN (Antrean Dibuka)' :
                           ($record->status === 'PAUSED' ? 'PAUSED (Ditangguhkan)' : 'CLOSED (Ditutup)')) 
                        }}
                    </span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-filament::button color="success" size="sm" wire:click="changeEventStatus('OPEN')" class="{{ $record->status === 'OPEN' ? 'ring-2 ring-emerald-500 ring-offset-2' : '' }}">
                    Buka Antrean
                </x-filament::button>
                <x-filament::button color="warning" size="sm" wire:click="changeEventStatus('PAUSED')" class="{{ $record->status === 'PAUSED' ? 'ring-2 ring-amber-500 ring-offset-2' : '' }}">
                    Jeda Antrean
                </x-filament::button>
                <x-filament::button color="danger" size="sm" wire:click="changeEventStatus('CLOSED')" class="{{ $record->status === 'CLOSED' ? 'ring-2 ring-red-500 ring-offset-2' : '' }}">
                    Tutup Antrean
                </x-filament::button>
                <x-filament::button color="gray" size="sm" icon="heroicon-m-computer-desktop" href="{{ url('/queue/' . $record->event_code . '/display') }}" tag="a" target="_blank">
                    Layar TV
                </x-filament::button>
            </div>
        </x-filament::card>

        <!-- Stats Cards Grid -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <x-filament::card class="p-4 text-center">
                <p class="text-xs text-gray-400 dark:text-gray-500 font-extrabold uppercase">Daftar Tunggu</p>
                <p class="text-3xl font-black text-primary-600 dark:text-primary-400 mt-1">{{ $stats['waiting'] }}</p>
            </x-filament::card>
            <x-filament::card class="p-4 text-center">
                <p class="text-xs text-gray-400 dark:text-gray-500 font-extrabold uppercase">Dipanggil</p>
                <p class="text-3xl font-black text-amber-600 mt-1">{{ $stats['called'] }}</p>
            </x-filament::card>
            <x-filament::card class="p-4 text-center">
                <p class="text-xs text-gray-400 dark:text-gray-500 font-extrabold uppercase">Sedang Foto</p>
                <p class="text-3xl font-black text-emerald-600 mt-1">{{ $stats['serving'] }}</p>
            </x-filament::card>
            <x-filament::card class="p-4 text-center">
                <p class="text-xs text-gray-400 dark:text-gray-500 font-extrabold uppercase">Selesai</p>
                <p class="text-3xl font-black text-gray-500 mt-1">{{ $stats['completed'] }}</p>
            </x-filament::card>
            <x-filament::card class="p-4 text-center col-span-2 md:col-span-1">
                <p class="text-xs text-gray-400 dark:text-gray-500 font-extrabold uppercase">Terlewat</p>
                <p class="text-3xl font-black text-red-500 mt-1">{{ $stats['skipped'] }}</p>
            </x-filament::card>
        </div>

        <!-- Devices Section -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            @for ($d = 1; $d <= 2; $d++)
                @php
                    $deviceServing = $nowServing->firstWhere('device_id', $d);
                    $deviceCalled = $nowCalled->firstWhere('device_id', $d);
                    $activeEntry = $deviceServing ?? $deviceCalled;
                @endphp
                <x-filament::card class="relative flex flex-col justify-between min-h-[320px] p-6">
                    <div class="absolute top-4 left-6 bg-primary-50 dark:bg-primary-950/20 text-primary-600 dark:text-primary-400 border border-primary-200 dark:border-primary-800 text-xs font-bold py-1 px-3 rounded-full">
                        DEVICE 0{{ $d }}
                    </div>

                    @if ($activeEntry)
                        <div class="my-auto text-center py-6 space-y-4">
                            <p class="text-xs uppercase font-extrabold tracking-widest text-gray-400 dark:text-gray-500">
                                {{ $activeEntry->status === 'CALLED' ? 'Sedang Dipanggil' : 'Sedang Difoto' }}
                            </p>
                            <h2 class="text-6xl font-black text-gray-900 dark:text-white tracking-tight">{{ $activeEntry->formatted_number }}</h2>
                            <div class="space-y-1">
                                <p class="text-lg font-bold text-gray-800 dark:text-gray-200">{{ $activeEntry->customer->name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 font-medium">{{ $activeEntry->customer->whatsapp }} &bull; {{ $activeEntry->customer->email }}</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2 border-t border-gray-100 dark:border-gray-800 pt-4">
                            @if ($activeEntry->status === 'CALLED')
                                <x-filament::button color="success" size="md" class="col-span-2 flex items-center justify-center gap-1" wire:click="startServing({{ $activeEntry->id }})">
                                    Mulai Melayani (Mulai Foto)
                                </x-filament::button>
                            @else
                                <x-filament::button color="success" size="md" class="col-span-2 flex items-center justify-center gap-1" wire:click="completeServing({{ $activeEntry->id }})">
                                    Selesai Berfoto
                                </x-filament::button>
                            @endif
                            <x-filament::button color="danger" size="sm" outlined wire:click="skipEntry({{ $activeEntry->id }})">
                                Lewati / Skip
                            </x-filament::button>
                            <x-filament::button color="gray" size="sm" outlined wire:click="cancelEntry({{ $activeEntry->id }})">
                                Batalkan
                            </x-filament::button>
                        </div>
                    @else
                        <div class="my-auto text-center py-10 space-y-4">
                            <div class="w-14 h-14 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mx-auto text-gray-400 dark:text-gray-500">
                                <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                            </div>
                            <div class="space-y-1">
                                <h4 class="font-bold text-gray-600 dark:text-gray-400">Device Sedang Kosong</h4>
                                <p class="text-xs text-gray-400 dark:text-gray-500">Silakan panggil antrean berikutnya untuk melayani.</p>
                            </div>
                        </div>

                        <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
                            <x-filament::button color="primary" class="w-full flex items-center justify-center gap-1.5" wire:click="callNext({{ $d }})">
                                Panggil Antrean Berikutnya
                            </x-filament::button>
                        </div>
                    @endif
                </x-filament::card>
            @endfor
        </div>

        <!-- Waiting Queue & History tabs -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Left 2 cols: Waiting List -->
            <x-filament::card class="md:col-span-2 space-y-4">
                <h3 class="text-base font-extrabold text-gray-950 dark:text-white flex items-center gap-2">
                    <span class="w-2.5 h-2.5 bg-primary-600 dark:bg-primary-400 rounded-full"></span>
                    Daftar Tunggu Antrean ({{ $waitingList->count() }} orang)
                </h3>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-750 text-left text-xs">
                        <thead class="bg-gray-50 dark:bg-gray-900">
                            <tr class="text-gray-500 dark:text-gray-400 uppercase font-bold tracking-wider">
                                <th class="py-3 px-4">No</th>
                                <th class="py-3 px-4">Customer</th>
                                <th class="py-3 px-4">Kontak</th>
                                <th class="py-3 px-4 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800 font-medium text-gray-700 dark:text-gray-300">
                            @forelse ($waitingList as $index => $item)
                                <tr>
                                    <td class="py-3.5 px-4 font-extrabold text-primary-600 dark:text-primary-400 text-sm">{{ $item->formatted_number }}</td>
                                    <td class="py-3.5 px-4">
                                        <div class="text-gray-955 dark:text-white font-bold text-sm">{{ $item->customer->name }}</div>
                                        <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Mendaftar: {{ $item->joined_at ? $item->joined_at->diffForHumans() : '-' }}</div>
                                    </td>
                                    <td class="py-3.5 px-4 space-y-0.5 text-gray-500 dark:text-gray-400">
                                        <div>{{ $item->customer->whatsapp }}</div>
                                        <div class="text-[10px]">{{ $item->customer->email }}</div>
                                    </td>
                                    <td class="py-3.5 px-4 text-right">
                                        <div class="flex justify-end gap-1.5">
                                            <x-filament::button color="danger" size="xs" outlined wire:click="skipEntry({{ $item->id }})">
                                                Skip
                                            </x-filament::button>
                                            <x-filament::button color="gray" size="xs" outlined wire:click="cancelEntry({{ $item->id }})">
                                                Cancel
                                            </x-filament::button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-8 text-center text-gray-400 dark:text-gray-650 font-medium">Belum ada customer di daftar antrean.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::card>

            <!-- Right 1 col: Recent History -->
            <x-filament::card class="space-y-6">
                <!-- Completed History -->
                <div>
                    <h3 class="text-sm font-extrabold text-gray-900 dark:text-white flex items-center gap-2 mb-3">
                        <span class="w-2.5 h-2.5 bg-emerald-500 rounded-full"></span>
                        Selesai Berfoto (10 Terakhir)
                    </h3>
                    <div class="space-y-2">
                        @forelse ($completedList as $item)
                            <div class="p-3 bg-gray-50 dark:bg-gray-950/50 border border-gray-100 dark:border-gray-800 rounded-xl flex justify-between items-center text-xs">
                                <div>
                                    <div class="font-bold text-gray-900 dark:text-white">{{ $item->customer->name }}</div>
                                    <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Device {{ $item->device_id }} &bull; Selesai {{ $item->completed_at ? $item->completed_at->diffForHumans() : '-' }}</div>
                                </div>
                                <div class="font-black text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-100 dark:border-emerald-500/20 rounded-lg px-2 py-1 text-xs">
                                    {{ $item->formatted_number }}
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-gray-400 dark:text-gray-650 text-center py-4">Belum ada antrean selesai.</p>
                        @endforelse
                    </div>
                </div>

                <hr class="border-gray-100 dark:border-gray-800" />

                <!-- Skipped History -->
                <div>
                    <h3 class="text-sm font-extrabold text-gray-900 dark:text-white flex items-center gap-2 mb-3">
                        <span class="w-2.5 h-2.5 bg-red-500 rounded-full"></span>
                        Terlewat / Dilewati
                    </h3>
                    <div class="space-y-2">
                        @forelse ($skippedList as $item)
                            <div class="p-3 bg-gray-50 dark:bg-gray-950/50 border border-gray-100 dark:border-gray-800 rounded-xl flex justify-between items-center text-xs">
                                <div>
                                    <div class="font-bold text-gray-900 dark:text-white">{{ $item->customer->name }}</div>
                                    <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Dilewati {{ $item->skipped_at ? $item->skipped_at->diffForHumans() : '-' }}</div>
                                </div>
                                <div class="font-black text-red-500 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg px-2 py-1 text-xs">
                                    {{ $item->formatted_number }}
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-gray-400 dark:text-gray-650 text-center py-4">Belum ada antrean dilewati.</p>
                        @endforelse
                    </div>
                </div>
            </x-filament::card>
        </div>
    </div>
</x-filament::page>
