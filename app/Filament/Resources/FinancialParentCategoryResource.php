<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FinancialParentCategoryResource\Pages;
use App\Models\FinancialParentCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class FinancialParentCategoryResource extends Resource
{
    protected static ?string $model = FinancialParentCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationLabel = 'Kategori Utama';

    protected static ?string $modelLabel = 'Kategori Utama';

    protected static ?string $pluralModelLabel = 'Kategori Utama';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $slug = 'keuangan-kategori-utama';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_keuangan_category') ?? false);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Kategori Utama')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Kategori Utama')
                        ->required()
                        ->maxLength(100)
                        ->unique(ignoreRecord: true)
                        ->validationMessages([
                            'required' => 'Nama kategori utama wajib diisi.',
                            'unique' => 'Nama kategori utama ini sudah ada.',
                        ]),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->helperText('Kategori utama nonaktif tidak muncul saat input transaksi baru.')
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Kategori Utama')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('subcategories_count')
                    ->label('Sub Kategori')
                    ->counts('subcategories')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Edit')
                    ->modalHeading('Edit Kategori Utama')
                    ->modalSubmitActionLabel('Simpan Perubahan')
                    ->successNotificationTitle('Kategori utama berhasil diperbarui.'),

                Tables\Actions\DeleteAction::make()
                    ->label('Hapus')
                    ->modalHeading('Hapus kategori utama ini?')
                    ->modalSubmitActionLabel('Hapus')
                    ->action(function (FinancialParentCategory $record): void {
                        if ($record->subcategories()->exists() || $record->transactions()->withTrashed()->exists()) {
                            Notification::make()
                                ->title('Kategori utama masih dipakai')
                                ->body('Pindahkan atau hapus sub kategori/transaksi terkait terlebih dahulu sebelum menghapus kategori utama ini.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $record->delete();

                        Notification::make()
                            ->title('Kategori utama berhasil dihapus.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Belum ada kategori utama')
            ->emptyStateDescription('Tambahkan kategori utama seperti Box, Transportasi, atau Gaji.')
            ->emptyStateIcon('heroicon-o-folder');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFinancialParentCategories::route('/'),
        ];
    }
}
