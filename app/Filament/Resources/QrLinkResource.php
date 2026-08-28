<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QrLinkResource\Pages;
use App\Filament\Resources\QrLinkResource\RelationManagers;
use App\Models\QrLink;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class QrLinkResource extends Resource
{
    protected static ?string $model = QrLink::class;

    protected static ?string $navigationGroup = 'CMS';

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationLabel = 'Link to QR';

    protected static ?string $pluralModelLabel = 'Link to QR';

    protected static ?string $modelLabel = 'Link to QR';

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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informasi Link')
                    ->description('Masukkan URL asli dan tentukan slug untuk link pendek Anda.')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Nama Link / Event')
                            ->placeholder('Contoh: Wedding Budi & Rina - GDrive')
                            ->required()
                            ->maxLength(255),
                        
                        Forms\Components\TextInput::make('slug')
                            ->label('Short URL Slug')
                            ->placeholder('Contoh: budi-rina (kosongkan untuk acak)')
                            ->helperText('Hasil akhir: domain.com/qr/slug-anda')
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Forms\Components\TextInput::make('original_url')
                            ->label('Destination URL (URL Asli)')
                            ->placeholder('https://drive.google.com/drive/folders/...')
                            ->required()
                            ->url()
                            ->columnSpanFull(),
                    ])
                    ->columnSpan(8),

                Forms\Components\Section::make('QR Code & Preview')
                    ->description('Tampilan QR code dan short link.')
                    ->schema([
                        Forms\Components\Placeholder::make('qr_preview')
                            ->label('Scan QR Code')
                            ->content(function ($record) {
                                if (! $record) {
                                    return new \Illuminate\Support\HtmlString('<p class="text-sm text-gray-500">Simpan pengaturan terlebih dahulu untuk memunculkan QR Code.</p>');
                                }
                                $shortUrl = url('/qr/' . $record->slug);
                                $qrCodeImage = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" . urlencode($shortUrl);
                                return new \Illuminate\Support\HtmlString("
                                    <div class='flex flex-col items-center justify-center p-4 bg-white border border-gray-200 rounded-xl shadow-sm dark:bg-gray-800 dark:border-gray-700'>
                                        <img src='{$qrCodeImage}' alt='QR Code' class='w-48 h-48 border border-gray-100 rounded-lg p-2 bg-white' />
                                        <div class='mt-4 text-center flex flex-col items-center justify-center w-full'>
                                            <a href='{$shortUrl}' target='_blank' class='text-sm font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400 hover:underline break-all'>
                                                {$shortUrl}
                                            </a>
                                            <p class='mt-1 text-xs text-gray-500 dark:text-gray-400'>
                                                Arahkan kamera HP untuk menguji pengalihan.
                                            </p>
                                            <button type='button' onclick='window.downloadQrCode(\"{$qrCodeImage}\", \"{$record->slug}\")' class='mt-3 inline-flex items-center gap-x-1.5 rounded-lg bg-primary-600 px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:bg-primary-500 dark:hover:bg-primary-400 cursor-pointer'>
                                                <svg class='-ml-0.5 h-4 w-4' fill='none' viewBox='0 0 24 24' stroke-width='1.5' stroke='currentColor'>
                                                    <path stroke-linecap='round' stroke-linejoin='round' d='M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3' />
                                                </svg>
                                                Unduh QR Code
                                            </button>
                                        </div>
                                    </div>
                                    <script>
                                        if (!window.downloadQrCode) {
                                            window.downloadQrCode = function (url, filename) {
                                                fetch(url)
                                                    .then(response => response.blob())
                                                    .then(blob => {
                                                        const blobUrl = window.URL.createObjectURL(blob);
                                                        const link = document.createElement('a');
                                                        link.href = blobUrl;
                                                        link.download = 'qr-' + filename + '.png';
                                                        document.body.appendChild(link);
                                                        link.click();
                                                        document.body.removeChild(link);
                                                        window.URL.revokeObjectURL(blobUrl);
                                                    })
                                                    .catch(error => {
                                                        console.error('Error downloading QR code:', error);
                                                        window.open(url, '_blank');
                                                    });
                                            };
                                        }
                                    </script>
                                ");
                            }),
                    ])
                    ->columnSpan(4),
            ])->columns(12);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Nama Link / Event')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('slug')
                    ->label('Short Link')
                    ->formatStateUsing(fn ($state) => url('/qr/' . $state))
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Short link disalin!')
                    ->copyMessageDuration(1500)
                    ->color('primary')
                    ->weight('bold')
                    ->icon('heroicon-m-clipboard-document-list'),

                Tables\Columns\TextColumn::make('original_url')
                    ->label('Destination URL (Asli)')
                    ->limit(40)
                    ->searchable(),

                Tables\Columns\TextColumn::make('clicks')
                    ->label('Total Scan / Klik')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state > 100 => 'success',
                        $state > 20 => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Terakhir Diperbarui')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Ubah'),
                Tables\Actions\DeleteAction::make()
                    ->label('Hapus'),
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
            'index' => Pages\ListQrLinks::route('/'),
            'create' => Pages\CreateQrLink::route('/create'),
            'edit' => Pages\EditQrLink::route('/{record}/edit'),
        ];
    }
}
