<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BioPhotostripResource\Pages;
use App\Filament\Resources\BioPhotostripResource\RelationManagers;
use App\Models\BioPhotostrip;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BioPhotostripResource extends Resource
{
    protected static ?string $model = BioPhotostrip::class;

    protected static ?string $navigationGroup = 'CMS';

    protected static ?string $navigationIcon = 'heroicon-o-photo';

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

    protected static ?string $navigationLabel = 'Bio Photostrips';

    protected static ?string $pluralModelLabel = 'Bio Photostrips';

    protected static ?string $modelLabel = 'Bio Photostrip';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Detail Photostrip')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Judul / Event')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('link')
                            ->label('Tautan (Link)')
                            ->url()
                            ->required()
                            ->maxLength(500),
                        Forms\Components\FileUpload::make('image')
                            ->label('Gambar Photostrip')
                            ->image()
                            ->directory('bio-photostrips')
                            ->imageEditor()
                            ->required()
                            ->maxSize(2048),
                        Forms\Components\TextInput::make('order')
                            ->label('Urutan')
                            ->integer()
                            ->default(0)
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true)
                            ->required(),
                    ])->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label('Gambar'),
                Tables\Columns\TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('link')
                    ->label('Tautan')
                    ->searchable()
                    ->limit(40),
                Tables\Columns\TextColumn::make('order')
                    ->label('Urutan')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('order', 'asc')
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
            'index' => Pages\ListBioPhotostrips::route('/'),
            'create' => Pages\CreateBioPhotostrip::route('/create'),
            'edit' => Pages\EditBioPhotostrip::route('/{record}/edit'),
        ];
    }
}
