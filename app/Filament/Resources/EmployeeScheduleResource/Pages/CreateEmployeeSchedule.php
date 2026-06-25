<?php

namespace App\Filament\Resources\EmployeeScheduleResource\Pages;

use App\Filament\Resources\EmployeeScheduleResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeSchedule extends CreateRecord
{
    protected static string $resource = EmployeeScheduleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (isset($data['shift_type_select'])) {
            if ($data['shift_type_select'] === 'custom') {
                if (isset($data['shift_start_time']) && isset($data['shift_end_time'])) {
                    $start = date('H:i', strtotime($data['shift_start_time']));
                    $end = date('H:i', strtotime($data['shift_end_time']));
                    $data['shift_type'] = "{$start} - {$end}";
                }
            } else {
                $data['shift_type'] = $data['shift_type_select'];
            }
        }
        return $data;
    }
}
