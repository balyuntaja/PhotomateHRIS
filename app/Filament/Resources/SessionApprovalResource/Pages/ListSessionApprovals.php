<?php

namespace App\Filament\Resources\SessionApprovalResource\Pages;

use App\Filament\Resources\SessionApprovalResource;
use Filament\Resources\Pages\ListRecords;

class ListSessionApprovals extends ListRecords
{
    protected static string $resource = SessionApprovalResource::class;

    protected static ?string $title = 'Approval Rekap Sesi';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
