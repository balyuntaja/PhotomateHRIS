<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GroupedStockRecordResolver;
use App\Filament\Resources\InventoryTransactionResource;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Services\InventoryService;
use App\Utils\InventoryAccess;
use App\Utils\InventoryBranches;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class InventoryStocks extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Stok per Cabang';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Stok per Cabang';

    protected static ?string $slug = 'inventory-stok';

    protected static string $view = 'filament.pages.inventory-stocks';

    public static function canAccess(): bool
    {
        return InventoryAccess::allowed(Auth::user());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected function resolveTableRecord(?string $key): ?Model
    {
        return GroupedStockRecordResolver::resolve($this, $key);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(InventoryService::class)->stockQuery()
                ->whereHas('item', fn (Builder $query): Builder => $query->active()))
            ->columns([
                Tables\Columns\TextColumn::make('item.nama_barang')
                    ->label('Barang')
                    ->searchable(['nama_barang', 'kode_barang'])
                    ->weight('semibold')
                    ->description(fn (InventoryStock $record): string => $record->item?->kode_barang ?? '-'),

                Tables\Columns\TextColumn::make('inventory_branch_label')
                    ->label('Cabang')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('jumlah')
                    ->label('Stok Tersedia')
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.'))
                    ->alignCenter()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('min_stok')
                    ->label('Batas Minimum')
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.'))
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status Stok')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => InventoryStock::STATUS_LABELS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => InventoryStock::STATUS_COLORS[$state] ?? 'gray')
                    ->tooltip(fn (InventoryStock $record): ?string => match ($record->status) {
                        InventoryStock::STATUS_HABIS => 'Barang terdaftar pada cabang ini dengan stok 0. Barang yang belum terdaftar tidak termasuk.',
                        InventoryStock::STATUS_MENIPIS => 'Stok masih di atas 0 tetapi sudah mencapai atau melewati batas minimum cabang.',
                        default => null,
                    }),

                Tables\Columns\TextColumn::make('last_transaction_at')
                    ->label('Terakhir Transaksi')
                    ->dateTime('d M Y H:i')
                    ->placeholder('Belum ada transaksi'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Terakhir Diperbarui')
                    ->dateTime('d M Y H:i'),
            ])
            ->searchPlaceholder('Cari nama atau kode barang')
            ->filters([
                Tables\Filters\SelectFilter::make('inventory_item_id')
                    ->label('Barang')
                    ->options(fn (): array => InventoryItem::query()
                        ->active()
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
                        $branchId = InventoryBranches::filterCanonicalId($data['value'] ?? null);

                        return $branchId === null
                            ? $query
                            : $query->where('cabang_id', $branchId);
                    }),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status Stok')
                    ->options(InventoryStock::STATUS_LABELS)
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            InventoryStock::STATUS_HABIS => $query->where('jumlah', 0),
                            InventoryStock::STATUS_MENIPIS => $query
                                ->where('jumlah', '>', 0)
                                ->whereColumn('jumlah', '<=', 'min_stok'),
                            InventoryStock::STATUS_AMAN => $query
                                ->where('jumlah', '>', 0)
                                ->whereColumn('jumlah', '>', 'min_stok'),
                            default => $query,
                        };
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('atur_minimum')
                    ->label('Atur Batas Minimum')
                    ->icon('heroicon-m-adjustments-horizontal')
                    ->color('gray')
                    ->form([
                        Forms\Components\TextInput::make('min_stok')
                            ->label('Batas Minimum Stok')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required()
                            ->helperText('Batas minimum khusus untuk cabang ini.'),
                    ])
                    ->fillForm(fn (InventoryStock $record): array => [
                        'min_stok' => $record->min_stok,
                    ])
                    ->visible(fn (): bool => InventoryAccess::allowed(Auth::user()))
                    ->action(function (InventoryStock $record, array $data): void {
                        app(InventoryService::class)->updateMinStock($record, (int) $data['min_stok']);

                        Notification::make()
                            ->title('Batas minimum stok diperbarui')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('catat_transaksi')
                    ->label('Catat Transaksi')
                    ->icon('heroicon-m-plus')
                    ->url(fn (InventoryStock $record): string => InventoryTransactionResource::getUrl('create', [
                        'item' => $record->inventory_item_id,
                        'cabang' => $record->cabang_id,
                    ])),
            ])
            ->defaultSort('cabang_id')
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Belum ada data stok per cabang')
            ->emptyStateDescription('Tambahkan barang dan pilih cabang tujuan, atau daftarkan barang yang sudah ada ke cabang lain untuk membuat baris stok.')
            ->emptyStateIcon('heroicon-o-building-storefront');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('catat_transaksi')
                ->label('Catat Transaksi')
                ->icon('heroicon-o-plus')
                ->url(fn (): string => InventoryTransactionResource::getUrl('create')),
        ];
    }
}
