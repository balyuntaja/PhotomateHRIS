<?php

namespace App\Filament\Resources\InventoryItemResource\Pages;

use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\InventoryTransactionResource;
use App\Filament\Resources\Pages\ViewRecord;
use App\Services\InventoryService;
use Filament\Actions;
use Filament\Notifications\Notification;

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

            Actions\Action::make('sinkronkan_stok')
                ->label('Sinkronkan Stok Cabang')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Sinkronkan Stok Cabang')
                ->modalDescription('Menambahkan baris stok yang belum ada untuk seluruh cabang aktif. Jumlah stok yang sudah tercatat tidak diubah.')
                ->modalSubmitActionLabel('Ya, sinkronkan')
                ->action(function (): void {
                    $created = app(InventoryService::class)->syncStocks($this->getRecord());

                    Notification::make()
                        ->title('Sinkronisasi selesai')
                        ->body($created.' baris stok baru ditambahkan.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
