<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PricingSettingResource\Pages;
use App\Models\PricingSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PricingSettingResource extends Resource
{
    protected static ?string $model = PricingSetting::class;

    protected static ?string $slug = 'session-management/pricing-settings';

    protected static ?string $navigationGroup = 'Session Management';

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Aturan Harga';

    protected static ?string $modelLabel = 'Aturan Harga Sesi';

    protected static ?string $pluralModelLabel = 'Aturan Harga Sesi';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        /** @var \App\Models\Karyawan|null $user */
        $user = Auth::user();
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
                Forms\Components\Section::make('Konfigurasi Pricing Sesi')
                    ->description('Tentukan harga dasar sesi pertama dan harga untuk setiap tambahan sesi berikutnya.')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Aturan')
                            ->default('Standard Pricing')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Status Aktif')
                            ->default(true)
                            ->required(),

                        Forms\Components\TextInput::make('base_price')
                            ->label('Harga Sesi Pertama (Base Price)')
                            ->prefix('Rp')
                            ->numeric()
                            ->default(30000)
                            ->required()
                            ->helperText('Contoh: Rp 30.000 untuk 1 sesi pertama'),

                        Forms\Components\TextInput::make('additional_session_price')
                            ->label('Harga Tambahan Per Sesi (Additional Price)')
                            ->prefix('Rp')
                            ->numeric()
                            ->default(15000)
                            ->required()
                            ->helperText('Contoh: Rp 15.000 untuk setiap sesi berikutnya (sesi ke-2, ke-3, dst)'),

                        Forms\Components\DatePicker::make('effective_from')
                            ->label('Berlaku Mulai Tanggal')
                            ->nullable()
                            ->native(false),

                        Forms\Components\DatePicker::make('effective_until')
                            ->label('Berlaku Sampai Tanggal')
                            ->nullable()
                            ->native(false)
                            ->helperText('Kosongkan jika berlaku seterusnya'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Aturan')
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('base_price')
                    ->label('Sesi Pertama')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format($state, 0, ',', '.'))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('additional_session_price')
                    ->label('Tambahan / Sesi')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format($state, 0, ',', '.'))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('effective_from')
                    ->label('Mulai Berlaku')
                    ->date('d M Y')
                    ->placeholder('Kapanpun')
                    ->sortable(),

                Tables\Columns\TextColumn::make('effective_until')
                    ->label('Sampai Dengan')
                    ->date('d M Y')
                    ->placeholder('Seterusnya')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPricingSettings::route('/'),
            'create' => Pages\CreatePricingSetting::route('/create'),
            'edit' => Pages\EditPricingSetting::route('/{record}/edit'),
        ];
    }
}
