<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Navigation Header -->
        <div class="flex items-center justify-between p-4 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
            <button
                type="button"
                wire:click="prevWeek"
                class="inline-flex items-center justify-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-xs hover:bg-gray-50 dark:hover:bg-gray-800 transition"
            >
                <x-heroicon-m-chevron-left class="w-5 h-5" />
                Minggu Sebelumnya
            </button>

            <span class="text-lg font-bold text-gray-900 dark:text-white">
                {{ $weekLabel }}
            </span>

            <button
                type="button"
                wire:click="nextWeek"
                class="inline-flex items-center justify-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-xs hover:bg-gray-50 dark:hover:bg-gray-800 transition"
            >
                Minggu Berikutnya
                <x-heroicon-m-chevron-right class="w-5 h-5" />
            </button>
        </div>

        <!-- Weekly Schedule Table Grid -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700">
                            <th class="px-6 py-4 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 min-w-[200px]">
                                Nama Karyawan
                            </th>
                            @foreach ($days as $day)
                                <th class="px-4 py-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 min-w-[120px]">
                                    <div class="font-bold">{{ $day->translatedFormat('D') }}</div>
                                    <div class="text-[10px] text-gray-400">{{ $day->translatedFormat('d M') }}</div>
                                </th>
                            @endforeach
                            <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 min-w-[100px]">
                                Total Shift
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($employees as $employee)
                            <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-900/50 transition">
                                <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">
                                    {{ $employee->nama_lengkap }}
                                    <div class="text-[10px] text-gray-400 font-normal">
                                        {{ $employee->jabatan ?? 'Staf Booth' }}
                                    </div>
                                </td>
                                @foreach ($days as $day)
                                    @php
                                        $dateStr = $day->toDateString();
                                        $shift = $scheduleGrid[$employee->karyawan_id][$dateStr] ?? null;
                                    @endphp
                                    <td class="px-3 py-4 text-center vertical-middle">
                                        @if ($shift)
                                            @php
                                                $badgeClass = match ($shift->shift_type) {
                                                    '08:00 - 16:00' => 'bg-emerald-50 dark:bg-emerald-950/30 text-emerald-700 dark:text-emerald-400 border border-emerald-200/50 dark:border-emerald-800/30',
                                                    '16:00 - 24:00' => 'bg-sky-50 dark:bg-sky-950/30 text-sky-700 dark:text-sky-400 border border-sky-200/50 dark:border-sky-800/30',
                                                    '18:00 - 24:00' => 'bg-amber-50 dark:bg-amber-950/30 text-amber-700 dark:text-amber-400 border border-amber-200/50 dark:border-amber-800/30',
                                                    '08:00 - 24:00' => 'bg-rose-50 dark:bg-rose-950/30 text-rose-700 dark:text-rose-400 border border-rose-200/50 dark:border-rose-800/30',
                                                    default => 'bg-gray-50 dark:bg-gray-900/50 text-gray-700 dark:text-gray-300 border border-gray-200/50 dark:border-gray-700/30',
                                                };
                                                $shiftLabel = $shift->shift_type;
                                            @endphp
                                            <div class="inline-flex flex-col items-center justify-center p-2.5 rounded-lg {{ $badgeClass }} w-full text-xs shadow-xs">
                                                <span class="font-bold leading-normal">{{ $shiftLabel }}</span>
                                                @if ($shift->booth_location)
                                                    <span class="mt-1 text-[10px] opacity-90 truncate max-w-[110px]" title="{{ $shift->booth_location }}">
                                                        📍 {{ $shift->booth_location }}
                                                    </span>
                                                @endif
                                            </div>
                                        @else
                                            <span class="text-gray-300 dark:text-gray-600">-</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-6 py-4 text-center font-bold text-gray-900 dark:text-white">
                                    {{ $employeeShiftCounts[$employee->karyawan_id] }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-6 py-8 text-center text-gray-400">
                                    Tidak ada data karyawan ditemukan.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
