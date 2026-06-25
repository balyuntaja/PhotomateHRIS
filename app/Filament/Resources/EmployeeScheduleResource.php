<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeScheduleResource\Pages;
use App\Filament\Resources\EmployeeScheduleResource\RelationManagers;
use App\Models\EmployeeSchedule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EmployeeScheduleResource extends Resource
{
    protected static ?string $model = EmployeeSchedule::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    public static function canAccess(): bool
    {
        /** @var \App\Models\Karyawan $user */
        $user = auth()->user();
        return $user && (
            $user->role_id === 'R01' || 
            $user->role_id === 'R06' || 
            $user->hasRole(['Admin', 'admin', 'CEO', 'ceo'])
        );
    }

    protected static ?string $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Schedule Karyawan';

    protected static ?string $modelLabel = 'Schedule Karyawan';

    protected static ?string $pluralModelLabel = 'Schedule Karyawan';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('karyawan_id')
                    ->label('Nama Karyawan')
                    ->relationship('karyawan', 'nama_lengkap')
                    ->required()
                    ->searchable()
                    ->preload(),
                Forms\Components\DatePicker::make('shift_date')
                    ->label('Tanggal Shift')
                    ->required()
                    ->rule(function (Forms\Get $get, $record) {
                        return function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                            $karyawanId = $get('karyawan_id');
                            if (!$karyawanId) return;

                            $query = EmployeeSchedule::where('karyawan_id', $karyawanId)
                                ->whereDate('shift_date', $value);

                            if ($record) {
                                $query->where('id', '!=', $record->id);
                            }

                            if ($query->exists()) {
                                $fail('Karyawan sudah memiliki jadwal shift pada tanggal ini.');
                            }
                        };
                    }),
                Forms\Components\Select::make('shift_type_select')
                    ->label('Pilihan Waktu Shift')
                    ->options([
                        '08:00 - 16:00' => '08:00 - 16:00',
                        '16:00 - 24:00' => '16:00 - 24:00',
                        '18:00 - 24:00' => '18:00 - 24:00',
                        '08:00 - 24:00' => '08:00 - 24:00 (Full Day)',
                        'custom' => 'Kustom (Tentukan Waktu Sendiri)',
                    ])
                    ->required()
                    ->live()
                    ->dehydrated(false)
                    ->afterStateHydrated(function (Forms\Components\Select $component, $state, $record) {
                        if ($record && $record->shift_type) {
                            $predefined = ['08:00 - 16:00', '16:00 - 24:00', '18:00 - 24:00', '08:00 - 24:00'];
                            if (in_array($record->shift_type, $predefined)) {
                                $component->state($record->shift_type);
                            } else {
                                $component->state('custom');
                            }
                        }
                    }),
                Forms\Components\TimePicker::make('shift_start_time')
                    ->label('Jam Mulai Shift')
                    ->seconds(false)
                    ->dehydrated(false)
                    ->visible(fn (Forms\Get $get): bool => $get('shift_type_select') === 'custom')
                    ->required(fn (Forms\Get $get): bool => $get('shift_type_select') === 'custom')
                    ->afterStateHydrated(function (Forms\Components\TimePicker $component, $state, $record) {
                        if ($record && $record->shift_type) {
                            $parts = explode(' - ', $record->shift_type);
                            if (count($parts) === 2) {
                                $component->state($parts[0]);
                            }
                        }
                    }),
                Forms\Components\TimePicker::make('shift_end_time')
                    ->label('Jam Selesai Shift')
                    ->seconds(false)
                    ->dehydrated(false)
                    ->visible(fn (Forms\Get $get): bool => $get('shift_type_select') === 'custom')
                    ->required(fn (Forms\Get $get): bool => $get('shift_type_select') === 'custom')
                    ->afterStateHydrated(function (Forms\Components\TimePicker $component, $state, $record) {
                        if ($record && $record->shift_type) {
                            $parts = explode(' - ', $record->shift_type);
                            if (count($parts) === 2) {
                                $component->state($parts[1]);
                            }
                        }
                    }),
                Forms\Components\Select::make('booth_location')
                    ->label('Lokasi Booth')
                    ->options([
                        'Newspaper Booth' => 'Newspaper Booth',
                        'Event Express' => 'Event Express',
                    ])
                    ->placeholder('Pilih Lokasi/Kategori Booth'),
                Forms\Components\Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(3)
                    ->columnSpanFull()
                    ->maxLength(65535),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee_name')
                    ->label('Nama Karyawan')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('shift_date')
                    ->label('Tanggal Shift')
                    ->date()
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
                    })
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('booth_location')
                    ->label('Lokasi Booth')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('notes')
                    ->label('Catatan')
                    ->limit(30)
                    ->placeholder('-'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('booth_location')
                    ->label('Lokasi/Kategori Booth')
                    ->options([
                        'Newspaper Booth' => 'Newspaper Booth',
                        'Event Express' => 'Event Express',
                    ]),
                Tables\Filters\Filter::make('shift_date')
                    ->form([
                        Forms\Components\DatePicker::make('shift_date_from')->label('Mulai Tanggal'),
                        Forms\Components\DatePicker::make('shift_date_until')->label('Sampai Tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['shift_date_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('shift_date', '>=', $date),
                            )
                            ->when(
                                $data['shift_date_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('shift_date', '<=', $date),
                            );
                    })
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployeeSchedules::route('/'),
            'create' => Pages\CreateEmployeeSchedule::route('/create'),
            'edit' => Pages\EditEmployeeSchedule::route('/{record}/edit'),
            'weekly' => Pages\WeeklySchedule::route('/weekly'),
        ];
    }
}
