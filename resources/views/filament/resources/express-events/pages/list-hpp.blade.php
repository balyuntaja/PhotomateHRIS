<x-filament-panels::page>
    <div class="space-y-6">

        {{-- 1. Control Bar: Periode Filter & Sinkronisasi --}}
        <div class="flex flex-wrap items-center justify-between gap-4 p-4 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="flex items-center space-x-3">
                <div class="p-2 bg-primary-50 dark:bg-primary-950 text-primary-600 dark:text-primary-400 rounded-lg">
                    <x-heroicon-m-calendar-days class="w-6 h-6" />
                </div>
                <div>
                    <h2 class="text-base font-bold text-gray-900 dark:text-white">Periode Laporan HPP</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Pilih bulan dan tahun operasional rekap sesi</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <select wire:model.live="filterMonth" class="text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white py-1.5 px-3 focus:ring-primary-500 focus:border-primary-500">
                    @foreach(range(1, 12) as $m)
                        <option value="{{ $m }}">{{ \Carbon\Carbon::create(null, $m, 1)->translatedFormat('F') }}</option>
                    @endforeach
                </select>

                <select wire:model.live="filterYear" class="text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white py-1.5 px-3 focus:ring-primary-500 focus:border-primary-500">
                    @foreach(range(now()->year - 5, now()->year + 1) as $y)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endforeach
                </select>

                <button
                    wire:click="syncRecaps"
                    type="button"
                    class="pm-btn pm-btn-secondary pm-btn-xs"
                >
                    <x-heroicon-m-arrow-path class="w-4 h-4 text-primary-600 dark:text-primary-400" />
                    Sinkronkan Rekap Baru
                </button>
            </div>
        </div>

        {{-- 2. Segmented Navigation Tabs --}}
        <div class="border-b border-gray-200 dark:border-gray-700">
            <nav class="-mb-px flex space-x-6 overflow-x-auto" aria-label="Tabs">
                <button
                    wire:click="setTab('all')"
                    type="button"
                    class="{{ $activeTab === 'all' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' }} whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center gap-2"
                >
                    <x-heroicon-m-squares-2x2 class="w-5 h-5" />
                    <span>Semua Cabang</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                        Total: {{ \App\Utils\RupiahHelper::format($this->consolidatedSummary['total_hpp_all']) }}
                    </span>
                </button>

                <button
                    wire:click="setTab('express')"
                    type="button"
                    class="{{ $activeTab === 'express' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' }} whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center gap-2"
                >
                    <x-heroicon-m-sparkles class="w-5 h-5" />
                    <span>Express</span>
                    <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300">
                        Event-based
                    </span>
                </button>

                <button
                    wire:click="setTab('janus')"
                    type="button"
                    class="{{ $activeTab === 'janus' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' }} whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center gap-2"
                >
                    <x-heroicon-m-newspaper class="w-5 h-5" />
                    <span>Janus</span>
                    <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300">
                        Per Lembar
                    </span>
                    @if($this->janusRecords->where('status', '!=', 'confirmed')->where('status', '!=', 'posted')->count() > 0)
                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                    @endif
                </button>

                <button
                    wire:click="setTab('golio')"
                    type="button"
                    class="{{ $activeTab === 'golio' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' }} whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center gap-2"
                >
                    <x-heroicon-m-camera class="w-5 h-5" />
                    <span>Golio</span>
                    <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-purple-100 text-purple-800 dark:bg-purple-900/50 dark:text-purple-300">
                        Per Lembar
                    </span>
                    @if($this->golioRecords->where('status', '!=', 'confirmed')->where('status', '!=', 'posted')->count() > 0)
                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                    @endif
                </button>
            </nav>
        </div>

        {{-- 3. Tab Contents --}}

        {{-- TAB 1: SEMUA CABANG --}}
        @if($activeTab === 'all')
            <div class="space-y-6">
                {{-- Comparison Grid 3 Cabang --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {{-- Express Card --}}
                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 shadow-sm space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300">
                                Photomate Express
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">Metode: Berbasis Event</span>
                        </div>
                        <div>
                            <div class="text-2xl font-black text-gray-900 dark:text-white">
                                {{ \App\Utils\RupiahHelper::format($this->consolidatedSummary['total_hpp_express']) }}
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Total HPP Dikonfirmasi Bulan Ini</p>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-xs text-gray-600 dark:text-gray-300 pt-2 border-t border-gray-100 dark:border-gray-700">
                            <div>Total Lembar: <span class="font-bold text-gray-900 dark:text-white">{{ number_format($this->consolidatedSummary['branches']['Express']['total_sheets'] ?? 0) }}</span></div>
                            <div>Total Sesi: <span class="font-bold text-gray-900 dark:text-white">{{ number_format($this->consolidatedSummary['branches']['Express']['total_sessions'] ?? 0) }}</span></div>
                            <div>Confirmed: <span class="font-bold text-emerald-600">{{ $this->consolidatedSummary['branches']['Express']['confirmed_count'] ?? 0 }} Event</span></div>
                            <div>Pending: <span class="font-bold text-amber-600">{{ $this->consolidatedSummary['branches']['Express']['pending_count'] ?? 0 }} Event</span></div>
                        </div>
                        <button wire:click="setTab('express')" type="button" class="pm-btn pm-btn-secondary pm-btn-xs w-full mt-2">
                            Kelola HPP Express &rarr;
                        </button>
                    </div>

                    {{-- Janus Card --}}
                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 shadow-sm space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300">
                                Newspaper Janus
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">Metode: Per Lembar Sesi</span>
                        </div>
                        <div>
                            <div class="text-2xl font-black text-gray-900 dark:text-white">
                                {{ \App\Utils\RupiahHelper::format($this->consolidatedSummary['total_hpp_janus']) }}
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Total HPP Dikonfirmasi Bulan Ini</p>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-xs text-gray-600 dark:text-gray-300 pt-2 border-t border-gray-100 dark:border-gray-700">
                            <div>Total Lembar: <span class="font-bold text-gray-900 dark:text-white">{{ number_format($this->consolidatedSummary['branches']['Janus']['total_sheets'] ?? 0) }}</span></div>
                            <div>Total Sesi: <span class="font-bold text-gray-900 dark:text-white">{{ number_format($this->consolidatedSummary['branches']['Janus']['total_sessions'] ?? 0) }}</span></div>
                            <div>Confirmed: <span class="font-bold text-emerald-600">{{ $this->consolidatedSummary['branches']['Janus']['confirmed_count'] ?? 0 }} Rekap</span></div>
                            <div>Pending: <span class="font-bold text-amber-600">{{ $this->consolidatedSummary['branches']['Janus']['pending_count'] ?? 0 }} Rekap</span></div>
                        </div>
                        <button wire:click="setTab('janus')" type="button" class="pm-btn pm-btn-secondary pm-btn-xs w-full mt-2">
                            Kelola HPP Janus &rarr;
                        </button>
                    </div>

                    {{-- Golio Card --}}
                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 shadow-sm space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-semibold bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300">
                                Photomate Golio
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">Metode: Per Lembar Sesi</span>
                        </div>
                        <div>
                            <div class="text-2xl font-black text-gray-900 dark:text-white">
                                {{ \App\Utils\RupiahHelper::format($this->consolidatedSummary['total_hpp_golio']) }}
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Total HPP Dikonfirmasi Bulan Ini</p>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-xs text-gray-600 dark:text-gray-300 pt-2 border-t border-gray-100 dark:border-gray-700">
                            <div>Total Lembar: <span class="font-bold text-gray-900 dark:text-white">{{ number_format($this->consolidatedSummary['branches']['Golio']['total_sheets'] ?? 0) }}</span></div>
                            <div>Total Sesi: <span class="font-bold text-gray-900 dark:text-white">{{ number_format($this->consolidatedSummary['branches']['Golio']['total_sessions'] ?? 0) }}</span></div>
                            <div>Confirmed: <span class="font-bold text-emerald-600">{{ $this->consolidatedSummary['branches']['Golio']['confirmed_count'] ?? 0 }} Rekap</span></div>
                            <div>Pending: <span class="font-bold text-amber-600">{{ $this->consolidatedSummary['branches']['Golio']['pending_count'] ?? 0 }} Rekap</span></div>
                        </div>
                        <button wire:click="setTab('golio')" type="button" class="pm-btn pm-btn-secondary pm-btn-xs w-full mt-2">
                            Kelola HPP Golio &rarr;
                        </button>
                    </div>
                </div>

                {{-- Consolidated Records Table --}}
                <div class="overflow-hidden bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm space-y-3 p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-gray-900 dark:text-white">Rincian Seluruh Catatan HPP Periode Ini</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Konsolidasi event Express dan rekap operasional Janus serta Golio</p>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/75 dark:bg-gray-700/50 text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">
                                    <th class="py-2.5 px-3 font-semibold">Cabang</th>
                                    <th class="py-2.5 px-3 font-semibold">Event / Rekap</th>
                                    <th class="py-2.5 px-3 font-semibold">Tanggal</th>
                                    <th class="py-2.5 px-3 font-semibold text-right">Lembar</th>
                                    <th class="py-2.5 px-3 font-semibold text-right">Tarif / Lembar</th>
                                    <th class="py-2.5 px-3 font-semibold text-right">Total HPP</th>
                                    <th class="py-2.5 px-3 font-semibold text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                                @forelse($this->consolidatedRecords as $item)
                                    <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/30">
                                        <td class="py-2.5 px-3 font-semibold">
                                            @if($item['type'] === 'Express')
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300">Express</span>
                                            @elseif($item['type'] === 'Janus')
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300">Janus</span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300">Golio</span>
                                            @endif
                                        </td>
                                        <td class="py-2.5 px-3 font-medium text-gray-900 dark:text-white">
                                            {{ $item['title'] }}
                                        </td>
                                        <td class="py-2.5 px-3 text-gray-600 dark:text-gray-300">
                                            {{ $item['date'] }}
                                        </td>
                                        <td class="py-2.5 px-3 text-right font-bold text-gray-900 dark:text-white">
                                            {{ number_format($item['sheets']) }}
                                        </td>
                                        <td class="py-2.5 px-3 text-right font-mono text-gray-700 dark:text-gray-300">
                                            {{ $item['rate'] }}
                                        </td>
                                        <td class="py-2.5 px-3 text-right font-mono font-bold text-primary-600 dark:text-primary-400">
                                            {{ $item['total_hpp'] }}
                                        </td>
                                        <td class="py-2.5 px-3 text-center">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                                {{ $item['status'] }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="py-6 text-center text-gray-400 text-xs">
                                            Belum ada catatan HPP pada periode ini.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Information Guide --}}
                <div class="bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 rounded-xl p-4 text-xs text-gray-600 dark:text-gray-300 space-y-2">
                    <div class="font-bold text-gray-900 dark:text-white text-sm flex items-center gap-1.5">
                        <x-heroicon-m-information-circle class="w-4 h-4 text-primary-500" />
                        Panduan Metode Perhitungan HPP per Cabang
                    </div>
                    <ul class="list-disc list-inside space-y-1 pl-1">
                        <li><strong>Photomate Express:</strong> Menggunakan tarif HPP per event. Satu event dapat menaungi multi-rekap sesi, dan satu rekap sesi dapat dialokasikan ke banyak event. Tarif HPP ditentukan per lembar untuk setiap event.</li>
                        <li><strong>Newspaper Janus & Golio:</strong> Menggunakan tarif HPP tetap per lembar cetak yang diinput langsung dari rekap sesi operasional yang telah disetujui (Approved).</li>
                        <li><strong>Pengakuan Akuntansi:</strong> Seluruh nominal HPP yang dikonfirmasi diposting secara idempoten ke modul Akuntansi (Kategori Pengeluaran Persediaan / Biaya Pokok) sesuai tanggal operasional rekap sesi.</li>
                    </ul>
                </div>
            </div>
        @endif

        {{-- TAB 2: EXPRESS (EVENT-BASED) --}}
        @if($activeTab === 'express')
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-base font-bold text-gray-900 dark:text-white">Daftar Event Express</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Pengelolaan HPP berbasis event dan alokasi lembar sesi Express</p>
                    </div>
                </div>

                {{-- Render the Filament Table for Express Events --}}
                <div class="overflow-hidden bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                    {{ $this->table }}
                </div>
            </div>
        @endif

        {{-- TAB 3: JANUS (PER LEMBAR) --}}
        @if($activeTab === 'janus')
            <div class="space-y-4">
                {{-- Header Actions Bar --}}
                <div class="flex flex-wrap items-center justify-between gap-3 p-4 bg-emerald-50/60 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 rounded-xl">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-emerald-500 text-white rounded-lg">
                            <x-heroicon-m-newspaper class="w-5 h-5" />
                        </div>
                        <div>
                            <div class="text-sm font-bold text-emerald-900 dark:text-emerald-100">
                                Tarif Standar Janus: Rp {{ number_format($this->standardRates['Janus'], 0, ',', '.') }} / lembar
                            </div>
                            <div class="text-xs text-emerald-700 dark:text-emerald-300">
                                Diterapkan otomatis untuk rekap sesi baru
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            wire:click="openStandardRateModal('Janus')"
                            type="button"
                            class="pm-btn pm-btn-outline pm-btn-xs"
                        >
                            <x-heroicon-m-cog-6-tooth class="w-4 h-4 mr-1.5" />
                            Atur Tarif Standar
                        </button>

                        <button
                            wire:click="bulkConfirmBooth('Janus')"
                            type="button"
                            class="pm-btn pm-btn-success pm-btn-xs"
                        >
                            <x-heroicon-m-check-badge class="w-4 h-4 mr-1.5" />
                            Konfirmasi Semua yang Siap
                        </button>
                    </div>
                </div>

                {{-- Table Rekap Sesi Janus --}}
                @include('filament.resources.express-events.partials.booth-table', ['records' => $this->janusRecords, 'boothType' => 'Janus'])
            </div>
        @endif

        {{-- TAB 4: GOLIO (PER LEMBAR) --}}
        @if($activeTab === 'golio')
            <div class="space-y-4">
                {{-- Header Actions Bar --}}
                <div class="flex flex-wrap items-center justify-between gap-3 p-4 bg-purple-50/60 dark:bg-purple-950/30 border border-purple-200 dark:border-purple-800 rounded-xl">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-purple-500 text-white rounded-lg">
                            <x-heroicon-m-camera class="w-5 h-5" />
                        </div>
                        <div>
                            <div class="text-sm font-bold text-purple-900 dark:text-purple-100">
                                Tarif Standar Golio: Rp {{ number_format($this->standardRates['Golio'], 0, ',', '.') }} / lembar
                            </div>
                            <div class="text-xs text-purple-700 dark:text-purple-300">
                                Diterapkan otomatis untuk rekap sesi baru
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            wire:click="openStandardRateModal('Golio')"
                            type="button"
                            class="pm-btn pm-btn-outline pm-btn-xs"
                        >
                            <x-heroicon-m-cog-6-tooth class="w-4 h-4 mr-1.5" />
                            Atur Tarif Standar
                        </button>

                        <button
                            wire:click="bulkConfirmBooth('Golio')"
                            type="button"
                            class="pm-btn pm-btn-success pm-btn-xs"
                        >
                            <x-heroicon-m-check-badge class="w-4 h-4 mr-1.5" />
                            Konfirmasi Semua yang Siap
                        </button>
                    </div>
                </div>

                {{-- Table Rekap Sesi Golio --}}
                @include('filament.resources.express-events.partials.booth-table', ['records' => $this->golioRecords, 'boothType' => 'Golio'])
            </div>
        @endif

        {{-- ======================================================== --}}
        {{-- MODALS --}}
        {{-- ======================================================== --}}

        {{-- 1. Modal Tentukan Tarif HPP --}}
        @if($isTariffModalOpen)
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-gray-900/50 backdrop-blur-sm">
                <div class="bg-white dark:bg-gray-800 rounded-2xl max-w-md w-full p-6 shadow-2xl border border-gray-200 dark:border-gray-700 space-y-4">
                    <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 pb-3">
                        <h3 class="text-base font-bold text-gray-900 dark:text-white">Tentukan Tarif HPP</h3>
                        <button wire:click="closeTariffModal" class="text-gray-400 hover:text-gray-500">
                            <x-heroicon-m-x-mark class="w-5 h-5" />
                        </button>
                    </div>

                    <div class="space-y-3 text-sm">
                        <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                            <div class="text-xs text-gray-500 dark:text-gray-400">Nomor Rekap Sesi:</div>
                            <div class="font-bold text-gray-900 dark:text-white">{{ $modalRecapNumber }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Jumlah Lembar: <span class="font-bold text-gray-900 dark:text-white">{{ number_format($modalSheets) }} lembar</span></div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Tarif HPP per Lembar (Rp)</label>
                            <input
                                wire:model.live="modalRate"
                                type="number"
                                min="0"
                                step="100"
                                placeholder="Contoh: 2500"
                                class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white py-2 px-3 focus:ring-primary-500 focus:border-primary-500 font-mono"
                            />
                        </div>

                        <div class="p-3 bg-primary-50 dark:bg-primary-950/40 rounded-lg border border-primary-200 dark:border-primary-800 text-xs text-primary-900 dark:text-primary-200 flex justify-between items-center">
                            <span>Estimasi Total HPP:</span>
                            <span class="font-bold text-sm font-mono">
                                {{ \App\Utils\RupiahHelper::format(($modalSheets ?? 0) * ($modalRate ?? 0)) }}
                            </span>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button
                            wire:click="closeTariffModal"
                            type="button"
                            class="pm-btn pm-btn-secondary pm-btn-sm"
                        >
                            Batal
                        </button>
                        <button
                            wire:click="saveTariff"
                            type="button"
                            class="pm-btn pm-btn-primary pm-btn-sm"
                        >
                            Simpan Tarif
                        </button>
                    </div>
                </div>
            </div>
        @endif

        {{-- 2. Modal Buka Kembali (Reopen) --}}
        @if($isReopenModalOpen)
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-gray-900/50 backdrop-blur-sm">
                <div class="bg-white dark:bg-gray-800 rounded-2xl max-w-md w-full p-6 shadow-2xl border border-gray-200 dark:border-gray-700 space-y-4">
                    <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 pb-3">
                        <div class="flex items-center gap-2 text-amber-600">
                            <x-heroicon-m-exclamation-triangle class="w-5 h-5" />
                            <h3 class="text-base font-bold text-gray-900 dark:text-white">Buka Kembali HPP</h3>
                        </div>
                        <button wire:click="closeReopenModal" class="text-gray-400 hover:text-gray-500">
                            <x-heroicon-m-x-mark class="w-5 h-5" />
                        </button>
                    </div>

                    <div class="space-y-3 text-sm">
                        <p class="text-xs text-gray-600 dark:text-gray-300">
                            Membuka kembali data HPP akan mengembalikan status ke Draft (Calculated). Jika transaksi HPP sudah diposting ke Akuntansi, jurnal tersebut akan dibatalkan/dibalik secara otomatis.
                        </p>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Alasan Buka Kembali (Wajib)</label>
                            <textarea
                                wire:model="reopenReason"
                                rows="3"
                                placeholder="Contoh: Koreksi tarif HPP karena revisi harga bahan baku..."
                                class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white py-2 px-3 focus:ring-amber-500 focus:border-amber-500"
                            ></textarea>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button
                            wire:click="closeReopenModal"
                            type="button"
                            class="pm-btn pm-btn-secondary pm-btn-sm"
                        >
                            Batal
                        </button>
                        <button
                            wire:click="submitReopen"
                            type="button"
                            class="pm-btn pm-btn-warning pm-btn-sm"
                        >
                            Konfirmasi Buka Kembali
                        </button>
                    </div>
                </div>
            </div>
        @endif

        {{-- 3. Modal Atur Tarif Standar --}}
        @if($isStandardRateModalOpen)
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-gray-900/50 backdrop-blur-sm">
                <div class="bg-white dark:bg-gray-800 rounded-2xl max-w-md w-full p-6 shadow-2xl border border-gray-200 dark:border-gray-700 space-y-4">
                    <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 pb-3">
                        <h3 class="text-base font-bold text-gray-900 dark:text-white">Atur Tarif Standar {{ $modalBoothType }}</h3>
                        <button wire:click="closeStandardRateModal" class="text-gray-400 hover:text-gray-500">
                            <x-heroicon-m-x-mark class="w-5 h-5" />
                        </button>
                    </div>

                    <div class="space-y-3 text-sm">
                        <p class="text-xs text-gray-600 dark:text-gray-300">
                            Tarif standar digunakan untuk menghitung estimasi HPP per lembar cetak {{ $modalBoothType }} secara otomatis.
                        </p>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Tarif Standar per Lembar (Rp)</label>
                            <input
                                wire:model="modalStandardRate"
                                type="number"
                                min="0"
                                step="100"
                                class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white py-2 px-3 focus:ring-primary-500 focus:border-primary-500 font-mono"
                            />
                        </div>

                        <div class="flex items-center gap-2 pt-1">
                            <input
                                wire:model="applyToUnconfirmed"
                                id="applyUnconfirmed"
                                type="checkbox"
                                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                            />
                            <label for="applyUnconfirmed" class="text-xs text-gray-700 dark:text-gray-300">
                                Terapkan juga ke seluruh rekap {{ $modalBoothType }} yang belum dikonfirmasi
                            </label>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button
                            wire:click="closeStandardRateModal"
                            type="button"
                            class="pm-btn pm-btn-secondary pm-btn-sm"
                        >
                            Batal
                        </button>
                        <button
                            wire:click="saveStandardRate"
                            type="button"
                            class="pm-btn pm-btn-primary pm-btn-sm"
                        >
                            Simpan Pengaturan
                        </button>
                    </div>
                </div>
            </div>
        @endif

    </div>
</x-filament-panels::page>
