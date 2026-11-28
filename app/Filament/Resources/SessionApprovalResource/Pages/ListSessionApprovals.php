<?php

namespace App\Filament\Resources\SessionApprovalResource\Pages;

use App\Filament\Resources\SessionApprovalResource;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\On;

class ListSessionApprovals extends ListRecords
{
    protected static string $resource = SessionApprovalResource::class;

    protected static ?string $title = 'Approval Rekap Sesi';

    #[On('session-report-updated')]
    public function refreshOnSessionReportUpdated(): void {}

    protected function getHeaderActions(): array
    {
        return [];
    }
}
