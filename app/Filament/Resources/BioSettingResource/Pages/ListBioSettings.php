<?php

namespace App\Filament\Resources\BioSettingResource\Pages;

use App\Filament\Resources\BioSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBioSettings extends ListRecords
{
    protected static string $resource = BioSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
