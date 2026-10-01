<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FinancialCategoryResource\Pages;
use App\Models\FinancialCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class FinancialCategoryResource extends Resource
{
    protected static ?string $model = FinancialCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Kategori';

    protected static ?string $modelLabel = 'Kategori';

    protected static ?string $pluralModelLabel = 'Kategori';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'keuangan-kategori';

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_keuangan_category') ?? false);
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_keuangan_category') ?? false);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Kategori')
                ->schema([
                    Forms\Components\ToggleButtons::make('transaction_type')
                        ->label('Jenis Transaksi')
                        ->options(FinancialCategory::typeOptions())
                        ->colors([
                            FinancialCategory::TYPE_INCOME => 'success',
                            FinancialCategory::TYPE_EXPENSE => 'danger',
                        ])
                        ->inline()
                        ->default(FinancialCategory::TYPE_EXPENSE)
                        ->required()
                        ->live(),

                    Forms\Components\TextInput::make('name')
                        ->label('Nama Kategori')
                        ->required()
                        ->maxLength(100)
                        ->rules([
                            fn (Get $get, ?FinancialCategory $record) => Rule::unique('financial_categories', 'name')
                                ->where(fn ($query) => $query->where('transaction_type', $get('transaction_type')))
                                ->ignore($record?->getKey()),
                        ])
                        ->validationMessages([
                            'required' => 'Nama kategori wajib diisi.',
                            'unique' => 'Nama kategori ini sudah ada untuk jenis transaksi tersebut.',
                        ]),

                    Forms\Components\TextInput::make('group_name')
                        ->label('Grup')
                        ->maxLength(100)
                        ->placeholder('Contoh: Operasional, SDM, Marketing')
                        ->helperText('Opsional. Dipakai untuk mengelompokkan kategori pengeluaran di laporan.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->helperText('Kategori nonaktif tidak muncul saat input transaksi baru.')
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
                    ->label('Kategori')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('transaction_type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => FinancialCategory::typeOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => $state === FinancialCategory::TYPE_INCOME ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('group_name')
                    ->label('Grup')
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('transactions_count')
                    ->label('Dipakai')
                    ->counts('transactions')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('transaction_type')
                    ->label('Jenis')
                    ->options(FinancialCategory::typeOptions()),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Edit')
                    ->modalHeading('Edit Kategori')
                    ->modalSubmitActionLabel('Simpan Perubahan')
                    ->successNotificationTitle('Kategori berhasil diperbarui.'),

                Tables\Actions\DeleteAction::make()
                    ->label('Hapus')
                    ->modalHeading('Hapus kategori ini?')
                    ->modalSubmitActionLabel('Hapus')
                    ->action(function (FinancialCategory $record): void {
                        if ($record->isUsed()) {
                            $record->update(['is_active' => false]);

                            Notification::make()
                                ->title('Kategori sudah dipakai transaksi')
                                ->body('Kategori tidak dihapus, tetapi dinonaktifkan agar transaksi lama tetap valid.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $record->delete();

                        Notification::make()
                            ->title('Kategori berhasil dihapus.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Belum ada kategori')
            ->emptyStateDescription('Tambahkan kategori pemasukan atau pengeluaran Photomate di sini.')
            ->emptyStateIcon('heroicon-o-tag');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFinancialCategories::route('/'),
        ];
    }
}
