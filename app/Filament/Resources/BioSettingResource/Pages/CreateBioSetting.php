<?php

namespace App\Filament\Resources\BioSettingResource\Pages;

use App\Filament\Resources\BioSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateBioSetting extends CreateRecord
{
    protected static string $resource = BioSettingResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
