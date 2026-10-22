<?php

namespace App\Filament\Resources\InventoryItemResource\RelationManagers;

use App\Models\InventoryStock;
use App\Services\InventoryService;
use App\Utils\InventoryAccess;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class StocksRelationManager extends RelationManager
{
    protected static string $relationship = 'stocks';

    protected static ?string $title = 'Stok per Cabang';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('cabang.nama_cabang')
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
                Tables\Actions\Action::make('sinkronkan')
                    ->label('Sinkronkan Stok Cabang')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalHeading('Sinkronkan Stok Cabang')
                    ->modalDescription('Menambahkan baris stok yang belum ada untuk seluruh cabang aktif. Jumlah stok yang sudah tercatat tidak diubah.')
                    ->modalSubmitActionLabel('Ya, sinkronkan')
                    ->visible(fn (): bool => InventoryAccess::allowed(Auth::user()))
                    ->action(function (): void {
                        $created = app(InventoryService::class)->syncStocks($this->getOwnerRecord());

                        Notification::make()
                            ->title('Sinkronisasi selesai')
                            ->body($created.' baris stok baru ditambahkan.')
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
