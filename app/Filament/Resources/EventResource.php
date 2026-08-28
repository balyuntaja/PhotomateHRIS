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
                    ->modalHeading('QR Code Pendaftaran Antrean')
                    ->modalContent(function (Event $record) {
                        $url = url('/queue/' . $record->event_code);
                        $qrCodeImage = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($url);
                        return new \Illuminate\Support\HtmlString("
                            <div class='flex flex-col items-center justify-center p-6 text-center space-y-4'>
                                <img src='{$qrCodeImage}' alt='QR Code' class='w-64 h-64 border p-2 bg-white rounded-lg shadow-sm' />
                                <div class='space-y-1 w-full'>
                                    <a href='{$url}' target='_blank' class='text-sm font-bold text-primary-600 hover:underline break-all block'>
                                        {$url}
                                    </a>
                                    <p class='text-xs text-gray-500'>
                                        Tempel QR code ini di venue agar customer dapat memindai dan bergabung ke antrean.
                                    </p>
                                </div>
                                <button type='button' onclick='window.downloadQrCode(\"{$qrCodeImage}\", \"{$record->event_code}\")' class='inline-flex items-center gap-x-1.5 rounded-lg bg-primary-600 px-4 py-2.5 text-xs font-semibold text-white shadow-sm hover:bg-primary-500 cursor-pointer'>
                                    <svg class='-ml-0.5 h-4 w-4' fill='none' viewBox='0 0 24 24' stroke-width='1.5' stroke='currentColor'>
                                        <path stroke-linecap='round' stroke-linejoin='round' d='M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3' />
                                    </svg>
                                    Unduh QR Code
                                </button>
                                <script>
                                    if (!window.downloadQrCode) {
                                        window.downloadQrCode = function (url, filename) {
                                            fetch(url)
                                                .then(response => response.blob())
                                                .then(blob => {
                                                    const blobUrl = window.URL.createObjectURL(blob);
                                                    const link = document.createElement('a');
                                                    link.href = blobUrl;
                                                    link.download = 'qr-queue-' + filename + '.png';
                                                    document.body.appendChild(link);
                                                    link.click();
                                                    document.body.removeChild(link);
                                                    window.URL.revokeObjectURL(blobUrl);
                                                })
                                                .catch(() => window.open(url, '_blank'));
                                        };
                                    }
                                </script>
                            </div>
                        ");
                    })
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
