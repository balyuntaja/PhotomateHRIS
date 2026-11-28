<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExpressEventResource\Pages;
use App\Filament\Resources\ExpressEventResource\Widgets\ExpressEventStatsOverview;
use App\Models\ExpressEvent;
use App\Models\ExpressEventInvoice;
use App\Models\SessionReport;
use App\Services\ExpressEventAllocationService;
use App\Utils\MonthHelper;
use App\Utils\RupiahHelper;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ExpressEventResource extends Resource
{
    protected static ?string $model = ExpressEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Manajemen HPP';

    protected static ?int $navigationSort = 8;

    protected static ?string $modelLabel = 'Event Express';

    protected static ?string $pluralModelLabel = 'Manajemen HPP';

    protected static ?string $slug = 'keuangan/hpp-event-express';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informasi Event')
                    ->schema([
                        Forms\Components\Select::make('event_name')
                            ->label('Nama Event')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->placeholder('Pilih Nama Event dari Rekap Sesi Express...')
                            ->options(function (?ExpressEvent $record) {
                                return app(ExpressEventAllocationService::class)->getAvailableEventNames($record?->id);
                            })
                            ->helperText(function (Get $get, ?ExpressEvent $record) {
                                $eventName = $get('event_name');
                                if (blank($eventName)) {
                                    return 'Pilih event dari rekap sesi Express yang sudah disetujui (Approved).';
                                }
                                $service = app(ExpressEventAllocationService::class);
                                $count = $service->getEligibleRecapsForEventName($eventName, $record?->id)->count();
                                if ($count === 0) {
                                    return '⚠️ Rekap sesi belum tersedia atau sudah dialokasikan ke Event HPP lain. Tidak dapat dialokasikan.';
                                }
                                return "Tersedia {$count} rekap sesi Approved untuk event ini.";
                            })
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set, ?ExpressEvent $record, Get $get) {
                                if (blank($state)) {
                                    $set('operational_identity_selection', null);
                                    $set('allocations_data', []);
                                    return;
                                }

                                $service = app(ExpressEventAllocationService::class);
                                $groups = $service->getOperationalIdentityGroupsForEventName($state, $record?->id);

                                if ($groups === []) {
                                    Notification::make()
                                        ->title('Rekap Sesi Belum Tersedia')
                                        ->body("Belum ada rekap sesi berstatus Approved yang tersedia untuk event '{$state}'.")
                                        ->warning()
                                        ->send();
                                    $set('operational_identity_selection', null);
                                    $set('allocations_data', []);
                                    return;
                                }

                                // Pilih grup identitas operasional pertama saja agar event berbeda bernama sama TIDAK pernah tergabung otomatis!
                                $firstGroupKey = (string) array_key_first($groups);
                                $set('operational_identity_selection', $firstGroupKey);
                                $matchingRecaps = $groups[$firstGroupKey]['recaps'];

                                $newAllocations = [];
                                $candidateInvoiceIds = [];
                                $selectedReportModels = SessionReport::with('cabang')
                                    ->whereIn('id', $matchingRecaps->pluck('id')->all())
                                    ->get()
                                    ->keyBy('id');

                                foreach ($matchingRecaps as $recap) {
                                    $repModel = $selectedReportModels->get($recap['id']);
                                    if ($repModel && $repModel->invoice_id) {
                                        $isUsedElsewhere = ExpressEventInvoice::query()
                                            ->where('invoice_id', $repModel->invoice_id)
                                            ->whereHas('expressEvent')
                                            ->when($record !== null, fn ($q) => $q->where('express_event_id', '!=', $record->id))
                                            ->exists();

                                        if (! $isUsedElsewhere) {
                                            $candidateInvoiceIds[] = (int) $repModel->invoice_id;
                                        }
                                    }

                                    $newAllocations[] = [
                                        'session_report_id' => $recap['id'],
                                        'allocated_sessions' => $recap['remaining_sessions'],
                                        'allocated_sheets' => $recap['remaining_sheets'],
                                        'allocated_revenue' => $recap['remaining_revenue'],
                                        'notes' => null,
                                    ];
                                }

                                $existingInvoiceIds = collect($get('linked_invoice_ids') ?? [])
                                    ->map(fn ($id): int => (int) $id)
                                    ->filter(fn (int $id): bool => $id > 0)
                                    ->all();
                                $mergedInvoiceIds = array_values(array_unique(array_merge($existingInvoiceIds, $candidateInvoiceIds)));

                                $context = ExpressEvent::resolveFormContext($newAllocations, $mergedInvoiceIds, $record);
                                if ($context['is_sharing_profit']) {
                                    $set('linked_invoice_ids', []);
                                } else {
                                    $set('linked_invoice_ids', $mergedInvoiceIds);

                                    if ($context['requires_invoice']) {
                                        $totalContract = (int) $context['invoice_total_revenue'];
                                        $totalSheets = array_sum(array_map(fn ($r): int => (int) $r['allocated_sheets'], $newAllocations));
                                        $count = count($newAllocations);
                                        $distributed = 0;

                                        foreach ($newAllocations as $idx => $row) {
                                            if ($idx === $count - 1) {
                                                $share = max(0, $totalContract - $distributed);
                                            } elseif ($totalSheets > 0) {
                                                $share = (int) floor(($totalContract * (int) $row['allocated_sheets']) / $totalSheets);
                                                $distributed += $share;
                                            } else {
                                                $share = (int) floor($totalContract / max(1, $count));
                                                $distributed += $share;
                                            }
                                            $newAllocations[$idx]['allocated_revenue'] = $share;
                                        }
                                    }
                                }

                                $set('allocations_data', $newAllocations);

                                // Auto-fill start_date and end_date from recap operational dates
                                $set('start_date', $groups[$firstGroupKey]['start_date']);
                                $set('end_date', $groups[$firstGroupKey]['end_date']);
                            })
                            ->columnSpan(2),

                        Forms\Components\Select::make('operational_identity_selection')
                            ->label('Pilih Jadwal / Identitas Event Operasional')
                            ->dehydrated(false)
                            ->options(function (Get $get, ?ExpressEvent $record): array {
                                $eventName = $get('event_name');
                                if (blank($eventName)) {
                                    return [];
                                }
                                $groups = app(ExpressEventAllocationService::class)
                                    ->getOperationalIdentityGroupsForEventName($eventName, $record?->id);

                                $opts = [];
                                foreach ($groups as $key => $g) {
                                    $opts[$key] = $g['label'];
                                }

                                return $opts;
                            })
                            ->visible(function (Get $get, ?ExpressEvent $record): bool {
                                $eventName = $get('event_name');
                                if (blank($eventName)) {
                                    return false;
                                }

                                return count(
                                    app(ExpressEventAllocationService::class)
                                        ->getOperationalIdentityGroupsForEventName($eventName, $record?->id)
                                ) > 1;
                            })
                            ->helperText('Terdapat beberapa event operasional berbeda dengan nama yang sama. Pilih jadwal/identitas yang sesuai.')
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set, Get $get, ?ExpressEvent $record) {
                                $eventName = $get('event_name');
                                if (blank($eventName) || blank($state)) {
                                    return;
                                }

                                $groups = app(ExpressEventAllocationService::class)
                                    ->getOperationalIdentityGroupsForEventName($eventName, $record?->id);

                                if (! isset($groups[$state])) {
                                    return;
                                }

                                $matchingRecaps = $groups[$state]['recaps'];
                                $newAllocations = [];
                                $candidateInvoiceIds = [];
                                $selectedReportModels = SessionReport::with('cabang')
                                    ->whereIn('id', $matchingRecaps->pluck('id')->all())
                                    ->get()
                                    ->keyBy('id');

                                foreach ($matchingRecaps as $recap) {
                                    $repModel = $selectedReportModels->get($recap['id']);
                                    if ($repModel && $repModel->invoice_id) {
                                        $isUsedElsewhere = ExpressEventInvoice::query()
                                            ->where('invoice_id', $repModel->invoice_id)
                                            ->whereHas('expressEvent')
                                            ->when($record !== null, fn ($q) => $q->where('express_event_id', '!=', $record->id))
                                            ->exists();

                                        if (! $isUsedElsewhere) {
                                            $candidateInvoiceIds[] = (int) $repModel->invoice_id;
                                        }
                                    }

                                    $newAllocations[] = [
                                        'session_report_id' => $recap['id'],
                                        'allocated_sessions' => $recap['remaining_sessions'],
                                        'allocated_sheets' => $recap['remaining_sheets'],
                                        'allocated_revenue' => $recap['remaining_revenue'],
                                        'notes' => null,
                                    ];
                                }

                                $context = ExpressEvent::resolveFormContext($newAllocations, $candidateInvoiceIds, $record);
                                if ($context['is_sharing_profit']) {
                                    $set('linked_invoice_ids', []);
                                } else {
                                    $set('linked_invoice_ids', array_values(array_unique($candidateInvoiceIds)));
                                    if ($context['requires_invoice']) {
                                        $totalContract = (int) $context['invoice_total_revenue'];
                                        $totalSheets = array_sum(array_map(fn ($r): int => (int) $r['allocated_sheets'], $newAllocations));
                                        $count = count($newAllocations);
                                        $distributed = 0;

                                        foreach ($newAllocations as $idx => $row) {
                                            if ($idx === $count - 1) {
                                                $share = max(0, $totalContract - $distributed);
                                            } elseif ($totalSheets > 0) {
                                                $share = (int) floor(($totalContract * (int) $row['allocated_sheets']) / $totalSheets);
                                                $distributed += $share;
                                            } else {
                                                $share = (int) floor($totalContract / max(1, $count));
                                                $distributed += $share;
                                            }
                                            $newAllocations[$idx]['allocated_revenue'] = $share;
                                        }
                                    }
                                }

                                $set('allocations_data', $newAllocations);
                                $set('start_date', $groups[$state]['start_date']);
                                $set('end_date', $groups[$state]['end_date']);
                            })
                            ->columnSpanFull(),

                        Forms\Components\DatePicker::make('start_date')
                            ->label('Tanggal Mulai')
                            ->required()
                            ->readOnly()
                            ->helperText('Otomatis mengikuti tanggal operasional rekap sesi terhubung.')
                            ->live(),

                        Forms\Components\DatePicker::make('end_date')
                            ->label('Tanggal Selesai')
                            ->required()
                            ->readOnly()
                            ->helperText('Otomatis mengikuti tanggal operasional rekap sesi terhubung.')
                            ->afterOrEqual('start_date'),

                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan Event')
                            ->maxLength(500)
                            ->placeholder('Catatan khusus event, klien, atau instruksi operasional...')
                            ->columnSpanFull(),
                    ])
                    ->columns(4),

                Forms\Components\Section::make('Alokasi Rekap Sesi Express')
                    ->description(function (Get $get) {
                        $eventName = $get('event_name');
                        if (blank($eventName)) {
                            return 'Hubungkan rekap sesi Express berstatus Approved. Anda dapat memilih Nama Event di atas atau memilih rekap sesi langsung di bawah.';
                        }
                        return "Rekap sesi Express berstatus Approved yang terhubung dengan event '{$eventName}'.";
                    })
                    ->schema([
                        Forms\Components\Repeater::make('allocations_data')
                            ->label('Daftar Rekap Terhubung & Alokasi')
                            ->schema([
                                Forms\Components\Select::make('session_report_id')
                                    ->label('Pilih Rekap Sesi Express')
                                    ->required()
                                    ->distinct()
                                    ->searchable()
                                    ->placeholder('Pilih Rekap Sesi Express...')
                                    ->options(function (Get $get, ?ExpressEvent $record) {
                                        $eventName = $get('../../event_name') ?: $record?->event_name;
                                        $service = app(ExpressEventAllocationService::class);
                                        $recaps = $service->getEligibleRecapsForEventName($eventName, $record?->id);

                                        $options = [];
                                        foreach ($recaps as $r) {
                                            $options[$r['id']] = $r['label'];
                                        }

                                        return $options;
                                    })
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set, ?ExpressEvent $record, Get $get) {
                                        if (! $state) {
                                            return;
                                        }
                                        $report = SessionReport::with(['cabang', 'transactions', 'invoice'])->find($state);
                                        if (! $report) {
                                            return;
                                        }
                                        $service = app(ExpressEventAllocationService::class);

                                        // Jika Nama Event di atas belum terisi, otomatis isi dari rekap yang dipilih
                                        if (blank($get('../../event_name'))) {
                                            $resolvedName = $service->resolveReportEventName($report);
                                            if ($resolvedName) {
                                                $set('../../event_name', $resolvedName);
                                            }
                                        }

                                        $remaining = $service->getRemainingTotals($report, $record?->id);

                                        // Default isi alokasi dengan kapasitas sisa yang ada
                                        $set('allocated_sessions', $remaining['sessions']);
                                        $set('allocated_sheets', $remaining['sheets']);

                                        // Sinkronisasi tanggal mulai dan tanggal selesai
                                        $reportDate = $report->event_date?->toDateString() ?? $report->report_date?->toDateString();
                                        if ($reportDate) {
                                            if (blank($get('../../start_date')) || $reportDate < $get('../../start_date')) {
                                                $set('../../start_date', $reportDate);
                                            }
                                            if (blank($get('../../end_date')) || $reportDate > $get('../../end_date')) {
                                                $set('../../end_date', $reportDate);
                                            }
                                        }

                                        $opType = ExpressEvent::classifyBranchOperationalType($report->cabang?->nama_cabang);
                                        $requiresInvoice = in_array($opType, [ExpressEvent::TYPE_SELF_RUN, ExpressEvent::TYPE_SEWA], true);

                                        if ($opType === ExpressEvent::TYPE_SHARING_PROFIT) {
                                            $set('../../linked_invoice_ids', []);
                                            $set('allocated_revenue', $remaining['revenue']);

                                            return;
                                        }

                                        $linkedIds = collect($get('../../linked_invoice_ids') ?? [])
                                            ->map(fn ($id): int => (int) $id)
                                            ->filter(fn (int $id): bool => $id > 0)
                                            ->unique()
                                            ->values()
                                            ->all();

                                        if ($requiresInvoice && $report->invoice_id && ! in_array((int) $report->invoice_id, $linkedIds, true)) {
                                            $usedElsewhere = ExpressEventInvoice::query()
                                                ->where('invoice_id', $report->invoice_id)
                                                ->whereHas('expressEvent')
                                                ->when($record !== null, fn ($q) => $q->where('express_event_id', '!=', $record->id))
                                                ->exists();

                                            if (! $usedElsewhere) {
                                                $linkedIds[] = (int) $report->invoice_id;
                                                $set('../../linked_invoice_ids', $linkedIds);
                                            }
                                        }

                                        if ($requiresInvoice) {
                                            if ($linkedIds === []) {
                                                $set('allocated_revenue', 0);
                                            } else {
                                                $totalInvoiceContract = (int) \App\Models\Invoice::query()
                                                    ->whereIn('id', $linkedIds)
                                                    ->get()
                                                    ->unique('id')
                                                    ->sum(fn (\App\Models\Invoice $inv): int => $inv->contract_amount);

                                                $allocations = $get('../../allocations_data') ?? [];
                                                $otherAllocatedRevenue = 0;
                                                foreach ($allocations as $alloc) {
                                                    $otherReportId = (int) ($alloc['session_report_id'] ?? 0);
                                                    if ($otherReportId && $otherReportId !== (int) $state) {
                                                        $otherAllocatedRevenue += RupiahHelper::toInt($alloc['allocated_revenue'] ?? 0);
                                                    }
                                                }

                                                $set('allocated_revenue', max(0, $totalInvoiceContract - $otherAllocatedRevenue));
                                            }
                                        } else {
                                            $set('allocated_revenue', $remaining['revenue']);
                                        }
                                    })
                                    ->columnSpan([
                                        'default' => 12,
                                        'md' => 4,
                                    ]),

                                Forms\Components\TextInput::make('allocated_sessions')
                                    ->label('Alokasi Sesi')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->default(0)
                                    ->live()
                                    ->columnSpan([
                                        'default' => 6,
                                        'md' => 2,
                                    ]),

                                Forms\Components\TextInput::make('allocated_sheets')
                                    ->label('Alokasi Lembar')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->default(0)
                                    ->live()
                                    ->columnSpan([
                                        'default' => 6,
                                        'md' => 2,
                                    ]),

                                Forms\Components\TextInput::make('allocated_revenue')
                                    ->label('Alokasi Omzet')
                                    ->rupiah()
                                    ->required()
                                    ->default(0)
                                    ->live()
                                    ->helperText(function (Get $get) {
                                        $reportId = $get('session_report_id');
                                        if (! $reportId) {
                                            return null;
                                        }
                                        $report = SessionReport::with('cabang')->find($reportId);
                                        if (! $report) {
                                            return null;
                                        }
                                        $opType = ExpressEvent::classifyBranchOperationalType($report->cabang?->nama_cabang);
                                        if (in_array($opType, [ExpressEvent::TYPE_SELF_RUN, ExpressEvent::TYPE_SEWA], true)) {
                                            return 'Omzet Self Run & Sewa mengikuti Total Nilai Invoice pada section Alokasi Invoice Event.';
                                        }

                                        return null;
                                    })
                                    ->columnSpan([
                                        'default' => 12,
                                        'md' => 2,
                                    ]),

                                Forms\Components\TextInput::make('notes')
                                    ->label('Keterangan')
                                    ->placeholder('Opsional...')
                                    ->columnSpan([
                                        'default' => 12,
                                        'md' => 2,
                                    ]),
                            ])
                            ->default([])
                            ->columns(12)
                            ->itemLabel(function (array $state): ?string {
                                $reportId = $state['session_report_id'] ?? null;
                                if (! $reportId) {
                                    return 'Rekap Baru';
                                }
                                $report = SessionReport::with('cabang')->find($reportId);
                                if (! $report) {
                                    return null;
                                }
                                $service = app(ExpressEventAllocationService::class);
                                $branchType = $service->resolveReportBranchType($report);
                                $dateStr = ($report->event_date ?? $report->report_date)?->format('d/m/Y') ?? '-';

                                return "{$report->report_number} · {$branchType} ({$dateStr})";
                            })
                            ->live()
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Alokasi Invoice Event')
                    ->description('Hubungkan satu atau lebih invoice Express untuk menentukan nilai kontrak event (khusus Express Self Run & Express Sewa).')
                    ->schema([
                        Forms\Components\Hidden::make('linked_invoice_ids')
                            ->default([]),

                        Forms\Components\Hidden::make('invoices_modified')
                            ->default(false),

                        Forms\Components\View::make('filament.resources.express-events.partials.form-invoice-allocation')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Tarif HPP & Realtime Preview')
                    ->schema([
                        Forms\Components\TextInput::make('hpp_rate_per_sheet')
                            ->label('Tarif HPP per Lembar')
                            ->rupiah()
                            ->placeholder('0')
                            ->helperText('Satu tarif HPP per lembar untuk seluruh lembar event ini. Masukkan 0 jika event tanpa biaya HPP.')
                            ->live()
                            ->columnSpan(2),

                        Forms\Components\Placeholder::make('realtime_preview')
                            ->label('Ringkasan Perhitungan Real-Time')
                            ->content(function (Get $get, ?ExpressEvent $record) {
                                $allocations = $get('allocations_data') ?? [];
                                $linkedInvoiceIds = $get('linked_invoice_ids') ?? [];
                                $rate = max(0, RupiahHelper::toInt($get('hpp_rate_per_sheet')));

                                $context = ExpressEvent::resolveFormContext(
                                    is_array($allocations) ? $allocations : [],
                                    is_array($linkedInvoiceIds) ? $linkedInvoiceIds : [],
                                    $record,
                                );

                                $totalSessions = $context['total_sessions'];
                                $totalSheets = $context['total_sheets'];
                                $totalRevenue = $context['effective_revenue'];
                                $revenueSourceLabel = e($context['revenue_source_label']);

                                $totalHpp = $rate * $totalSheets;
                                $grossProfit = $totalRevenue - $totalHpp;

                                return new \Illuminate\Support\HtmlString("
                                    <div class='grid grid-cols-2 sm:grid-cols-3 gap-3 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg text-sm border border-gray-200 dark:border-gray-700'>
                                        <div>
                                            <div class='text-xs text-gray-500'>Total Sesi</div>
                                            <div class='font-semibold text-gray-800 dark:text-gray-100'>{$totalSessions} sesi</div>
                                        </div>
                                        <div>
                                            <div class='text-xs text-gray-500'>Total Lembar</div>
                                            <div class='font-semibold text-gray-800 dark:text-gray-100'>{$totalSheets} lembar</div>
                                        </div>
                                        <div>
                                            <div class='text-xs text-gray-500'>Total Omzet</div>
                                            <div class='font-semibold text-blue-600 dark:text-blue-400'>" . RupiahHelper::format($totalRevenue) . "</div>
                                            <div class='text-[11px] text-gray-500'>{$revenueSourceLabel}</div>
                                        </div>
                                        <div>
                                            <div class='text-xs text-gray-500'>Tarif HPP</div>
                                            <div class='font-semibold text-gray-800 dark:text-gray-100'>" . RupiahHelper::format($rate) . " /lbr</div>
                                        </div>
                                        <div>
                                            <div class='text-xs text-gray-500'>Total HPP</div>
                                            <div class='font-semibold text-amber-600 dark:text-amber-400'>" . RupiahHelper::format($totalHpp) . "</div>
                                        </div>
                                        <div>
                                            <div class='text-xs text-gray-500'>Laba Kotor</div>
                                            <div class='font-semibold " . ($grossProfit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400') . "'>" . RupiahHelper::format($grossProfit) . "</div>
                                        </div>
                                    </div>
                                ");
                            })
                            ->columnSpan(2),
                    ])
                    ->columns(4),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('event_name')
                    ->label('Nama Event')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (ExpressEvent $record): ?string => $record->needs_admin_verification
                        ? '⚠️ Perlu Verifikasi Admin'
                        : null),

                Tables\Columns\TextColumn::make('period_range')
                    ->label('Rentang Tanggal')
                    ->state(fn (ExpressEvent $record): string => $record->period_range_label)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('start_date', $direction)),

                Tables\Columns\TextColumn::make('operational_type_label')
                    ->label('Jenis Express')
                    ->badge()
                    ->state(fn (ExpressEvent $record): string => $record->operational_type_label)
                    ->color(fn (ExpressEvent $record): string => $record->isSharingProfit() ? 'success' : 'info'),

                Tables\Columns\TextColumn::make('total_sessions')
                    ->label('Total Sesi')
                    ->state(fn (ExpressEvent $record): int => $record->total_allocated_sessions)
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_sheets')
                    ->label('Total Lembar')
                    ->state(fn (ExpressEvent $record): int => $record->total_allocated_sheets)
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_revenue')
                    ->label('Total Omzet')
                    ->state(fn (ExpressEvent $record): string => RupiahHelper::format($record->effective_revenue))
                    ->alignEnd()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('hpp_rate_per_sheet')
                    ->label('Tarif HPP')
                    ->formatStateUsing(fn ($state) => $state !== null ? RupiahHelper::format($state) : '-')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('calculated_hpp')
                    ->label('Total HPP')
                    ->state(fn (ExpressEvent $record): string => $record->hpp_rate_per_sheet !== null ? RupiahHelper::format($record->calculated_hpp) : '-')
                    ->alignEnd()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('gross_profit')
                    ->label('Laba Kotor')
                    ->state(fn (ExpressEvent $record): string => $record->isReadyForHppCalculation() ? RupiahHelper::format($record->gross_profit) : '-')
                    ->alignEnd()
                    ->color(fn (ExpressEvent $record): string => ! $record->isReadyForHppCalculation() ? 'gray' : ($record->gross_profit >= 0 ? 'success' : 'danger'))
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('invoice_status')
                    ->label('Status Invoice')
                    ->badge()
                    ->state(fn (ExpressEvent $record): string => $record->invoice_connection_status_label)
                    ->color(fn (ExpressEvent $record): string => $record->invoice_connection_status_color),

                Tables\Columns\TextColumn::make('completeness_status')
                    ->label('Status Pengelolaan HPP')
                    ->badge()
                    ->state(fn (ExpressEvent $record): string => $record->completeness_status_label)
                    ->description(fn (ExpressEvent $record): string => $record->hpp_status_label)
                    ->color(fn (ExpressEvent $record): string => $record->completeness_status_color),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tahun')
                    ->label('Tahun')
                    ->options(function () {
                        $currentYear = (int) now()->year;
                        $years = [];
                        for ($y = $currentYear + 1; $y >= $currentYear - 3; $y--) {
                            $years[$y] = (string) $y;
                        }
                        return $years;
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        if (! empty($data['value'])) {
                            return $query->whereYear('start_date', $data['value']);
                        }
                        return $query;
                    }),

                Tables\Filters\SelectFilter::make('bulan')
                    ->label('Bulan')
                    ->options(MonthHelper::getMonthOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        if (! empty($data['value'])) {
                            return $query->whereMonth('start_date', $data['value']);
                        }
                        return $query;
                    }),

                Tables\Filters\SelectFilter::make('reconciliation_status')
                    ->label('Status Rekonsiliasi')
                    ->options(ExpressEvent::RECON_LABELS),

                Tables\Filters\SelectFilter::make('hpp_status')
                    ->label('Status HPP')
                    ->options(ExpressEvent::HPP_LABELS),

                Tables\Filters\TernaryFilter::make('needs_admin_verification')
                    ->label('Verifikasi Identitas')
                    ->placeholder('Semua Event')
                    ->trueLabel('Perlu Verifikasi Admin')
                    ->falseLabel('Sudah Jelas / Terverifikasi'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false),
                ]),
            ])
            ->defaultSort('start_date', 'desc')
            ->emptyStateHeading('Belum Ada Event Express')
            ->emptyStateDescription('Buat data event Express baru dan hubungkan dengan rekap sesi operasional yang telah disetujui.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getWidgets(): array
    {
        return [
            ExpressEventStatsOverview::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpressEvents::route('/'),
            'create' => Pages\CreateExpressEvent::route('/create'),
            'view' => Pages\ViewExpressEvent::route('/{record}'),
            'edit' => Pages\EditExpressEvent::route('/{record}/edit'),
        ];
    }
}
