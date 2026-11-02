<?php

namespace App\Filament\Resources\FinancialParentCategoryResource\Pages;

use App\Filament\Resources\FinancialCategoryResource;
use App\Filament\Resources\FinancialParentCategoryResource;
use App\Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListFinancialParentCategories extends ListRecords
{
    protected static string $resource = FinancialParentCategoryResource::class;

    protected static ?string $title = 'Kategori Utama';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Tambah Kategori Utama')
                ->icon('heroicon-o-plus')
                ->modalHeading('Tambah Kategori Utama')
                ->modalSubmitActionLabel('Simpan')
                ->createAnother(false)
                ->successNotificationTitle('Kategori utama berhasil disimpan.'),

            Actions\Action::make('kembali')
                ->label('Kembali ke Kategori Keuangan')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => FinancialCategoryResource::getUrl())
                ->visible(fn (): bool => FinancialCategoryResource::canViewAny()),
        ];
    }
}
