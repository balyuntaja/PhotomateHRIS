<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryItemResource\Pages;
use App\Filament\Resources\InventoryItemResource\RelationManagers;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Services\InventoryService;
use App\Utils\InventoryAccess;
use App\Utils\InventoryBranches;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class InventoryItemResource extends Resource
{
    protected static ?string $model = InventoryItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Barang';

    protected static ?string $modelLabel = 'Barang';

    protected static ?string $pluralModelLabel = 'Barang';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'inventory-barang';

    public static function canViewAny(): bool
    {
        return InventoryAccess::allowed(Auth::user());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        foreach (InventoryBranches::readGroups() as $key => $group) {
            $query->withSum(
                ['stocks as stock_sum_'.$key => fn (Builder $stockQuery): Builder => $stockQuery
                    ->whereIn('cabang_id', $group['members'])],
                'jumlah',
            );

            $query->withSum(
                ['stocks as stock_min_'.$key => fn (Builder $stockQuery): Builder => $stockQuery
                    ->where('cabang_id', $group['cabang_id'])],
                'min_stok',
            );
        }

        $memberIds = InventoryBranches::allMemberCabangIds(true);

        if ($memberIds !== []) {
            $query->withSum(
                ['stocks as total_stock_sum' => fn (Builder $stockQuery): Builder => $stockQuery
                    ->whereIn('cabang_id', $memberIds)],
                'jumlah',
            );
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Informasi Barang')
                ->schema([
                    Forms\Components\TextInput::make('kode_barang')
                        ->label('Kode Barang / SKU')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->autocomplete(false)
                        ->placeholder('Contoh: KRT-2R')
                        ->validationMessages([
                            'required' => 'Kode barang wajib diisi.',
                            'unique' => 'Kode barang sudah terdaftar. Buka barang tersebut lalu gunakan aksi Daftarkan ke Cabang untuk menambah stok di cabang lain.',
                            'max' => 'Kode barang maksimal 50 karakter.',
                        ]),

                    Forms\Components\TextInput::make('nama_barang')
                        ->label('Nama Barang')
                        ->required()
                        ->maxLength(150)
                        ->autocomplete(false)
                        ->placeholder('Contoh: Kertas Foto 2R')
                        ->validationMessages([
                            'required' => 'Nama barang wajib diisi.',
                            'max' => 'Nama barang maksimal 150 karakter.',
                        ]),

                    Forms\Components\TextInput::make('kategori')
                        ->label('Kategori')
                        ->maxLength(100)
                        ->datalist(fn (): array => InventoryItem::query()
                            ->whereNotNull('kategori')
                            ->where('kategori', '!=', '')
                            ->distinct()
                            ->orderBy('kategori')
                            ->pluck('kategori')
                            ->all())
                        ->placeholder('Contoh: Kertas Foto')
                        ->helperText('Pilih kategori yang sudah ada atau ketik kategori baru.'),

                    Forms\Components\Select::make('satuan')
                        ->label('Satuan')
                        ->options(InventoryItem::SATUAN_OPTIONS)
                        ->default('pcs')
                        ->required()
                        ->selectablePlaceholder(false)
                        ->validationMessages(['required' => 'Satuan wajib dipilih.']),

                    Forms\Components\TextInput::make('min_stok_default')
                        ->label('Batas Minimum Stok')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->required()
                        ->helperText('Diterapkan pada cabang tujuan saat barang ditambahkan, dan menjadi nilai default saat barang didaftarkan ke cabang lain.')
                        ->validationMessages([
                            'required' => 'Batas minimum stok wajib diisi.',
                            'min' => 'Batas minimum stok tidak boleh negatif.',
                        ]),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Barang Aktif')
                        ->default(true)
                        ->helperText('Barang nonaktif tidak dapat dipakai untuk transaksi baru, tetapi riwayat stok tetap tersimpan.'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Stok Cabang Tujuan')
                ->description('Barang baru hanya didaftarkan ke cabang yang dipilih. Cabang lain tidak otomatis memperoleh data stok untuk barang ini.')
                ->schema([
                    Forms\Components\Select::make('cabang_id')
                        ->label('Cabang Tujuan')
                        ->options(fn (): array => InventoryBranches::options())
                        ->required()
                        ->selectablePlaceholder(false)
                        ->helperText('Pilih satu cabang Inventory tempat stok awal dicatat.')
                        ->validationMessages([
                            'required' => 'Cabang tujuan wajib dipilih.',
                        ]),

                    Forms\Components\TextInput::make('stok_awal')
                        ->label('Stok Awal')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->required()
                        ->helperText('Hanya diterapkan pada cabang yang dipilih. Stok awal dicatat sebagai transaksi barang masuk.')
                        ->validationMessages([
                            'required' => 'Stok awal wajib diisi.',
                            'integer' => 'Stok awal harus berupa bilangan bulat.',
                            'min' => 'Stok awal tidak boleh negatif.',
                        ]),
                ])
                ->columns(2)
                ->columnSpanFull()
                ->visibleOn('create'),

            Forms\Components\Section::make('Pengelolaan Stok')
                ->schema([
                    Forms\Components\Placeholder::make('stock_notice')
                        ->hiddenLabel()
                        ->content('Stok awal hanya diterapkan pada cabang tujuan saat menambah barang. Perubahan stok berikutnya hanya melalui menu Transaksi Stok agar tercatat pada riwayat.'),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('kode_barang')
                    ->label('Kode / SKU')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('nama_barang')
                    ->label('Nama Barang')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (InventoryItem $record): string => filled($record->kategori) ? $record->kategori : 'Tanpa kategori'),

                Tables\Columns\TextColumn::make('kategori')
                    ->label('Kategori')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('satuan')
                    ->label('Satuan')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('total_stock')
                    ->label('Total Stok')
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.'))
                    ->alignCenter()
                    ->weight('semibold')
                    ->tooltip('Total stok cabang Inventory (Express, Golio, Newspaper Janus)'),

                Tables\Columns\TextColumn::make('branch_stock_count')
                    ->label('Cabang')
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.').' cabang')
                    ->alignCenter()
                    ->badge()
                    ->color('primary')
                    ->tooltip('Jumlah cabang Inventory yang memiliki stok tersedia'),

                Tables\Columns\TextColumn::make('stock_status')
                    ->label('Status Stok')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => InventoryStock::STATUS_LABELS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => InventoryStock::STATUS_COLORS[$state] ?? 'gray')
                    ->tooltip('Status agregat berdasarkan stok cabang Inventory'),

                Tables\Columns\TextColumn::make('is_active')
                    ->label('Status Barang')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Terakhir Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nama_barang')
            ->searchPlaceholder('Cari nama atau kode barang')
            ->filters([
                Tables\Filters\SelectFilter::make('kategori')
                    ->label('Kategori')
                    ->options(fn (): array => InventoryItem::query()
                        ->whereNotNull('kategori')
                        ->where('kategori', '!=', '')
                        ->distinct()
                        ->orderBy('kategori')
                        ->pluck('kategori', 'kategori')
                        ->all())
                    ->searchable(),

                Tables\Filters\SelectFilter::make('cabang_id')
                    ->label('Cabang')
                    ->options(fn (): array => InventoryBranches::options())
                    ->placeholder('Semua Cabang')
                    ->query(function (Builder $query, array $data): Builder {
                        $branchIds = InventoryBranches::expandCabangIds($data['value'] ?? null);

                        return $branchIds === null
                            ? $query
                            : $query->whereHas(
                                'stocks',
                                fn (Builder $stockQuery): Builder => $stockQuery->whereIn('cabang_id', $branchIds),
                            );
                    }),

                Tables\Filters\SelectFilter::make('status_stok')
                    ->label('Status Stok')
                    ->options(InventoryStock::STATUS_LABELS)
                    ->query(function (Builder $query, array $data): Builder {
                        $status = $data['value'] ?? null;
                        $stockQuery = app(InventoryService::class)->stockQuery();

                        $lowStockItems = fn (): Builder => (clone $stockQuery)
                            ->select('inventory_item_id')
                            ->where('jumlah', '>', 0)
                            ->whereColumn('jumlah', '<=', 'min_stok');

                        return match ($status) {
                            InventoryStock::STATUS_HABIS => $query->whereNotIn(
                                'id',
                                (clone $stockQuery)->select('inventory_item_id')->where('jumlah', '>', 0),
                            ),
                            InventoryStock::STATUS_MENIPIS => $query->whereIn('id', $lowStockItems()),
                            InventoryStock::STATUS_AMAN => $query
                                ->whereIn(
                                    'id',
                                    (clone $stockQuery)->select('inventory_item_id')->where('jumlah', '>', 0),
                                )
                                ->whereNotIn('id', $lowStockItems()),
                            default => $query,
                        };
                    }),

                Tables\Filters\SelectFilter::make('is_active')
                    ->label('Status Barang')
                    ->options([
                        1 => 'Aktif',
                        0 => 'Nonaktif',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Lihat'),

                Tables\Actions\EditAction::make()
                    ->label('Ubah'),

                Tables\Actions\Action::make('nonaktifkan')
                    ->label('Nonaktifkan')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Nonaktifkan Barang')
                    ->modalDescription('Barang tidak dapat dipakai pada transaksi baru. Riwayat stok dan transaksi tetap tersimpan.')
                    ->modalSubmitActionLabel('Ya, nonaktifkan')
                    ->visible(fn (InventoryItem $record): bool => $record->is_active && InventoryAccess::allowed(Auth::user()))
                    ->action(function (InventoryItem $record): void {
                        InventoryAccess::authorize();

                        $record->update(['is_active' => false]);

                        Notification::make()
                            ->title('Barang dinonaktifkan')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('aktifkan')
                    ->label('Aktifkan')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Aktifkan Barang')
                    ->modalDescription('Barang akan kembali tersedia untuk transaksi stok baru.')
                    ->modalSubmitActionLabel('Ya, aktifkan')
                    ->visible(fn (InventoryItem $record): bool => ! $record->is_active && InventoryAccess::allowed(Auth::user()))
                    ->action(function (InventoryItem $record): void {
                        InventoryAccess::authorize();

                        $record->update(['is_active' => true]);

                        Notification::make()
                            ->title('Barang diaktifkan')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('daftarkan_cabang')
                    ->label('Daftarkan ke Cabang')
                    ->icon('heroicon-m-plus-circle')
                    ->color('gray')
                    ->form(fn (InventoryItem $record): array => static::registerBranchForm($record))
                    ->visible(fn (InventoryItem $record): bool => InventoryAccess::allowed(Auth::user())
                        && app(InventoryService::class)->availableBranchOptions($record) !== [])
                    ->action(function (InventoryItem $record, array $data): void {
                        app(InventoryService::class)->registerBranchStock(
                            $record,
                            (string) $data['cabang_id'],
                            (int) $data['stok_awal'],
                            (int) $data['min_stok'],
                        );

                        Notification::make()
                            ->title('Barang didaftarkan ke cabang')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('nonaktifkan_terpilih')
                        ->label('Nonaktifkan Terpilih')
                        ->icon('heroicon-m-no-symbol')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Nonaktifkan Barang Terpilih')
                        ->modalDescription('Barang terpilih tidak dapat dipakai untuk transaksi baru. Riwayat tetap tersimpan.')
                        ->visible(fn (): bool => InventoryAccess::allowed(Auth::user()))
                        ->action(function (Collection $records): void {
                            InventoryAccess::authorize();

                            $records->each->update(['is_active' => false]);

                            Notification::make()
                                ->title('Barang terpilih dinonaktifkan')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('aktifkan_terpilih')
                        ->label('Aktifkan Terpilih')
                        ->icon('heroicon-m-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Aktifkan Barang Terpilih')
                        ->visible(fn (): bool => InventoryAccess::allowed(Auth::user()))
                        ->action(function (Collection $records): void {
                            InventoryAccess::authorize();

                            $records->each->update(['is_active' => true]);

                            Notification::make()
                                ->title('Barang terpilih diaktifkan')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->emptyStateHeading('Belum ada barang')
            ->emptyStateDescription('Tambahkan barang pertama untuk mulai memantau persediaan di seluruh cabang.')
            ->emptyStateIcon('heroicon-o-archive-box')
            ->defaultPaginationPageOption(10);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Informasi Barang')
                ->schema([
                    Infolists\Components\TextEntry::make('kode_barang')
                        ->label('Kode Barang / SKU')
                        ->badge()
                        ->color('gray')
                        ->copyable(),

                    Infolists\Components\TextEntry::make('nama_barang')
                        ->label('Nama Barang')
                        ->weight('semibold'),

                    Infolists\Components\TextEntry::make('kategori')
                        ->label('Kategori')
                        ->placeholder('-'),

                    Infolists\Components\TextEntry::make('satuan')
                        ->label('Satuan')
                        ->badge()
                        ->color('info'),

                    Infolists\Components\TextEntry::make('min_stok_default')
                        ->label('Batas Minimum Default per Cabang')
                        ->numeric(),

                    Infolists\Components\TextEntry::make('is_active')
                        ->label('Status Barang')
                        ->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')
                        ->color(fn (bool $state): string => $state ? 'success' : 'gray'),

                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Dibuat')
                        ->dateTime('d M Y H:i'),

                    Infolists\Components\TextEntry::make('updated_at')
                        ->label('Terakhir Diperbarui')
                        ->dateTime('d M Y H:i'),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Ringkasan Stok')
                ->schema([
                    Infolists\Components\TextEntry::make('total_stock')
                        ->label('Total Stok Cabang Inventory')
                        ->badge()
                        ->color('primary')
                        ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.')),

                    Infolists\Components\TextEntry::make('branch_stock_count')
                        ->label('Cabang Menyimpan')
                        ->badge()
                        ->color('info')
                        ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.').' cabang'),

                    Infolists\Components\TextEntry::make('stock_status')
                        ->label('Status Stok')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => InventoryStock::STATUS_LABELS[$state] ?? (string) $state)
                        ->color(fn (?string $state): string => InventoryStock::STATUS_COLORS[$state] ?? 'gray'),
                ])
                ->columns(3),
        ]);
    }

    /**
     * Form untuk mendaftarkan master barang yang sudah ada ke cabang lain.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function registerBranchForm(InventoryItem $item): array
    {
        return [
            Forms\Components\Select::make('cabang_id')
                ->label('Cabang Tujuan')
                ->options(fn (): array => app(InventoryService::class)->availableBranchOptions($item))
                ->required()
                ->selectablePlaceholder(false)
                ->validationMessages([
                    'required' => 'Cabang tujuan wajib dipilih.',
                ]),

            Forms\Components\TextInput::make('stok_awal')
                ->label('Stok Awal')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0)
                ->required()
                ->helperText('Hanya diterapkan pada cabang yang dipilih. Stok awal dicatat sebagai barang masuk.')
                ->validationMessages([
                    'required' => 'Stok awal wajib diisi.',
                    'integer' => 'Stok awal harus berupa bilangan bulat.',
                    'min' => 'Stok awal tidak boleh negatif.',
                ]),

            Forms\Components\TextInput::make('min_stok')
                ->label('Batas Minimum Stok')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default($item->min_stok_default)
                ->required()
                ->helperText('Batas minimum khusus untuk cabang yang dipilih.')
                ->validationMessages([
                    'required' => 'Batas minimum stok wajib diisi.',
                    'min' => 'Batas minimum stok tidak boleh negatif.',
                ]),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\StocksRelationManager::class,
            RelationManagers\TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryItems::route('/'),
            'create' => Pages\CreateInventoryItem::route('/create'),
            'view' => Pages\ViewInventoryItem::route('/{record}'),
            'edit' => Pages\EditInventoryItem::route('/{record}/edit'),
        ];
    }
}
