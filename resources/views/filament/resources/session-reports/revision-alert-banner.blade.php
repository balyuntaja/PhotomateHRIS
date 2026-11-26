@php
    use App\Filament\Resources\SessionReportResource;
    use App\Models\Karyawan;
    use App\Models\SessionReport;
    use App\Services\SessionWorkflowService;
    use Illuminate\Support\Facades\Auth;

    $user = Auth::user();
    $workflow = app(SessionWorkflowService::class);
    $isCrew = $user instanceof Karyawan && ! $workflow->isSupervisorOrAdmin($user);

    $revisionReports = $isCrew
        ? SessionReport::query()
            ->with(['cabang'])
            ->where('status', SessionReport::STATUS_REVISION)
            ->where(function ($query) use ($user): void {
                $query->where('submitted_by', $user->karyawan_id)
                    ->orWhereHas('crews', fn ($c) => $c->where('karyawan.karyawan_id', $user->karyawan_id));
            })
            ->orderByDesc('updated_at')
            ->get()
        : collect();
@endphp

@if ($revisionReports->isNotEmpty())
    <div class="pm-revision-table-banner mb-4 rounded-xl border border-orange-200 bg-gradient-to-r from-orange-50 via-rose-50/80 to-amber-50/70 p-4 shadow-sm dark:border-orange-800/60 dark:from-orange-950/40 dark:via-rose-950/30 dark:to-amber-950/25">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-orange-500 to-rose-600 text-white shadow-sm">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" />
                </span>
                <div>
                    <p class="text-sm font-bold text-slate-900 dark:text-white">
                        {{ $revisionReports->count() }} Rekap Sesi Memerlukan Revisi
                    </p>
                    <p class="mt-0.5 text-xs text-slate-600 dark:text-slate-300">
                        Baris yang ditandai warna lembut di bawah ini perlu diperbaiki. Klik tombol <strong>Edit</strong> untuk memperbarui data, lalu klik <strong>Submit Ulang</strong>.
                    </p>
                </div>
            </div>

            @if ($revisionReports->count() === 1)
                @php $singleReport = $revisionReports->first(); @endphp
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <a
                        href="{{ SessionReportResource::getUrl('edit', ['record' => $singleReport]) }}"
                        class="pm-btn pm-btn-xs pm-revision-cta-btn"
                    >
                        <x-filament::icon icon="heroicon-m-pencil-square" class="h-3.5 w-3.5" />
                        Perbaiki #{{ $singleReport->report_number }}
                    </a>
                </div>
            @endif
        </div>
    </div>
@endif
