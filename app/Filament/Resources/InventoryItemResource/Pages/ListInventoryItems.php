<?php

namespace App\Filament\Resources\InventoryItemResource\Pages;

use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\InventoryTransactionResource;
use App\Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListInventoryItems extends ListRecords
{
    protected static string $resource = InventoryItemResource::class;

    protected static ?string $title = 'Daftar Barang';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Tambah Barang')
                ->icon('heroicon-o-plus'),

            Actions\Action::make('catat_transaksi')
                ->label('Catat Transaksi Stok')
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray')
                ->url(fn (): string => InventoryTransactionResource::getUrl('create')),
        ];
    }
}
