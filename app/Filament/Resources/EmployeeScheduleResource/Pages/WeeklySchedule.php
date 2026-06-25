<?php

namespace App\Filament\Resources\EmployeeScheduleResource\Pages;

use App\Filament\Resources\EmployeeScheduleResource;
use App\Models\Karyawan;
use App\Models\EmployeeSchedule;
use Carbon\Carbon;
use Filament\Resources\Pages\Page;

class WeeklySchedule extends Page
{
    protected static string $resource = EmployeeScheduleResource::class;

    protected static string $view = 'filament.resources.employee-schedule-resource.pages.weekly-schedule';

    protected static ?string $title = 'Jadwal Mingguan Karyawan';

    public string $weekStart;

    public function mount()
    {
        $this->weekStart = Carbon::now()->startOfWeek()->toDateString();
    }

    public function prevWeek()
    {
        $this->weekStart = Carbon::parse($this->weekStart)->subWeek()->toDateString();
    }

    public function nextWeek()
    {
        $this->weekStart = Carbon::parse($this->weekStart)->addWeek()->toDateString();
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('list_view')
                ->label('Kembali ke Daftar (List View)')
                ->icon('heroicon-o-list-bullet')
                ->url(fn () => static::getResource()::getUrl('index'))
                ->color('gray'),
        ];
    }

    protected function getViewData(): array
    {
        $start = Carbon::parse($this->weekStart);
        $end = $start->copy()->endOfWeek();

        // Generate the 7 days of the week
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = $start->copy()->addDays($i);
        }

        // Get all active employees
        $employees = Karyawan::orderBy('nama_lengkap')->get();

        // Get all schedules for the week
        $schedules = EmployeeSchedule::whereBetween('shift_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        // Map schedules by employee_id and date
        $scheduleGrid = [];
        $employeeShiftCounts = [];

        foreach ($employees as $employee) {
            $employeeShiftCounts[$employee->karyawan_id] = 0;
            foreach ($days as $day) {
                $dateStr = $day->toDateString();
                $shift = $schedules->first(function ($s) use ($employee, $dateStr) {
                    return $s->karyawan_id === $employee->karyawan_id && $s->shift_date->toDateString() === $dateStr;
                });

                $scheduleGrid[$employee->karyawan_id][$dateStr] = $shift;

                if ($shift) {
                    $employeeShiftCounts[$employee->karyawan_id]++;
                }
            }
        }

        return [
            'days' => $days,
            'employees' => $employees,
            'scheduleGrid' => $scheduleGrid,
            'employeeShiftCounts' => $employeeShiftCounts,
            'weekLabel' => $start->translatedFormat('d M Y') . ' - ' . $end->translatedFormat('d M Y'),
        ];
    }
}
