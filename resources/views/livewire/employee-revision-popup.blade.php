<div>
    @if ($showModal && ! empty($revisionItems))
        <div
            x-data="{}"
            x-on:keydown.escape.window="$wire.dismissPopup()"
            role="dialog"
            aria-modal="true"
            aria-labelledby="pm-revision-popup-title"
            class="pm-revision-modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4"
        >
            <div
                class="fixed inset-0 bg-slate-950/60 backdrop-blur-[2px]"
                wire:click="dismissPopup"
            ></div>

            <div class="pm-revision-modal-card relative z-10 w-full max-w-xl overflow-hidden rounded-2xl border border-orange-200 bg-white shadow-2xl dark:border-orange-800/60 dark:bg-gray-900">
                {{-- Header Ringkas --}}
                <div class="pm-revision-modal-header flex items-center justify-between gap-3 border-b border-orange-200/80 px-5 py-3.5 dark:border-orange-900/60">
                    <div class="flex items-center gap-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-rose-600 text-white shadow-sm">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" />
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <h2
                                id="pm-revision-popup-title"
                                class="text-sm font-bold tracking-tight text-slate-900 sm:text-base dark:text-white"
                            >
                                Rekap Sesi Memerlukan Revisi
                            </h2>

                            @if (count($revisionItems) > 1)
                                <span class="inline-flex items-center rounded-full bg-rose-600 px-2 py-0.5 text-[11px] font-bold text-white">
                                    {{ count($revisionItems) }} Rekap
                                </span>
                            @endif
                        </div>
                    </div>

                    <button
                        type="button"
                        wire:click="dismissPopup"
                        aria-label="Tutup popup revisi"
                        class="rounded-lg p-1.5 text-slate-400 transition hover:bg-orange-100/70 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-white"
                    >
                        <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
                    </button>
                </div>

                {{-- Daftar Ringkas Seluruh Rekap Perlu Revisi --}}
                <div class="max-h-[60vh] divide-y divide-orange-100 overflow-y-auto dark:divide-orange-950/60">
                    @foreach ($revisionItems as $item)
                        <div
                            wire:key="revision-popup-item-{{ $item['session_report_id'] }}"
                            class="flex flex-col gap-2.5 bg-orange-50/35 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between dark:bg-orange-950/15"
                        >
                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-1.5 text-sm font-bold text-slate-900 dark:text-white">
                                    <span class="inline-flex items-center gap-1 rounded bg-rose-600 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">
                                        Perlu Revisi
                                    </span>
                                    <span>Cabang {{ $item['cabang_name'] }}</span>
                                    <span class="text-slate-400" aria-hidden="true">·</span>
                                    <span class="text-[#354B6D] dark:text-[#F8E2B9]">Tanggal {{ $item['report_date_formatted'] }}</span>
                                </div>

                                <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-slate-600 dark:text-slate-300">
                                    <span class="font-mono font-semibold">#{{ $item['report_number'] }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span>Crew: {{ $item['crew_names'] }}</span>
                                </div>

                                @if (filled($item['revision_note']) && $item['revision_note'] !== '-')
                                    <p class="rounded-md border-l-2 border-rose-500 bg-rose-50/90 px-2.5 py-1 text-xs font-medium text-rose-900 dark:bg-rose-950/45 dark:text-rose-100">
                                        "{{ $item['revision_note'] }}"
                                    </p>
                                @endif
                            </div>

                            <div class="shrink-0">
                                <button
                                    type="button"
                                    wire:click="openRevision({{ $item['session_report_id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="openRevision({{ $item['session_report_id'] }})"
                                    class="pm-btn pm-btn-xs pm-revision-cta-btn w-full sm:w-auto"
                                >
                                    <x-filament::icon icon="heroicon-m-pencil-square" class="h-3.5 w-3.5" />
                                    <span>Lihat Rekap &amp; Perbaiki</span>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Footer --}}
                <div class="flex items-center justify-end border-t border-slate-200 bg-slate-50/90 px-5 py-3 dark:border-gray-800 dark:bg-gray-950/60">
                    <button
                        type="button"
                        wire:click="dismissPopup"
                        class="pm-btn pm-btn-xs pm-btn-secondary"
                    >
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
