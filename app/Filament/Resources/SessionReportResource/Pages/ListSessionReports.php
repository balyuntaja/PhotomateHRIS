<?php

namespace App\Filament\Resources\SessionReportResource\Pages;

use App\Filament\Resources\SessionReportResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSessionReports extends ListRecords
{
    protected static string $resource = SessionReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Buat Rekap')
                ->icon('heroicon-o-plus'),
        ];
    }
}
