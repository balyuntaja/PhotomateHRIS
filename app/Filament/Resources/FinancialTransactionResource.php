<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FinancialTransactionResource\Pages;
use App\Models\Cabang;
use App\Models\FinancialCategory;
use App\Models\FinancialPaymentMethod;
use App\Models\FinancialTransaction;
use App\Services\KeuanganService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class FinancialTransactionResource extends Resource
{
    protected static ?string $model = FinancialTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationLabel = 'Transaksi';

    protected static ?string $modelLabel = 'Transaksi';

    protected static ?string $pluralModelLabel = 'Transaksi';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'keuangan-transaksi';

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_keuangan_transaction') ?? false);
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_keuangan_transaction') ?? false);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['category', 'cabang', 'paymentMethod', 'creator']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Transaksi')
                ->schema([
                    Forms\Components\ToggleButtons::make('transaction_type')
                        ->label('Jenis Transaksi')
                        ->options(FinancialTransaction::TYPE_LABELS)
                        ->colors([
                            FinancialTransaction::TYPE_INCOME => 'success',
                            FinancialTransaction::TYPE_EXPENSE => 'danger',
                        ])
                        ->icons([
                            FinancialTransaction::TYPE_INCOME => 'heroicon-o-arrow-down-circle',
                            FinancialTransaction::TYPE_EXPENSE => 'heroicon-o-arrow-up-circle',
                        ])
                        ->inline()
                        ->default(FinancialTransaction::TYPE_INCOME)
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('category_id', null))
                        ->columnSpanFull(),

                    self::amountInput(),

                    Forms\Components\Select::make('category_id')
                        ->label('Kategori')
                        ->options(fn (Get $get): array => FinancialCategory::query()
                            ->ofType($get('transaction_type') ?: FinancialTransaction::TYPE_INCOME)
                            ->active()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required()
                        ->validationMessages(['required' => 'Kategori wajib dipilih.']),

                    Forms\Components\DatePicker::make('transaction_date')
                        ->label('Tanggal Transaksi')
                        ->default(now())
                        ->required()
                        ->validationMessages(['required' => 'Tanggal transaksi wajib diisi.']),

                    Forms\Components\Select::make('cabang_id')
                        ->label('Cabang')
                        ->options(fn (): array => Cabang::query()
                            ->orderBy('nama_cabang')
                            ->pluck('nama_cabang', 'cabang_id')
                            ->all())
                        ->searchable()
                        ->placeholder('— Tanpa cabang —'),

                    Forms\Components\Select::make('payment_method_id')
                        ->label('Metode Pembayaran')
                        ->options(fn (): array => FinancialPaymentMethod::query()
                            ->active()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn (): ?int => FinancialPaymentMethod::query()->where('name', 'Cash')->value('id'))
                        ->searchable()
                        ->required()
                        ->validationMessages(['required' => 'Metode pembayaran wajib dipilih.']),

                    Forms\Components\Textarea::make('description')
                        ->label('Deskripsi')
                        ->maxLength(500)
                        ->rows(2)
                        ->placeholder('Opsional, contoh: Pembelian tinta printer Janus')
                        ->columnSpanFull(),

                    Forms\Components\FileUpload::make('evidence_path')
                        ->label('Bukti Transaksi')
                        ->disk('public')
                        ->directory('keuangan/evidence')
                        ->visibility('public')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->maxSize(5120)
                        ->helperText('Opsional. Format JPG, PNG, WEBP, atau PDF (maksimal 5MB).')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function amountInput(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('amount')
            ->label('Nominal')
            ->prefix('Rp')
            ->required()
            ->mask(RawJs::make('$money($input, ",", ".", 0)'))
            ->stripCharacters(['.', ','])
            ->numeric()
            ->minValue(1)
            ->validationMessages([
                'required' => 'Nominal wajib diisi.',
                'numeric' => 'Nominal harus berupa angka.',
                'min' => 'Nominal harus lebih dari Rp0.',
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Detail Transaksi')
                ->schema([
                    Infolists\Components\TextEntry::make('transaction_type')
                        ->label('Jenis Transaksi')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => FinancialTransaction::TYPE_LABELS[$state] ?? $state)
                        ->color(fn (string $state): string => $state === FinancialTransaction::TYPE_INCOME ? 'success' : 'danger'),

                    Infolists\Components\TextEntry::make('signed_formatted_amount')
                        ->label('Nominal')
                        ->weight('bold')
                        ->color(fn (FinancialTransaction $record): string => $record->is_income ? 'success' : 'danger'),

                    Infolists\Components\TextEntry::make('category.name')
                        ->label('Kategori')
                        ->placeholder('-'),

                    Infolists\Components\TextEntry::make('transaction_date_label')
                        ->label('Tanggal'),

                    Infolists\Components\TextEntry::make('cabang.nama_cabang')
                        ->label('Cabang')
                        ->placeholder('Tanpa Cabang'),

                    Infolists\Components\TextEntry::make('paymentMethod.name')
                        ->label('Metode Pembayaran')
                        ->placeholder('-'),

                    Infolists\Components\TextEntry::make('description')
                        ->label('Deskripsi')
                        ->placeholder('-')
                        ->columnSpanFull(),

                    Infolists\Components\ImageEntry::make('evidence_path')
                        ->label('Bukti Transaksi')
                        ->disk('public')
                        ->height(220)
                        ->visible(fn (FinancialTransaction $record): bool => $record->evidence_is_image)
                        ->columnSpanFull(),

                    Infolists\Components\TextEntry::make('evidence_url')
                        ->label('Bukti Transaksi (PDF)')
                        ->formatStateUsing(fn (): string => 'Lihat Bukti')
                        ->url(fn (FinancialTransaction $record): ?string => $record->evidence_url)
                        ->openUrlInNewTab()
                        ->visible(fn (FinancialTransaction $record): bool => filled($record->evidence_path) && ! $record->evidence_is_image)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Infolists\Components\Section::make('Informasi Pencatatan')
                ->schema([
                    Infolists\Components\TextEntry::make('creator.nama_lengkap')
                        ->label('Dibuat Oleh')
                        ->placeholder('-'),

                    Infolists\Components\TextEntry::make('updater.nama_lengkap')
                        ->label('Terakhir Diubah Oleh')
                        ->placeholder('-'),

                    Infolists\Components\TextEntry::make('created_at_label')
                        ->label('Dibuat Pada'),

                    Infolists\Components\TextEntry::make('updated_at_label')
                        ->label('Terakhir Diubah'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('transaction_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('transaction_type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => FinancialTransaction::TYPE_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => $state === FinancialTransaction::TYPE_INCOME ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Kategori')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Deskripsi')
                    ->searchable()
                    ->limit(40)
                    ->placeholder('-')
                    ->tooltip(fn (FinancialTransaction $record): ?string => $record->description),

                Tables\Columns\TextColumn::make('cabang.nama_cabang')
                    ->label('Cabang')
                    ->searchable()
                    ->placeholder('Tanpa Cabang')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('paymentMethod.name')
                    ->label('Metode')
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->formatStateUsing(fn (FinancialTransaction $record): string => $record->signed_formatted_amount)
                    ->color(fn (FinancialTransaction $record): string => $record->is_income ? 'success' : 'danger')
                    ->weight('semibold')
                    ->alignEnd()
                    ->sortable()
                    ->searchable(),

                Tables\Columns\IconColumn::make('evidence_path')
                    ->label('Bukti')
                    ->icon(fn (?string $state): string => filled($state) ? 'heroicon-o-paper-clip' : 'heroicon-o-x-mark')
                    ->color(fn (?string $state): string => filled($state) ? 'info' : 'gray')
                    ->tooltip(fn (?string $state): ?string => filled($state) ? 'Ada bukti transaksi' : 'Tanpa bukti')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('creator.nama_lengkap')
                    ->label('Dibuat Oleh')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->filters([
                self::periodFilter(),

                Tables\Filters\SelectFilter::make('transaction_type')
                    ->label('Jenis')
                    ->options(FinancialTransaction::TYPE_LABELS),

                Tables\Filters\SelectFilter::make('cabang_id')
                    ->label('Cabang')
                    ->relationship('cabang', 'nama_cabang')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Kategori')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('payment_method_id')
                    ->label('Metode Pembayaran')
                    ->relationship('paymentMethod', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\TrashedFilter::make()
                    ->label('Data Terhapus'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Detail')
                    ->modalHeading('Detail Transaksi')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),

                Tables\Actions\Action::make('riwayat')
                    ->label('Riwayat')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading('Riwayat Perubahan Transaksi')
                    ->modalWidth('2xl')
                    ->modalContent(fn (FinancialTransaction $record) => view(
                        'filament.resources.financial-transaction-resource.audit-timeline',
                        ['logs' => $record->auditLogs()->with('user')->orderByDesc('created_at')->get()]
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),

                Tables\Actions\EditAction::make()
                    ->label('Edit')
                    ->modalHeading('Edit Transaksi')
                    ->modalSubmitActionLabel('Simpan Perubahan')
                    ->successNotificationTitle('Transaksi berhasil diperbarui.'),

                Tables\Actions\DeleteAction::make()
                    ->label('Hapus')
                    ->modalHeading('Hapus transaksi ini?')
                    ->modalDescription('Transaksi yang dihapus tidak akan muncul dalam laporan.')
                    ->modalSubmitActionLabel('Hapus')
                    ->successNotificationTitle('Transaksi berhasil dihapus.'),

                Tables\Actions\RestoreAction::make()
                    ->label('Pulihkan')
                    ->successNotificationTitle('Transaksi berhasil dipulihkan.'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Hapus Terpilih'),
                ]),
            ])
            ->emptyStateHeading('Belum ada transaksi')
            ->emptyStateDescription('Mulai catat pemasukan dan pengeluaran Photomate di sini.')
            ->emptyStateIcon('heroicon-o-wallet');
    }

    public static function periodFilter(): Tables\Filters\Filter
    {
        return Tables\Filters\Filter::make('periode')
            ->label('Periode')
            ->form([
                Forms\Components\Select::make('preset')
                    ->label('Periode')
                    ->options(fn (): array => app(KeuanganService::class)->periodOptions())
                    ->default(KeuanganService::DEFAULT_PRESET)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->columnSpanFull(),

                Forms\Components\DatePicker::make('dari')
                    ->label('Dari Tanggal')
                    ->visible(fn (Get $get): bool => $get('preset') === KeuanganService::PRESET_CUSTOM)
                    ->required(fn (Get $get): bool => $get('preset') === KeuanganService::PRESET_CUSTOM),

                Forms\Components\DatePicker::make('sampai')
                    ->label('Sampai Tanggal')
                    ->visible(fn (Get $get): bool => $get('preset') === KeuanganService::PRESET_CUSTOM)
                    ->required(fn (Get $get): bool => $get('preset') === KeuanganService::PRESET_CUSTOM),
            ])
            ->query(function (Builder $query, array $data): Builder {
                $period = app(KeuanganService::class)->resolvePeriod(
                    $data['preset'] ?? null,
                    $data['dari'] ?? null,
                    $data['sampai'] ?? null,
                );

                return $query->periode($period['start'], $period['end']);
            })
            ->indicateUsing(function (array $data): array {
                if (blank($data['preset'] ?? null)) {
                    return [];
                }

                $period = app(KeuanganService::class)->resolvePeriod(
                    $data['preset'],
                    $data['dari'] ?? null,
                    $data['sampai'] ?? null,
                );

                return ['periode' => 'Periode: ' . $period['label']];
            });
    }

    public static function getPages(): array
    {
        // Hanya halaman index: tanpa halaman create/edit/view, Filament otomatis
        // membuka form/infolist di modal (pencatatan cepat tanpa pindah halaman).
        return [
            'index' => Pages\ListFinancialTransactions::route('/'),
        ];
    }
}
