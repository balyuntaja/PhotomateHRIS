<?php

namespace App\Filament\Resources\InventoryItemResource\Pages;

use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\InventoryTransactionResource;
use App\Filament\Resources\Pages\EditRecord;
use Filament\Actions;

class EditInventoryItem extends EditRecord
{
    protected static string $resource = InventoryItemResource::class;

    protected static ?string $title = 'Ubah Barang';

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()
                ->label('Lihat'),

            Actions\Action::make('catat_transaksi')
                ->label('Catat Transaksi Stok')
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray')
                ->url(fn (): string => InventoryTransactionResource::getUrl('create', [
                    'item' => $this->getRecord()->getKey(),
                ])),
        ];
    }
}
