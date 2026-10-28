<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryTransactionResource\Pages;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Karyawan;
use App\Services\InventoryService;
use App\Utils\InventoryAccess;
use App\Utils\InventoryBranches;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class InventoryTransactionResource extends Resource
{
    protected static ?string $model = InventoryTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Riwayat Stok';

    protected static ?string $modelLabel = 'Transaksi Stok';

    protected static ?string $pluralModelLabel = 'Transaksi Stok';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'inventory-riwayat';

    public static function canViewAny(): bool
    {
        return InventoryAccess::allowed(Auth::user());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['item', 'cabang', 'user']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Transaksi')
                ->schema([
                    Forms\Components\ToggleButtons::make('jenis_transaksi')
                        ->label('Jenis Transaksi')
                        ->options(InventoryTransaction::TYPE_LABELS)
                        ->icons(InventoryTransaction::TYPE_ICONS)
                        ->colors(InventoryTransaction::TYPE_COLORS)
                        ->inline()
                        ->default(InventoryTransaction::TYPE_IN)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set): void {
                            $set('jumlah', null);
                            $set('stok_aktual', null);
                        })
                        ->columnSpanFull()
                        ->validationMessages(['required' => 'Jenis transaksi wajib dipilih.']),

                    Forms\Components\DatePicker::make('tanggal_transaksi')
                        ->label('Tanggal Transaksi')
                        ->default(now())
                        ->required()
                        ->validationMessages([
                            'required' => 'Tanggal transaksi wajib diisi.',
                            'date' => 'Tanggal transaksi tidak valid.',
                        ]),

                    Forms\Components\Select::make('inventory_item_id')
                        ->label('Barang')
                        ->options(fn (): array => InventoryItem::query()
                            ->active()
                            ->orderBy('nama_barang')
                            ->get()
                            ->mapWithKeys(fn (InventoryItem $item): array => [
                                $item->id => $item->kode_barang.' · '.$item->nama_barang,
                            ])
                            ->all())
                        ->searchable()
                        ->required()
                        ->live()
                        ->validationMessages([
                            'required' => 'Barang wajib dipilih.',
                            'exists' => 'Barang tidak ditemukan.',
                        ]),

                    Forms\Components\Select::make('cabang_id')
                        ->label('Cabang')
                        ->options(fn (): array => InventoryBranches::options())
                        ->searchable()
                        ->required()
                        ->live()
                        ->validationMessages([
                            'required' => 'Cabang wajib dipilih.',
                            'exists' => 'Cabang tidak ditemukan.',
                        ]),

                    Forms\Components\TextInput::make('jumlah')
                        ->label('Jumlah')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->required(fn (Get $get): bool => in_array($get('jenis_transaksi'), [
                            InventoryTransaction::TYPE_IN,
                            InventoryTransaction::TYPE_OUT,
                        ], true))
                        ->visible(fn (Get $get): bool => in_array($get('jenis_transaksi'), [
                            InventoryTransaction::TYPE_IN,
                            InventoryTransaction::TYPE_OUT,
                        ], true))
                        ->live()
                        ->suffix(fn (Get $get): string => (string) (InventoryItem::query()
                            ->whereKey($get('inventory_item_id'))
                            ->value('satuan') ?? ''))
                        ->validationMessages([
                            'required' => 'Jumlah transaksi wajib diisi.',
                            'integer' => 'Jumlah transaksi harus berupa bilangan bulat.',
                            'min' => 'Jumlah transaksi harus lebih dari nol.',
                        ]),

                    Forms\Components\TextInput::make('stok_aktual')
                        ->label('Stok Aktual (hasil stock opname)')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->required(fn (Get $get): bool => $get('jenis_transaksi') === InventoryTransaction::TYPE_ADJUSTMENT)
                        ->visible(fn (Get $get): bool => $get('jenis_transaksi') === InventoryTransaction::TYPE_ADJUSTMENT)
                        ->live()
                        ->suffix(fn (Get $get): string => (string) (InventoryItem::query()
                            ->whereKey($get('inventory_item_id'))
                            ->value('satuan') ?? ''))
                        ->helperText('Masukkan jumlah stok fisik yang sebenarnya. Selisih terhadap stok sistem akan dicatat.')
                        ->validationMessages([
                            'required' => 'Stok aktual wajib diisi untuk penyesuaian.',
                            'integer' => 'Stok aktual harus berupa bilangan bulat.',
                            'min' => 'Stok aktual tidak boleh negatif.',
                        ]),

                    Forms\Components\Textarea::make('alasan')
                        ->label('Alasan / Keterangan')
                        ->rows(3)
                        ->maxLength(500)
                        ->required(fn (Get $get): bool => $get('jenis_transaksi') === InventoryTransaction::TYPE_ADJUSTMENT)
                        ->helperText('Wajib diisi untuk penyesuaian stok. Sebaiknya diisi untuk semua transaksi.')
                        ->columnSpanFull()
                        ->validationMessages([
                            'required' => 'Alasan wajib diisi untuk penyesuaian stok.',
                            'max' => 'Alasan maksimal 500 karakter.',
                        ]),

                    Forms\Components\Placeholder::make('ringkasan_stok')
                        ->label('Stok Sistem')
                        ->content(fn (Get $get): string => static::stockSystemLabel($get))
                        ->columnSpanFull(),

                    Forms\Components\Hidden::make('idempotency_key')
                        ->default(fn (): string => Str::uuid()->toString()),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nomor_transaksi')
                    ->label('No. Transaksi')
                    ->searchable()
                    ->copyable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('tanggal_transaksi')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('item.nama_barang')
                    ->label('Barang')
                    ->searchable()
                    ->weight('semibold')
                    ->description(fn (InventoryTransaction $record): string => $record->item?->kode_barang ?? '-'),

                Tables\Columns\TextColumn::make('inventory_branch_label')
                    ->label('Cabang')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('source_branch_label')
                    ->label('Cabang Sumber')
                    ->state(fn (InventoryTransaction $record): ?string => $record->source_branch_label !== $record->inventory_branch_label
                        ? $record->source_branch_label
                        : null)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('jenis_transaksi')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => InventoryTransaction::TYPE_LABELS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => InventoryTransaction::TYPE_COLORS[$state] ?? 'gray'),

                Tables\Columns\TextColumn::make('jumlah_masuk')
                    ->label('Jumlah Masuk')
                    ->state(fn (InventoryTransaction $record): int => $record->jumlah_masuk)
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? '+'.number_format($state, 0, ',', '.') : '-')
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('jumlah_keluar')
                    ->label('Jumlah Keluar')
                    ->state(fn (InventoryTransaction $record): int => $record->jumlah_keluar)
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? '-'.number_format($state, 0, ',', '.') : '-')
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('stok_sebelum')
                    ->label('Stok Sebelum')
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.'))
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('stok_sesudah')
                    ->label('Stok Sesudah')
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.'))
                    ->alignCenter()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('alasan')
                    ->label('Keterangan')
                    ->placeholder('-')
                    ->limit(40)
                    ->tooltip(fn (InventoryTransaction $record): ?string => filled($record->alasan) ? $record->alasan : null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('user_name')
                    ->label('Pengguna')
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dicatat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder('Cari nomor transaksi atau barang')
            ->filters([
                Tables\Filters\Filter::make('rentang_tanggal')
                    ->label('Rentang Tanggal')
                    ->form([
                        Forms\Components\DatePicker::make('dari')
                            ->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('sampai')
                            ->label('Sampai Tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                filled($data['dari'] ?? null),
                                fn (Builder $query): Builder => $query->whereDate('tanggal_transaksi', '>=', $data['dari']),
                            )
                            ->when(
                                filled($data['sampai'] ?? null),
                                fn (Builder $query): Builder => $query->whereDate('tanggal_transaksi', '<=', $data['sampai']),
                            );
                    }),

                Tables\Filters\SelectFilter::make('inventory_item_id')
                    ->label('Barang')
                    ->options(fn (): array => InventoryItem::query()
                        ->orderBy('nama_barang')
                        ->get()
                        ->mapWithKeys(fn (InventoryItem $item): array => [
                            $item->id => $item->kode_barang.' · '.$item->nama_barang,
                        ])
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
                            : $query->whereIn('cabang_id', $branchIds);
                    }),

                Tables\Filters\SelectFilter::make('jenis_transaksi')
                    ->label('Jenis Transaksi')
                    ->options(InventoryTransaction::TYPE_LABELS),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Pengguna')
                    ->options(fn (): array => Karyawan::query()
                        ->orderBy('nama_lengkap')
                        ->pluck('nama_lengkap', 'karyawan_id')
                        ->all())
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Detail')
                    ->infolist(fn (Infolist $infolist): Infolist => static::infolist($infolist)),
            ])
            ->emptyStateHeading('Belum ada transaksi stok')
            ->emptyStateDescription('Catat barang masuk, barang keluar, atau penyesuaian stok untuk mulai membangun riwayat.')
            ->emptyStateIcon('heroicon-o-clock')
            ->defaultPaginationPageOption(25);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Detail Transaksi')
                ->schema([
                    Infolists\Components\TextEntry::make('nomor_transaksi')
                        ->label('No. Transaksi')
                        ->badge()
                        ->color('gray')
                        ->copyable(),

                    Infolists\Components\TextEntry::make('tanggal_transaksi')
                        ->label('Tanggal Transaksi')
                        ->date('d F Y'),

                    Infolists\Components\TextEntry::make('jenis_transaksi')
                        ->label('Jenis Transaksi')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => InventoryTransaction::TYPE_LABELS[$state] ?? (string) $state)
                        ->color(fn (?string $state): string => InventoryTransaction::TYPE_COLORS[$state] ?? 'gray'),

                    Infolists\Components\TextEntry::make('item.nama_barang')
                        ->label('Barang'),

                    Infolists\Components\TextEntry::make('item.kode_barang')
                        ->label('Kode / SKU')
                        ->placeholder('-'),

                    Infolists\Components\TextEntry::make('inventory_branch_label')
                        ->label('Cabang')
                        ->placeholder('-'),

                    Infolists\Components\TextEntry::make('source_branch_label')
                        ->label('Cabang Sumber Transaksi')
                        ->visible(fn (InventoryTransaction $record): bool => $record->source_branch_label !== $record->inventory_branch_label)
                        ->placeholder('-'),

                    Infolists\Components\TextEntry::make('jumlah')
                        ->label('Jumlah Transaksi')
                        ->formatStateUsing(fn (int $state, InventoryTransaction $record): string => number_format($state, 0, ',', '.').' '.($record->item?->satuan ?? '')),

                    Infolists\Components\TextEntry::make('stok_sebelum')
                        ->label('Stok Sebelum')
                        ->numeric(),

                    Infolists\Components\TextEntry::make('stok_sesudah')
                        ->label('Stok Sesudah')
                        ->numeric()
                        ->weight('semibold'),

                    Infolists\Components\TextEntry::make('alasan')
                        ->label('Alasan / Keterangan')
                        ->placeholder('-')
                        ->columnSpanFull(),

                    Infolists\Components\TextEntry::make('user_name')
                        ->label('Pengguna')
                        ->placeholder('-'),

                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Waktu Pencatatan')
                        ->dateTime('d M Y H:i'),
                ])
                ->columns(3),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryTransactions::route('/'),
            'create' => Pages\CreateInventoryTransaction::route('/create'),
        ];
    }

    private static function stockSystemLabel(Get $get): string
    {
        $itemId = filled($get('inventory_item_id')) ? (int) $get('inventory_item_id') : null;
        $cabangId = filled($get('cabang_id')) ? (string) $get('cabang_id') : null;

        $snapshot = app(InventoryService::class)->stockSnapshot($itemId, $cabangId);

        if (! $snapshot) {
            return 'Pilih barang dan cabang untuk melihat stok sistem saat ini.';
        }

        $label = 'Stok sistem: '.number_format($snapshot['jumlah'], 0, ',', '.')
            .' '.$snapshot['satuan']
            .' · Batas minimum: '.number_format($snapshot['min_stok'], 0, ',', '.')
            .' '.$snapshot['satuan']
            .' · Status: '.$snapshot['status'];

        if ($get('jenis_transaksi') === InventoryTransaction::TYPE_ADJUSTMENT && filled($get('stok_aktual'))) {
            $difference = (int) $get('stok_aktual') - $snapshot['jumlah'];
            $label .= ' · Selisih: '.($difference > 0 ? '+' : '').number_format($difference, 0, ',', '.').' '.$snapshot['satuan'];
        }

        return $label;
    }
}
