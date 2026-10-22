<?php

namespace App\Filament\Resources\InventoryTransactionResource\Pages;

use App\Filament\Resources\InventoryTransactionResource;
use App\Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListInventoryTransactions extends ListRecords
{
    protected static string $resource = InventoryTransactionResource::class;

    protected static ?string $title = 'Riwayat Stok';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Catat Transaksi')
                ->icon('heroicon-o-plus'),
        ];
    }
}
