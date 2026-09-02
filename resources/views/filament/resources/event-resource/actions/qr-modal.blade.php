@php
    $url = url('/queue/' . $record->event_code);
    $qrCodeImage = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=" . urlencode($url);
    $eventDateFormatted = $record->date ? \Carbon\Carbon::parse($record->date)->translatedFormat('d F Y') : '';
    $venue = $record->location ?: 'Photomate Booth';
@endphp

<div class="p-2 space-y-5 text-center">
    <!-- Card Top Header: Event Info -->
    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-gray-800 dark:to-gray-800/80 border border-blue-100 dark:border-gray-700 rounded-2xl p-4 text-center space-y-1.5 shadow-xs">
        <div class="inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 text-xs font-bold">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
            </svg>
            <span>QR Code Pendaftaran Antrean</span>
        </div>
        <h3 class="text-base font-extrabold text-gray-900 dark:text-white">
            {{ $record->name }}
        </h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 font-medium">
            📍 {{ $venue }} @if($eventDateFormatted) &bull; 🗓️ {{ $eventDateFormatted }} @endif
        </p>
    </div>

    <!-- Center Stage: Clean, High-Contrast QR Code Card -->
    <div class="flex flex-col items-center justify-center">
        <div class="bg-white p-4 rounded-2xl shadow-md border border-gray-200 dark:border-gray-700 flex flex-col items-center max-w-[260px] w-full">
            <img src="{{ $qrCodeImage }}" alt="QR Code {{ $record->name }}" class="w-48 h-48 object-contain rounded-lg" />
            <div class="mt-3 text-center w-full">
                <p class="text-xs font-bold text-gray-800">Silakan Scan untuk Antrean</p>
                <p class="text-[10px] text-blue-600 font-medium break-all mt-0.5">
                    {{ preg_replace('#^https?://#', '', $url) }}
                </p>
            </div>
        </div>
    </div>

    <!-- URL Box & Copy -->
    <div class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-2.5 flex items-center gap-2 max-w-md mx-auto">
        <span class="text-gray-400 text-xs pl-1">🔗</span>
        <input type="text" readonly value="{{ $url }}" class="text-xs font-semibold text-gray-800 dark:text-gray-200 bg-transparent flex-1 focus:outline-none truncate" />
        <button type="button" 
                onclick="navigator.clipboard.writeText('{{ $url }}'); const self = this; self.innerText = 'Tersalin! ✓'; setTimeout(() => self.innerText = 'Salin Link', 2500);"
                class="px-3 py-1.5 text-xs font-bold rounded-lg bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 transition cursor-pointer shadow-xs">
            Salin Link
        </button>
    </div>

    <!-- Download Buttons Section -->
    <div class="space-y-3 pt-1 max-w-md mx-auto">
        <button type="button"
                id="btn-dl-poster-{{ $record->id }}"
                onclick="window.downloadHighResPoster({
                    url: '{{ $url }}',
                    name: {{ json_encode($record->name) }},
                    venue: {{ json_encode($venue) }},
                    date: {{ json_encode($eventDateFormatted) }},
                    code: {{ json_encode($record->event_code) }},
                    btnId: 'btn-dl-poster-{{ $record->id }}'
                })"
                class="w-full py-3.5 px-5 rounded-xl bg-primary-600 hover:bg-primary-500 disabled:opacity-50 text-white font-bold text-sm shadow-md hover:shadow-lg transition cursor-pointer flex items-center justify-center gap-2">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
            </svg>
            <span>Unduh Poster QR (1200 &times; 1800 px)</span>
        </button>

        <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400 px-1">
            <!-- <span>Format: 1200 &times; 1800 px (Rasio 2:3)</span> -->
            <button type="button" 
                    onclick="window.downloadRawQrCode('{{ $url }}', '{{ $record->event_code }}')"
                    class="font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400 hover:underline cursor-pointer">
                Unduh Gambar QR Saja &rarr;
            </button>
        </div>
    </div>
</div>
