<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BioSettingResource\Pages;
use App\Filament\Resources\BioSettingResource\RelationManagers;
use App\Models\BioSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BioSettingResource extends Resource
{
    protected static ?string $model = BioSetting::class;

    protected static ?string $navigationGroup = 'CMS';

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Pengaturan Bio';

    protected static ?string $pluralModelLabel = 'Pengaturan Bio';

    protected static ?string $modelLabel = 'Pengaturan Bio';

    public static function canCreate(): bool
    {
        return BioSetting::count() === 0;
    }

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
                Forms\Components\Section::make('Informasi Utama')
                    ->schema([
                        Forms\Components\FileUpload::make('logo')
                            ->image()
                            ->directory('bio')
                            ->imageEditor()
                            ->circleCropper()
                            ->maxSize(2048)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('title')
                            ->label('Judul Bio')
                            ->required()
                            ->maxLength(255)
                            ->default('photomate.id'),
                        Forms\Components\Textarea::make('description')
                            ->label('Deskripsi')
                            ->rows(3)
                            ->placeholder('Tulis deskripsi singkat untuk halaman bio Anda...'),
                    ])->columns(1),

                Forms\Components\Section::make('Tautan Media Sosial / Kontak')
                    ->schema([
                        Forms\Components\TextInput::make('whatsapp_url')
                            ->label('WhatsApp URL')
                            ->url()
                            ->placeholder('https://wa.me/62...'),
                        Forms\Components\TextInput::make('instagram_url')
                            ->label('Instagram URL')
                            ->url()
                            ->placeholder('https://www.instagram.com/...'),
                        Forms\Components\TextInput::make('tiktok_url')
                            ->label('TikTok URL')
                            ->url()
                            ->placeholder('https://www.tiktok.com/@...'),
                        Forms\Components\TextInput::make('website_url')
                            ->label('Website URL')
                            ->url()
                            ->placeholder('https://photomate.id'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo')
                    ->circular(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Deskripsi')
                    ->limit(50)
                    ->searchable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Terakhir Diperbarui')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
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
            'index' => Pages\ListBioSettings::route('/'),
            'create' => Pages\CreateBioSetting::route('/create'),
            'edit' => Pages\EditBioSetting::route('/{record}/edit'),
        ];
    }
}
