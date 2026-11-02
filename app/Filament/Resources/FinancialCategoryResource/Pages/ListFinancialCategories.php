<?php

namespace App\Filament\Resources\FinancialCategoryResource\Pages;

use App\Filament\Resources\FinancialCategoryResource;
use App\Filament\Resources\FinancialParentCategoryResource;
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
                ->label('Tambah Sub Kategori')
                ->icon('heroicon-o-plus')
                ->modalHeading('Tambah Sub Kategori')
                ->modalSubmitActionLabel('Simpan')
                ->createAnother(false)
                ->successNotificationTitle('Sub kategori berhasil disimpan.'),

            Actions\Action::make('kategori_utama')
                ->label('Kelola Kategori Utama')
                ->icon('heroicon-o-folder')
                ->color('gray')
                ->url(fn (): string => FinancialParentCategoryResource::getUrl())
                ->authorize(fn (): bool => FinancialParentCategoryResource::canViewAny()),
        ];
    }
}
