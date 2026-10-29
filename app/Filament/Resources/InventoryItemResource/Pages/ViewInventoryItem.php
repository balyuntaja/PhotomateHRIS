<?php

namespace App\Filament\Resources\InventoryItemResource\Pages;

use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\InventoryTransactionResource;
use App\Filament\Resources\Pages\ViewRecord;
use Filament\Actions;

class ViewInventoryItem extends ViewRecord
{
    protected static string $resource = InventoryItemResource::class;

    protected static ?string $title = 'Detail Barang';

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->label('Ubah'),

            Actions\Action::make('catat_transaksi')
                ->label('Catat Transaksi Stok')
                ->icon('heroicon-o-arrows-right-left')
                ->url(fn (): string => InventoryTransactionResource::getUrl('create', [
                    'item' => $this->getRecord()->getKey(),
                ])),
        ];
    }
}
