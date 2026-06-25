<?php

namespace App\Filament\Resources\BioPhotostripResource\Pages;

use App\Filament\Resources\BioPhotostripResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBioPhotostrips extends ListRecords
{
    protected static string $resource = BioPhotostripResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
