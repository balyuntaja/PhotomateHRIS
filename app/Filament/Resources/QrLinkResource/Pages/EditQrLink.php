<?php

namespace App\Filament\Resources\QrLinkResource\Pages;

use App\Filament\Resources\QrLinkResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditQrLink extends EditRecord
{
    protected static string $resource = QrLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
