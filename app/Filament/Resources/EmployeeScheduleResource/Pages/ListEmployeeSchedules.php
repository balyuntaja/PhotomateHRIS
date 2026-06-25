<?php

namespace App\Filament\Resources\EmployeeScheduleResource\Pages;

use App\Filament\Resources\EmployeeScheduleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeSchedules extends ListRecords
{
    protected static string $resource = EmployeeScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('weekly_view')
                ->label('Jadwal Mingguan (Weekly View)')
                ->icon('heroicon-o-calendar')
                ->url(fn () => static::getResource()::getUrl('weekly'))
                ->color('info'),
            Actions\CreateAction::make(),
        ];
    }
}
