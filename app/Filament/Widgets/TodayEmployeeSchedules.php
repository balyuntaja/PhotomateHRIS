<?php

namespace App\Filament\Widgets;

use App\Models\EmployeeSchedule;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TodayEmployeeSchedules extends BaseWidget
{
    protected static ?string $heading = 'Jadwal Hari Ini 📅';

    protected static ?int $sort = 1; // Show first or near top

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                EmployeeSchedule::query()->whereDate('shift_date', Carbon::today())
            )
            ->columns([
                Tables\Columns\TextColumn::make('employee_name')
                    ->label('Nama Karyawan')
                    ->sortable(),
                Tables\Columns\TextColumn::make('shift_type')
                    ->label('Jenis Shift')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        '08:00 - 16:00' => 'success',
                        '16:00 - 24:00' => 'info',
                        '18:00 - 24:00' => 'warning',
                        '08:00 - 24:00' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('booth_location')
                    ->label('Lokasi Booth')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('notes')
                    ->label('Catatan')
                    ->limit(50)
                    ->placeholder('-'),
            ]);
    }
}
