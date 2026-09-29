<?php

namespace App\Filament\Resources\FinancialCategoryResource\Pages;

use App\Filament\Resources\FinancialCategoryResource;
use App\Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListFinancialCategories extends ListRecords
{
    protected static string $resource = FinancialCategoryResource::class;

    protected static ?string $title = 'Kategori Keuangan';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Tambah Kategori')
                ->icon('heroicon-o-plus')
                ->modalHeading('Tambah Kategori')
                ->modalSubmitActionLabel('Simpan')
                ->createAnother(false)
                ->successNotificationTitle('Kategori berhasil disimpan.'),
        ];
    }
}
