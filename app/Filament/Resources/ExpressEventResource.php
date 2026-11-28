<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExpressEventResource\Pages;
use App\Filament\Resources\ExpressEventResource\Widgets\ExpressEventStatsOverview;
use App\Models\ExpressEvent;
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
                                    $set('allocations_data', []);
                                    return;
                                }

                                $service = app(ExpressEventAllocationService::class);
                                $matchingRecaps = $service->getEligibleRecapsForEventName($state, $record?->id);

                                if ($matchingRecaps->isEmpty()) {
                                    Notification::make()
                                        ->title('Rekap Sesi Belum Tersedia')
                                        ->body("Belum ada rekap sesi berstatus Approved yang tersedia untuk event '{$state}'.")
                                        ->warning()
                                        ->send();
                                    $set('allocations_data', []);
                                    return;
                                }

                                $newAllocations = [];
                                foreach ($matchingRecaps as $recap) {
                                    $newAllocations[] = [
                                        'session_report_id' => $recap['id'],
                                        'allocated_sessions' => $recap['remaining_sessions'],
                                        'allocated_sheets' => $recap['remaining_sheets'],
                                        'allocated_revenue' => $recap['remaining_revenue'],
                                        'notes' => null,
                                    ];
                                }
                                $set('allocations_data', $newAllocations);

                                // Auto-fill start_date and end_date from recap operational dates
                                $dates = $matchingRecaps->pluck('raw_date')->filter()->sort()->values();
                                if ($dates->isNotEmpty()) {
                                    $set('start_date', $dates->first());
                                    $set('end_date', $dates->last());
                                }
                            })
                            ->columnSpan(2),

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

                                        $isExpressSewa = str_contains(strtolower($report->cabang?->nama_cabang ?? ''), 'express sewa');
                                        if ($isExpressSewa) {
                                            if (! $report->invoice) {
                                                Notification::make()
                                                    ->title('Invoice Belum Tersedia')
                                                    ->body("Rekap sesi {$report->report_number} belum memiliki invoice terhubung. Alokasi omzet tidak dapat diisi otomatis.")
                                                    ->warning()
                                                    ->persistent()
                                                    ->send();

                                                $set('allocated_revenue', 0);
                                            } else {
                                                // Agregasi alokasi untuk invoice yang sama dalam form repeater saat ini (mencegah double-count)
                                                $allocations = $get('../../allocations_data') ?? [];
                                                $otherAllocatedRevenue = 0;
                                                foreach ($allocations as $alloc) {
                                                    $otherReportId = (int) ($alloc['session_report_id'] ?? 0);
                                                    if ($otherReportId && $otherReportId !== (int) $state) {
                                                        $otherReport = SessionReport::find($otherReportId);
                                                        if ($otherReport && $otherReport->invoice_id === $report->invoice_id) {
                                                            $otherAllocatedRevenue += (int) ($alloc['allocated_revenue'] ?? 0);
                                                        }
                                                    }
                                                }

                                                $availableRevenue = max(0, $remaining['revenue'] - $otherAllocatedRevenue);
                                                $set('allocated_revenue', $availableRevenue);
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
                                        if (! $reportId) return null;
                                        $report = SessionReport::with(['cabang', 'invoice'])->find($reportId);
                                        if (! $report) return null;
                                        $isExpressSewa = str_contains(strtolower($report->cabang?->nama_cabang ?? ''), 'express sewa');
                                        if ($isExpressSewa) {
                                            if ($report->invoice) {
                                                return 'Omzet otomatis dari Invoice ' . $report->invoice->invoice_number . ' (' . RupiahHelper::format($report->invoice->total) . ').';
                                            }
                                            return '⚠️ Invoice belum tersedia untuk rekap ini. Hubungkan rekap dengan invoice pada menu Rekap Sesi.';
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
                            ->content(function (Get $get) {
                                $allocations = $get('allocations_data') ?? [];
                                $rate = (int) $get('hpp_rate_per_sheet');

                                $totalSessions = 0;
                                $totalSheets = 0;
                                $totalRevenue = 0;

                                foreach ($allocations as $alloc) {
                                    $totalSessions += (int) ($alloc['allocated_sessions'] ?? 0);
                                    $totalSheets += (int) ($alloc['allocated_sheets'] ?? 0);
                                    $totalRevenue += (int) ($alloc['allocated_revenue'] ?? 0);
                                }

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
                    ->description(fn (ExpressEvent $record): string => $record->start_date?->format('d/m/Y') . ' - ' . $record->end_date?->format('d/m/Y')),

                Tables\Columns\TextColumn::make('operational_type_label')
                    ->label('Jenis Operasional')
                    ->badge()
                    ->state(fn (ExpressEvent $record): string => $record->operational_type_label)
                    ->color(fn (ExpressEvent $record): string => $record->isSharingProfit() ? 'success' : 'info'),

                Tables\Columns\TextColumn::make('total_sessions')
                    ->label('Sesi')
                    ->state(fn (ExpressEvent $record): int => $record->total_allocated_sessions)
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_sheets')
                    ->label('Lembar')
                    ->state(fn (ExpressEvent $record): int => $record->total_allocated_sheets)
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_revenue')
                    ->label('Omzet / Kontrak')
                    ->state(fn (ExpressEvent $record): string => RupiahHelper::format($record->effective_revenue))
                    ->description(fn (ExpressEvent $record): ?string => $record->requiresInvoice() ? $record->invoice_connection_status_label : null)
                    ->alignEnd()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('hpp_rate_per_sheet')
                    ->label('Tarif HPP')
                    ->formatStateUsing(fn ($state) => $state !== null ? RupiahHelper::format($state) : '-')
                    ->alignEnd(),

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

                Tables\Columns\TextColumn::make('completeness_status')
                    ->label('Kelengkapan Data')
                    ->badge()
                    ->state(fn (ExpressEvent $record): string => $record->completeness_status_label)
                    ->color(fn (ExpressEvent $record): string => $record->completeness_status_color),

                Tables\Columns\TextColumn::make('hpp_status')
                    ->label('Status HPP')
                    ->badge()
                    ->formatStateUsing(fn (ExpressEvent $record): string => $record->hpp_status_label)
                    ->color(fn (ExpressEvent $record): string => $record->hpp_status_color),
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
