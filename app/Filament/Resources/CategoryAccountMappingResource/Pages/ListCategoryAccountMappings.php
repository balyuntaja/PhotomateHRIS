<?php

namespace App\Filament\Resources\CategoryAccountMappingResource\Pages;

use App\Filament\Resources\CategoryAccountMappingResource;
use App\Filament\Resources\Pages\ListRecords;
use App\Models\CategoryAccountMapping;
use App\Services\AccountingMasterDataService;
use Filament\Actions;
use Illuminate\Support\Facades\Auth;

class ListCategoryAccountMappings extends ListRecords
{
    protected static string $resource = CategoryAccountMappingResource::class;

    protected static ?string $title = 'Mapping Kategori ke COA';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Tambah Mapping')
                ->icon('heroicon-o-plus')
                ->modalHeading('Tambah Mapping Kategori')
                ->modalSubmitActionLabel('Simpan')
                ->createAnother(false)
                ->successNotificationTitle('Mapping berhasil disimpan.')
                ->using(function (array $data): CategoryAccountMapping {
                    $reason = $data['change_reason'] ?? null;
                    unset($data['change_reason']);

                    return app(AccountingMasterDataService::class)->createMapping($data, Auth::user(), $reason);
                }),
        ];
    }
}
