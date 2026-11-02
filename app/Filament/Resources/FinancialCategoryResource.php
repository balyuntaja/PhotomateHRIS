<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FinancialCategoryResource\Pages;
use App\Models\FinancialCategory;
use App\Models\FinancialParentCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class FinancialCategoryResource extends Resource
{
    protected static ?string $model = FinancialCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Kategori Keuangan';

    protected static ?string $modelLabel = 'Sub Kategori';

    protected static ?string $pluralModelLabel = 'Sub Kategori';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 6;

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
            Forms\Components\Section::make('Data Sub Kategori')
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

                    Forms\Components\Select::make('parent_category_id')
                        ->label('Kategori Utama')
                        ->options(function (Get $get): array {
                            $selectedParentId = $get('parent_category_id');

                            return FinancialParentCategory::query()
                                ->where(function (Builder $query) use ($selectedParentId): void {
                                    $query->where('is_active', true);

                                    if (filled($selectedParentId)) {
                                        $query->orWhere('id', $selectedParentId);
                                    }
                                })
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all();
                        })
                        ->searchable()
                        ->required()
                        ->exists('financial_parent_categories', 'id')
                        ->validationMessages([
                            'required' => 'Kategori utama wajib dipilih.',
                            'exists' => 'Kategori utama tidak valid.',
                        ]),

                    Forms\Components\TextInput::make('name')
                        ->label('Nama Sub Kategori')
                        ->required()
                        ->maxLength(100)
                        ->rules([
                            fn (Get $get, ?FinancialCategory $record) => Rule::unique('financial_categories', 'name')
                                ->where(fn ($query) => $query->where('transaction_type', $get('transaction_type')))
                                ->ignore($record?->getKey()),
                        ])
                        ->validationMessages([
                            'required' => 'Nama sub kategori wajib diisi.',
                            'unique' => 'Nama sub kategori ini sudah ada untuk jenis transaksi tersebut.',
                        ]),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->helperText('Sub kategori nonaktif tidak muncul saat input transaksi baru.')
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('parentCategory.name')
                    ->label('Kategori Utama')
                    ->placeholder('Belum dipetakan')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Sub Kategori')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('transaction_type')
                    ->label('Jenis Transaksi')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => FinancialCategory::typeOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => $state === FinancialCategory::TYPE_INCOME ? 'success' : 'danger'),

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
                Tables\Filters\SelectFilter::make('parent_category_id')
                    ->label('Kategori Utama')
                    ->options(fn (): array => FinancialParentCategory::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),

                Tables\Filters\SelectFilter::make('transaction_type')
                    ->label('Jenis Transaksi')
                    ->options(FinancialCategory::typeOptions()),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif'),

                Tables\Filters\Filter::make('tanpa_kategori_utama')
                    ->label('Belum ada Kategori Utama')
                    ->query(fn (Builder $query): Builder => $query->whereNull('parent_category_id'))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Edit')
                    ->modalHeading('Edit Sub Kategori')
                    ->modalSubmitActionLabel('Simpan Perubahan')
                    ->successNotificationTitle('Sub kategori berhasil diperbarui.'),

                Tables\Actions\DeleteAction::make()
                    ->label('Hapus')
                    ->modalHeading('Hapus sub kategori ini?')
                    ->modalSubmitActionLabel('Hapus')
                    ->action(function (FinancialCategory $record): void {
                        if ($record->isUsed()) {
                            $record->update(['is_active' => false]);

                            Notification::make()
                                ->title('Sub kategori sudah dipakai transaksi')
                                ->body('Sub kategori tidak dihapus, tetapi dinonaktifkan agar transaksi lama tetap valid.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $record->delete();

                        Notification::make()
                            ->title('Sub kategori berhasil dihapus.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Belum ada sub kategori')
            ->emptyStateDescription('Tambahkan sub kategori pemasukan atau pengeluaran Photomate di sini.')
            ->emptyStateIcon('heroicon-o-tag');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFinancialCategories::route('/'),
        ];
    }
}
