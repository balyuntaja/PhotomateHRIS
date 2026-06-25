<?php

namespace App\Filament\Resources\BioSettingResource\Pages;

use App\Filament\Resources\BioSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBioSetting extends EditRecord
{
    protected static string $resource = BioSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
