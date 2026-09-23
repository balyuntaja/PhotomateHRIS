<?php

namespace App\Filament\Resources\SessionReportResource\Pages;

use App\Filament\Resources\SessionReportResource;
use App\Models\SessionReport;
use App\Services\SessionWorkflowService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewSessionReport extends ViewRecord
{
    protected static string $resource = SessionReportResource::class;

    protected static ?string $title = 'Detail Rekap Sesi';

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn () => in_array($this->record->status, [SessionReport::STATUS_DRAFT, SessionReport::STATUS_REVISION])
                    && !app(SessionWorkflowService::class)->isInputClosed(Auth::user(), $this->record->report_date)),
            Actions\DeleteAction::make()
                ->visible(fn () => (bool) \Illuminate\Support\Facades\Auth::user()?->isSuperAdmin()),
        ];
    }

    public function infolist(\Filament\Infolists\Infolist $infolist): \Filament\Infolists\Infolist
    {
        return $infolist
            ->schema([
                \Filament\Infolists\Components\ViewEntry::make('review_content')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->view('filament.resources.session-reports.review-modal', [
                        'record' => $this->record,
                    ]),
            ]);
    }
}
