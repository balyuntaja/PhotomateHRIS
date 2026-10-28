<?php

namespace App\Filament\Resources\InventoryItemResource\RelationManagers;

use App\Models\InventoryTransaction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Riwayat Transaksi Terbaru';

    public function table(Table $table): Table
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

                Tables\Columns\TextColumn::make('inventory_branch_label')
                    ->label('Cabang')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('jenis_transaksi')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (InventoryTransaction $record): string => $record->jenis_label)
                    ->color(fn (InventoryTransaction $record): string => $record->jenis_color),

                Tables\Columns\TextColumn::make('jumlah_masuk')
                    ->label('Masuk')
                    ->state(fn (InventoryTransaction $record): int => $record->jumlah_masuk)
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? '+'.number_format($state, 0, ',', '.') : '-')
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('jumlah_keluar')
                    ->label('Keluar')
                    ->state(fn (InventoryTransaction $record): int => $record->jumlah_keluar)
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? '-'.number_format($state, 0, ',', '.') : '-')
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('stok_sebelum')
                    ->label('Sebelum')
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.'))
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('stok_sesudah')
                    ->label('Sesudah')
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.'))
                    ->alignCenter()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('user_name')
                    ->label('Pengguna')
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dicatat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Belum ada transaksi stok')
            ->emptyStateDescription('Transaksi barang masuk, keluar, dan penyesuaian akan tampil di sini.');
    }
}
