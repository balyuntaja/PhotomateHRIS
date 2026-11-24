<div class="overflow-hidden bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse text-xs">
            <thead>
                <tr class="bg-gray-50/75 dark:bg-gray-700/50 text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">
                    <th class="py-3 px-4 font-semibold">No Rekap</th>
                    <th class="py-3 px-4 font-semibold">Tgl Operasional</th>
                    <th class="py-3 px-4 font-semibold">Cabang</th>
                    <th class="py-3 px-4 font-semibold text-right">Sesi</th>
                    <th class="py-3 px-4 font-semibold text-right">Lembar</th>
                    <th class="py-3 px-4 font-semibold text-right">Omzet</th>
                    <th class="py-3 px-4 font-semibold text-right">Tarif / Lembar</th>
                    <th class="py-3 px-4 font-semibold text-right">Total HPP</th>
                    <th class="py-3 px-4 font-semibold text-center">Status HPP</th>
                    <th class="py-3 px-4 font-semibold text-center">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                @forelse($records as $row)
                    <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/30 transition-colors">
                        <td class="py-3 px-4 font-mono font-medium text-gray-900 dark:text-white">
                            {{ $row->sessionReport?->report_number ?? "SR-{$row->session_report_id}" }}
                        </td>
                        <td class="py-3 px-4 text-gray-600 dark:text-gray-300">
                            {{ $row->report_date->format('d M Y') }}
                        </td>
                        <td class="py-3 px-4 text-gray-600 dark:text-gray-300">
                            {{ $row->cabang?->nama_cabang ?? $boothType }}
                        </td>
                        <td class="py-3 px-4 text-right font-medium text-gray-900 dark:text-white">
                            {{ number_format($row->total_sessions) }}
                        </td>
                        <td class="py-3 px-4 text-right font-bold text-gray-900 dark:text-white">
                            {{ number_format($row->total_sheets) }}
                        </td>
                        <td class="py-3 px-4 text-right font-mono text-gray-700 dark:text-gray-300">
                            {{ \App\Utils\RupiahHelper::format($row->revenue) }}
                        </td>
                        <td class="py-3 px-4 text-right font-mono font-semibold text-gray-900 dark:text-white">
                            {{ $row->formatted_rate }}
                        </td>
                        <td class="py-3 px-4 text-right font-mono font-bold {{ $row->isConfirmed() ? 'text-primary-600 dark:text-primary-400' : 'text-gray-500' }}">
                            {{ $row->formatted_total_hpp }}
                        </td>
                        <td class="py-3 px-4 text-center">
                            @if($row->status === \App\Models\BoothSessionHpp::STATUS_POSTED)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300">
                                    <x-heroicon-m-check-circle class="w-3.5 h-3.5 mr-1 text-emerald-500" />
                                    Posted
                                </span>
                            @elseif($row->status === \App\Models\BoothSessionHpp::STATUS_CONFIRMED)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300">
                                    <x-heroicon-m-check class="w-3.5 h-3.5 mr-1 text-blue-500" />
                                    Confirmed
                                </span>
                            @elseif($row->status === \App\Models\BoothSessionHpp::STATUS_CALCULATED)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">
                                    <x-heroicon-m-calculator class="w-3.5 h-3.5 mr-1 text-amber-500" />
                                    Draft
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                    <x-heroicon-m-clock class="w-3.5 h-3.5 mr-1 text-gray-400" />
                                    Pending
                                </span>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-center">
                            <div class="inline-flex items-center justify-center gap-1.5">
                                {{-- Set Tarif Button --}}
                                @if(! $row->isPosted())
                                    <button
                                        wire:click="openTariffModal({{ $row->id }})"
                                        type="button"
                                        title="Ubah Tarif per Lembar"
                                        class="pm-icon-btn hover:text-primary-600 dark:hover:text-primary-400"
                                    >
                                        <x-heroicon-m-pencil-square class="w-4 h-4" />
                                    </button>
                                @endif

                                {{-- Konfirmasi Button --}}
                                @if(! $row->isConfirmed() && $row->hpp_rate_per_sheet !== null)
                                    <button
                                        wire:click="confirmBoothHpp({{ $row->id }})"
                                        type="button"
                                        title="Konfirmasi HPP"
                                        class="pm-icon-btn text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-950"
                                    >
                                        <x-heroicon-m-check-badge class="w-4 h-4" />
                                    </button>
                                @endif

                                {{-- Post ke Akuntansi Button --}}
                                @if($row->isConfirmed() && ! $row->isPosted())
                                    <button
                                        wire:click="postBoothHpp({{ $row->id }})"
                                        type="button"
                                        title="Post ke Akuntansi (Financial Transaction)"
                                        class="pm-btn pm-btn-primary pm-btn-xs"
                                    >
                                        <x-heroicon-m-arrow-up-tray class="w-3.5 h-3.5" />
                                        Post
                                    </button>
                                @endif

                                {{-- Buka Kembali Button --}}
                                @if($row->isConfirmed())
                                    <button
                                        wire:click="openReopenModal({{ $row->id }})"
                                        type="button"
                                        title="Buka Kembali (Reopen)"
                                        class="pm-icon-btn text-amber-600 hover:text-amber-700 hover:bg-amber-50 dark:hover:bg-amber-950"
                                    >
                                        <x-heroicon-m-arrow-path-rounded-square class="w-4 h-4" />
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="py-8 text-center text-gray-400 dark:text-gray-500">
                            <x-heroicon-o-inbox class="w-8 h-8 mx-auto mb-2 opacity-50" />
                            <p class="text-xs">Tidak ada data rekap sesi {{ $boothType }} pada periode ini.</p>
                            <p class="text-[11px] mt-1 text-gray-400">Pastikan rekap sesi operasional berstatus Approved.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if($records->isNotEmpty())
                <tfoot class="bg-gray-50/75 dark:bg-gray-700/50 border-t border-gray-200 dark:border-gray-700 font-bold text-gray-900 dark:text-white">
                    <tr>
                        <td colspan="3" class="py-3 px-4">TOTAL {{ strtoupper($boothType) }}</td>
                        <td class="py-3 px-4 text-right">{{ number_format($records->sum('total_sessions')) }}</td>
                        <td class="py-3 px-4 text-right">{{ number_format($records->sum('total_sheets')) }}</td>
                        <td class="py-3 px-4 text-right font-mono">{{ \App\Utils\RupiahHelper::format($records->sum('revenue')) }}</td>
                        <td class="py-3 px-4 text-right font-mono">-</td>
                        <td class="py-3 px-4 text-right font-mono text-primary-600 dark:text-primary-400">
                            {{ \App\Utils\RupiahHelper::format($records->whereIn('status', ['confirmed', 'posted'])->sum('total_hpp')) }}
                        </td>
                        <td colspan="2" class="py-3 px-4 text-center text-xs text-gray-500 font-normal">
                            {{ $records->whereIn('status', ['confirmed', 'posted'])->count() }} / {{ $records->count() }} Terkonfirmasi
                        </td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
