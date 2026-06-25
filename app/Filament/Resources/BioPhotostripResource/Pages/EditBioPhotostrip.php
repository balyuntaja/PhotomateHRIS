<?php

namespace App\Filament\Resources\BioPhotostripResource\Pages;

use App\Filament\Resources\BioPhotostripResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBioPhotostrip extends EditRecord
{
    protected static string $resource = BioPhotostripResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
