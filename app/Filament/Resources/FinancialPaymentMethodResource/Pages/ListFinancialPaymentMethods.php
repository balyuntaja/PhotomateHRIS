<?php

namespace App\Filament\Resources\FinancialPaymentMethodResource\Pages;

use App\Filament\Resources\FinancialPaymentMethodResource;
use App\Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListFinancialPaymentMethods extends ListRecords
{
    protected static string $resource = FinancialPaymentMethodResource::class;

    protected static ?string $title = 'Metode Pembayaran';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Tambah Metode')
                ->icon('heroicon-o-plus')
                ->modalHeading('Tambah Metode Pembayaran')
                ->modalSubmitActionLabel('Simpan')
                ->createAnother(false)
                ->successNotificationTitle('Metode pembayaran berhasil disimpan.'),
        ];
    }
}
