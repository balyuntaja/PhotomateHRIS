<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SessionReportResource\Pages;
use App\Models\Invoice;
use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Services\PricingService;
use App\Services\SessionWorkflowService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class SessionReportResource extends Resource
{
    protected static ?string $model = SessionReport::class;

    protected static ?string $navigationGroup = 'Session Management';

    protected static ?string $navigationIcon = 'heroicon-o-camera';

    protected static ?string $navigationLabel = 'Rekap Sesi';

    protected static ?string $modelLabel = 'Rekap Sesi';

    protected static ?string $pluralModelLabel = 'Rekap Sesi';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return Auth::check();
    }

    public static function form(Form $form): Form
    {
        $pricingService = app(PricingService::class);

        $isApproved = function ($record = null, $livewire = null): bool {
            if ($record instanceof SessionReport) {
                return $record->isApproved();
            }
            if ($record instanceof \App\Models\SessionTransaction) {
                return (bool) $record->sessionReport?->isApproved();
            }
            if ($livewire && method_exists($livewire, 'getRecord')) {
                $parent = $livewire->getRecord();
                return $parent instanceof SessionReport && $parent->isApproved();
            }
            return false;
        };

        return $form
            ->schema([
                // Alert Banner if REVISION
                Forms\Components\Placeholder::make('revision_alert')
                    ->hidden(fn ($record) => !($record instanceof SessionReport) || $record->status !== SessionReport::STATUS_REVISION)
                    ->columnSpanFull()
                    ->content(fn ($record) => new \Illuminate\Support\HtmlString('
                        <div class="p-4 bg-rose-50 dark:bg-rose-950/50 border-l-4 border-rose-500 rounded-r-lg">
                            <div class="flex items-center gap-2 text-rose-800 dark:text-rose-200 font-bold text-sm">
                                <svg class="w-5 h-5 text-rose-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                                <span>REVISI DIPERLUKAN</span>
                            </div>
                            <p class="mt-1 text-sm text-rose-700 dark:text-rose-300 font-medium">
                                Catatan Supervisor: ' . e($record?->revision_note ?? 'Silakan periksa kembali data transaksi Anda.') . '
                            </p>
                        </div>
                    ')),

                // Alert Banner if APPROVED (Locked)
                Forms\Components\Placeholder::make('approved_alert')
                    ->hidden(fn ($record) => !($record instanceof SessionReport) || $record->status !== SessionReport::STATUS_APPROVED)
                    ->columnSpanFull()
                    ->content(fn ($record) => new \Illuminate\Support\HtmlString('
                        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/50 border-l-4 border-emerald-500 rounded-r-lg">
                            <div class="flex items-center gap-2 text-emerald-800 dark:text-emerald-200 font-bold text-sm">
                                <svg class="w-5 h-5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                <span>REKAP SUDAH DISETUJUI (APPROVED)</span>
                            </div>
                            <p class="mt-1 text-sm text-emerald-700 dark:text-emerald-300">
                                Disetujui oleh ' . e($record?->approver?->nama_lengkap ?? 'Supervisor') . ' pada ' . ($record?->approved_at ? $record->approved_at->translatedFormat('d F Y, H:i') : '-') . '. Data ini bersifat tetap (immutable).
                            </p>
                        </div>
                    ')),

                Forms\Components\Section::make('Informasi Rekap')
                    ->description('Tentukan tanggal event dan pilih crew yang bertugas')
                    ->schema([
                        Forms\Components\DatePicker::make('report_date')
                            ->label('Tanggal Sesi')
                            ->default(now())
                            ->required()
                            ->disabled(fn ($record, $livewire = null) => $isApproved($record, $livewire))
                            ->native(false),

                        Forms\Components\Select::make('cabang_id')
                            ->label('Cabang Photomate')
                            ->options(\App\Models\Cabang::orderBy('nama_cabang')->pluck('nama_cabang', 'cabang_id'))
                            ->searchable()
                            ->preload()
                            ->placeholder('Pilih Cabang (misal: Goliohub / Janus)')
                            ->nullable()
                            ->helperText('Pilih cabang tempat sesi berlangsung')
                            ->disabled(fn ($record, $livewire = null) => $isApproved($record, $livewire)),

                        Forms\Components\Select::make('crews')
                            ->label('Crew yang Bertugas')
                            ->relationship('crews', 'nama_lengkap')
                            ->options(Karyawan::orderBy('nama_lengkap')->pluck('nama_lengkap', 'karyawan_id'))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required()
                            ->default(fn () => Auth::user() ? [Auth::user()->karyawan_id] : [])
                            ->helperText('Dapat memilih lebih dari 1 crew')
                            ->columnSpanFull()
                            ->disabled(fn ($record, $livewire = null) => $isApproved($record, $livewire)),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Detail Transaksi')
                    ->description('Masukkan transaksi sesi foto yang terjadi. Nominal dihitung otomatis oleh sistem sesuai pricing rule (1 sesi = Rp 30.000, tambahan sesi = Rp 15.000/sesi).')
                    ->schema([
                        Forms\Components\Repeater::make('transactions')
                            ->relationship('transactions')
                            ->schema([
                                Forms\Components\Select::make('payment_method')
                                    ->label('Metode Pembayaran')
                                    ->options([
                                        'CASH' => 'Tunai',
                                        'QRIS' => 'QRIS',
                                    ])
                                    ->default('CASH')
                                    ->required()
                                    ->disabled(fn ($record, $livewire = null) => $isApproved($record, $livewire))
                                    ->columnSpan(['sm' => 1, 'md' => 1]),

                                Forms\Components\TextInput::make('session_count')
                                    ->label('Jumlah Sesi')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->live(debounce: 200)
                                    ->afterStateUpdated(function (Get $get, Forms\Set $set, $state) use ($pricingService) {
                                        $count = (int) $state;
                                        if ($count >= 1) {
                                            $calculated = $pricingService->calculateSessionPrice($count, $get('../../report_date'));
                                            $set('calculated_amount_display', 'Rp ' . number_format($calculated, 0, ',', '.'));
                                            $set('amount', $calculated);
                                        } else {
                                            $set('calculated_amount_display', 'Rp 0');
                                            $set('amount', 0);
                                        }
                                    })
                                    ->disabled(fn ($record, $livewire = null) => $isApproved($record, $livewire))
                                    ->columnSpan(['sm' => 1, 'md' => 1]),

                                Forms\Components\Placeholder::make('calculated_amount_display')
                                    ->label('Nominal')
                                    ->content(function (Get $get) use ($pricingService) {
                                        $count = (int) ($get('session_count') ?? 1);
                                        if ($count < 1) {
                                            return 'Rp 0';
                                        }
                                        $calculated = $pricingService->calculateSessionPrice($count, $get('../../report_date'));
                                        return 'Rp ' . number_format($calculated, 0, ',', '.');
                                    })
                                    ->columnSpan(['sm' => 1, 'md' => 1]),

                                Forms\Components\TextInput::make('notes')
                                    ->label('Catatan (Opsional)')
                                    ->disabled(fn ($record, $livewire = null) => $isApproved($record, $livewire))
                                    ->columnSpan(['sm' => 1, 'md' => 1]),
                            ])
                            ->columns(4)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('+ Tambah Transaksi')
                            ->disabled(fn ($record, $livewire = null) => $isApproved($record, $livewire))
                            ->reorderable(false),
                    ]),

                Forms\Components\Section::make('Ringkasan Kalkulasi Otomatis')
                    ->description('Dihitung otomatis realtime (1 sesi = Rp 30.000, sesi berikutnya +Rp 15.000)')
                    ->icon('heroicon-m-calculator')
                    ->schema([
                        Forms\Components\Placeholder::make('live_summary')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(fn (Get $get) => view('filament.resources.session-reports.live-summary', ['get' => $get])),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                /** @var Karyawan $user */
                $user = Auth::user();
                $workflow = app(SessionWorkflowService::class);

                // If regular crew, only show reports where user is submitter OR in crews
                if ($user && !$workflow->isSupervisorOrAdmin($user)) {
                    $query->where(function ($q) use ($user) {
                        $q->where('submitted_by', $user->karyawan_id)
                            ->orWhereHas('crews', function ($c) use ($user) {
                                $c->where('karyawan.karyawan_id', $user->karyawan_id);
                            });
                    });
                }
            })
            ->columns([
                Tables\Columns\TextColumn::make('report_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('report_number')
                    ->label('No Rekap')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->color('primary')
                    ->copyable(),

                Tables\Columns\TextColumn::make('crews.nama_lengkap')
                    ->label('Crew')
                    ->badge()
                    ->separator(', ')
                    ->limitList(2)
                    ->searchable(),

                Tables\Columns\TextColumn::make('cabang.nama_cabang')
                    ->label('Cabang')
                    ->badge()
                    ->color('gray')
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_sessions')
                    ->label('Total Sesi')
                    ->formatStateUsing(fn ($state) => $state . ' sesi')
                    ->alignCenter()
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_cash_amount')
                    ->label('Total Tunai')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format($state, 0, ',', '.'))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_qris_amount')
                    ->label('Total QRIS')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format($state, 0, ',', '.'))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('grand_total_amount')
                    ->label('Grand Total')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format($state, 0, ',', '.'))
                    ->weight('bold')
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (SessionReport $record): string => $record->status_color)
                    ->formatStateUsing(fn (SessionReport $record): string => $record->status_label)
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('report_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('cabang_id')
                    ->label('Cabang')
                    ->options(\App\Models\Cabang::pluck('nama_cabang', 'cabang_id')),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        SessionReport::STATUS_DRAFT => 'Draft',
                        SessionReport::STATUS_WAITING_APPROVAL => 'Waiting Approval',
                        SessionReport::STATUS_REVISION => 'Revision',
                        SessionReport::STATUS_APPROVED => 'Approved',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('review_detail')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('secondary')
                    ->modalHeading(fn (SessionReport $record) => 'Detail Rekap Sesi #' . $record->report_number)
                    ->modalWidth('4xl')
                    ->modalContent(fn (SessionReport $record) => view('filament.resources.session-reports.review-modal', ['record' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),

                Tables\Actions\EditAction::make()
                    ->visible(fn (SessionReport $record) => in_array($record->status, [SessionReport::STATUS_DRAFT, SessionReport::STATUS_REVISION])),

                Tables\Actions\Action::make('submit_rekap')
                    ->label(fn (SessionReport $record) => $record->status === SessionReport::STATUS_REVISION ? 'Submit Ulang' : 'Submit')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(fn (SessionReport $record) => in_array($record->status, [SessionReport::STATUS_DRAFT, SessionReport::STATUS_REVISION]))
                    ->requiresConfirmation()
                    ->modalHeading(fn (SessionReport $record) => $record->status === SessionReport::STATUS_REVISION ? 'Submit Ulang Rekap Sesi?' : 'Submit Rekap Sesi?')
                    ->modalDescription('Rekap sesi akan dikirimkan ke Supervisor untuk diperiksa. Pastikan semua data transaksi sudah benar.')
                    ->modalSubmitActionLabel('Ya, Submit')
                    ->action(function (SessionReport $record): void {
                        try {
                            app(SessionWorkflowService::class)->submit($record, Auth::user());
                            Notification::make()
                                ->title('Rekap sesi berhasil dikirim dan menunggu approval Supervisor.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Gagal mengirim rekap')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (SessionReport $record) => $record->status === SessionReport::STATUS_DRAFT),
            ])
            ->bulkActions([
                // Avoid bulk deletion of operational data
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSessionReports::route('/'),
            'create' => Pages\CreateSessionReport::route('/create'),
            'edit' => Pages\EditSessionReport::route('/{record}/edit'),
            'view' => Pages\ViewSessionReport::route('/{record}'),
        ];
    }
}
