@props([
    'notifications',
    'unreadNotificationsCount',
])

@php
    use App\Services\RevisionNotificationService;
    use Filament\Support\Enums\Alignment;

    $hasNotifications = $notifications->count();
    $isPaginated = $notifications instanceof \Illuminate\Contracts\Pagination\Paginator && $notifications->hasPages();
@endphp

<x-filament::modal
    :alignment="$hasNotifications ? null : Alignment::Center"
    close-button
    :description="$hasNotifications ? null : __('filament-notifications::database.modal.empty.description')"
    :heading="$hasNotifications ? null : __('filament-notifications::database.modal.empty.heading')"
    :icon="$hasNotifications ? null : 'heroicon-o-bell-slash'"
    :icon-alias="$hasNotifications ? null : 'notifications::database.modal.empty-state'"
    :icon-color="$hasNotifications ? null : 'gray'"
    id="database-notifications"
    slide-over
    :sticky-header="$hasNotifications"
    width="md"
>
    @if ($hasNotifications)
        <x-slot name="header">
            <div>
                <x-filament-notifications::database.modal.heading
                    :unread-notifications-count="$unreadNotificationsCount"
                />

                <x-filament-notifications::database.modal.actions
                    :notifications="$notifications"
                    :unread-notifications-count="$unreadNotificationsCount"
                />
            </div>
        </x-slot>

        <div
            @class([
                '-mx-6 -mt-6 divide-y divide-gray-200 dark:divide-white/10',
                '-mb-6' => ! $isPaginated,
                'border-b border-gray-200 dark:border-white/10' => $isPaginated,
            ])
        >
            @foreach ($notifications as $notification)
                @if (data_get($notification->data, 'type') === RevisionNotificationService::TYPE_SESSION_REVISION)
                    @php
                        $reportId = data_get($notification->data, 'session_report_id');
                        $reportNumber = data_get($notification->data, 'report_number', '-');
                        $reportDate = data_get($notification->data, 'report_date_formatted', '-');
                        $cabangName = data_get($notification->data, 'cabang_name', '-');
                        $revisedAt = data_get($notification->data, 'revised_at_formatted') ?: ($notification->created_at?->diffForHumans() ?? '-');
                        $revisionNote = data_get($notification->data, 'revision_note', '-');
                        $supervisorName = data_get($notification->data, 'supervisor_name');
                        $detailUrl = data_get($notification->data, 'detail_url')
                            ?: ($reportId ? route('filament.admin.resources.session-reports.view', ['record' => $reportId]) : '#');
                    @endphp

                    <div
                        wire:key="db-revision-notif-{{ $notification->id }}"
                        @class([
                            'pm-bell-revision-item relative transition hover:bg-orange-50/75 dark:hover:bg-orange-950/25',
                            'bg-orange-50/55 dark:bg-orange-950/20 before:absolute before:inset-y-0 before:start-0 before:w-1 before:bg-rose-500' => $notification->unread(),
                        ])
                    >
                        <a
                            href="{{ $detailUrl }}"
                            x-on:click="$wire.markNotificationAsRead('{{ $notification->id }}')"
                            class="block p-4 pe-10"
                        >
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-100 text-rose-600 dark:bg-rose-950/80 dark:text-rose-400">
                                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" />
                                </span>

                                <div class="min-w-0 flex-1 space-y-1.5">
                                    <div class="flex flex-wrap items-center justify-between gap-1.5">
                                        <span class="text-sm font-bold text-slate-900 dark:text-white">
                                            Rekap Sesi Memerlukan Revisi
                                        </span>

                                        @if ($notification->unread())
                                            <span class="inline-flex items-center rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-bold text-white">
                                                Belum Dibaca
                                            </span>
                                        @endif
                                    </div>

                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-600 dark:text-slate-300">
                                        <span class="font-mono font-semibold text-[#354B6D] dark:text-slate-200">#{{ $reportNumber }}</span>
                                        <span aria-hidden="true">·</span>
                                        <span>{{ $reportDate }}</span>
                                        <span aria-hidden="true">·</span>
                                        <span class="font-medium">{{ $cabangName }}</span>
                                    </div>

                                    <div class="rounded-lg border-l-2 border-rose-400 bg-rose-50/85 px-2.5 py-1.5 text-xs text-rose-900 dark:border-rose-600 dark:bg-rose-950/45 dark:text-rose-200">
                                        "{{ $revisionNote }}"
                                    </div>

                                    <div class="flex flex-wrap items-center justify-between gap-2 pt-0.5 text-[11px]">
                                        <span class="text-slate-500 dark:text-slate-400">
                                            {{ $revisedAt }}@if ($supervisorName) · {{ $supervisorName }}@endif
                                        </span>

                                        <span class="font-semibold text-rose-600 dark:text-rose-400">
                                            Detail Rekap &rarr;
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </a>

                        <button
                            type="button"
                            wire:click.stop.prevent="removeNotification('{{ $notification->id }}')"
                            aria-label="Hapus notifikasi"
                            class="absolute end-3 top-3 rounded-md p-1 text-slate-400 transition hover:bg-slate-200/70 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-white"
                        >
                            <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" />
                        </button>
                    </div>
                @else
                    <div
                        @class([
                            'relative before:absolute before:start-0 before:h-full before:w-0.5 before:bg-primary-600 dark:before:bg-primary-500' => $notification->unread(),
                        ])
                    >
                        {{ $this->getNotification($notification)->inline() }}
                    </div>
                @endif
            @endforeach
        </div>

        @if ($isPaginated)
            <x-slot name="footer">
                <x-filament::pagination :paginator="$notifications" />
            </x-slot>
        @endif
    @endif
</x-filament::modal>
