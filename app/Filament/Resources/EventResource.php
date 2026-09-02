<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EventResource\Pages;
use App\Filament\Resources\EventResource\RelationManagers;
use App\Models\Event;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static ?string $navigationGroup = 'Sistem Antrean';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Kelola Antrean';

    protected static ?string $pluralModelLabel = 'Kelola Antrean';

    protected static ?string $modelLabel = 'Event Antrean';

    public static function canAccess(): bool
    {
        /** @var \App\Models\Karyawan $user */
        $user = auth()->user();
        return $user && (
            $user->role_id === 'R01' || 
            $user->role_id === 'R02' || 
            $user->role_id === 'R03' || 
            $user->role_id === 'R06' || 
            $user->hasRole(['Admin', 'admin', 'CEO', 'ceo', 'Manager HRD', 'manager hrd', 'Staff HRD', 'staff hrd'])
        );
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Detail Event')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Event / Pernikahan')
                            ->placeholder('Contoh: Wedding Kinan & Tryadit')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('event_code')
                            ->label('Kode Event (URL Slug)')
                            ->placeholder('Contoh: WED-KINAN')
                            ->default(fn () => 'EVT-' . strtoupper(\Illuminate\Support\Str::random(5)))
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(50),

                        Forms\Components\TextInput::make('location')
                            ->label('Lokasi')
                            ->placeholder('Contoh: Hotel Santika Malang')
                            ->maxLength(255),

                        Forms\Components\DatePicker::make('date')
                            ->label('Tanggal Event')
                            ->required()
                            ->default(now()),

                        Forms\Components\Select::make('status')
                            ->label('Status Antrean')
                            ->options([
                                'DRAFT' => 'Draft (Belum Dibuka)',
                                'OPEN' => 'Open (Antrean Dibuka)',
                                'PAUSED' => 'Paused (Antrean Disedot/Jeda)',
                                'CLOSED' => 'Closed (Antrean Ditutup)',
                            ])
                            ->default('DRAFT')
                            ->required(),
                    ])->columns(2)
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('event_code')
                    ->label('Kode Event')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->color('primary')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Event')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('location')
                    ->label('Lokasi')
                    ->searchable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('date')
                    ->label('Tanggal')
                    ->date('d F Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('queue_entries_count')
                    ->counts('queueEntries')
                    ->label('Total Antrean')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'DRAFT' => 'gray',
                        'OPEN' => 'success',
                        'PAUSED' => 'warning',
                        'CLOSED' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\Action::make('operator_dashboard')
                    ->label('Operator')
                    ->icon('heroicon-o-users')
                    ->color('success')
                    ->url(fn (Event $record): string => EventResource::getUrl('operator', ['record' => $record])),

                Tables\Actions\Action::make('view_qr')
                    ->label('QR Code')
                    ->icon('heroicon-o-qr-code')
                    ->color('info')
                    ->modalHeading(fn (Event $record) => 'QR Code & Poster Antrean: ' . $record->name)
                    ->modalWidth('lg')
                    ->modalContent(fn (Event $record) => view('filament.resources.event-resource.actions.qr-modal', ['record' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),

                Tables\Actions\EditAction::make()
                    ->label('Ubah'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Hapus Terpilih'),
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
            'index' => Pages\ListEvents::route('/'),
            'create' => Pages\CreateEvent::route('/create'),
            'edit' => Pages\EditEvent::route('/{record}/edit'),
            'operator' => Pages\OperatorDashboard::route('/{record}/operator'),
        ];
    }
}
