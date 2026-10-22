<?php

namespace App\Filament\Resources\InventoryItemResource\Pages;

use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\InventoryTransactionResource;
use App\Filament\Resources\Pages\EditRecord;
use App\Services\InventoryService;
use Filament\Actions;

class EditInventoryItem extends EditRecord
{
    protected static string $resource = InventoryItemResource::class;

    protected static ?string $title = 'Ubah Barang';

    protected function afterSave(): void
    {
        app(InventoryService::class)->ensureStocks($this->getRecord());
    }

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
