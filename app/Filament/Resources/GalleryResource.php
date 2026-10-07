<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GalleryResource\Pages;
use App\Filament\Resources\GalleryResource\RelationManagers;
use App\Models\Gallery;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class GalleryResource extends Resource
{
    protected static ?string $model = Gallery::class;

    protected static ?string $navigationGroup = "CMS";

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

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
                Forms\Components\TextInput::make('title')
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\Select::make('category')
                    ->label('Kategori')
                    ->options(fn (): array => Gallery::categoryOptions())
                    ->searchable()
                    ->required()
                    ->default('Event')
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Kategori')
                            ->required()
                            ->maxLength(255)
                            ->validationMessages(['required' => 'Nama kategori wajib diisi.']),
                    ])
                    ->createOptionModalHeading('Tambah Kategori Gallery')
                    ->createOptionAction(fn (Forms\Components\Actions\Action $action): Forms\Components\Actions\Action => $action
                        ->modalSubmitActionLabel('Simpan'))
                    ->createOptionUsing(fn (array $data): string => Gallery::normalizeCategory((string) $data['name']))
                    ->validationMessages(['required' => 'Kategori wajib dipilih.']),
                Forms\Components\FileUpload::make('image')
                    ->image()
                    ->multiple(fn (string $context) => $context === 'create')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable(),
                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->sortable()
                    ->searchable(),
                Tables\Columns\ImageColumn::make('image'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListGalleries::route('/'),
            'create' => Pages\CreateGallery::route('/create'),
            'edit' => Pages\EditGallery::route('/{record}/edit'),
        ];
    }
}
