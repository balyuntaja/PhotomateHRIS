<?php

namespace App\Filament\Resources\InventoryItemResource\RelationManagers;

use App\Filament\Concerns\GroupedStockRecordResolver;
use App\Filament\Resources\InventoryItemResource;
use App\Models\InventoryStock;
use App\Services\InventoryService;
use App\Utils\InventoryAccess;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class StocksRelationManager extends RelationManager
{
    protected static string $relationship = 'stocks';

    protected static ?string $title = 'Stok per Cabang';

    protected function resolveTableRecord(?string $key): ?Model
    {
        return GroupedStockRecordResolver::resolve($this, $key);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(InventoryService::class)->stockQuery()
                ->where('inventory_item_id', $this->getOwnerRecord()->getKey()))
            ->columns([
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
                    ->color(fn (?string $state): string => InventoryStock::STATUS_COLORS[$state] ?? 'gray'),

                Tables\Columns\TextColumn::make('last_transaction_at')
                    ->label('Terakhir Transaksi')
                    ->dateTime('d M Y H:i')
                    ->placeholder('Belum ada transaksi')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Terakhir Diperbarui')
                    ->dateTime('d M Y H:i'),
            ])
            ->defaultSort('cabang_id')
            ->paginated(false)
            ->headerActions([
                Tables\Actions\Action::make('daftarkan_cabang')
                    ->label('Daftarkan ke Cabang')
                    ->icon('heroicon-o-plus-circle')
                    ->color('gray')
                    ->form(fn (): array => InventoryItemResource::registerBranchForm($this->getOwnerRecord()))
                    ->visible(fn (): bool => InventoryAccess::allowed(Auth::user())
                        && app(InventoryService::class)->availableBranchOptions($this->getOwnerRecord()) !== [])
                    ->action(function (array $data): void {
                        app(InventoryService::class)->registerBranchStock(
                            $this->getOwnerRecord(),
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
            ]);
    }
}
