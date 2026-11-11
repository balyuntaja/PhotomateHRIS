<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryAccountMappingResource\Pages;
use App\Models\CategoryAccountMapping;
use App\Models\ChartOfAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialParentCategory;
use App\Services\AccountingMasterDataService;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class CategoryAccountMappingResource extends Resource
{
    protected static ?string $model = CategoryAccountMapping::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Mapping Kategori';

    protected static ?string $modelLabel = 'Mapping Kategori';

    protected static ?string $pluralModelLabel = 'Mapping Kategori';

    protected static ?string $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'akuntansi-category-mapping';

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_category_account_mapping') ?? false);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['financialCategory', 'financialParentCategory', 'debitAccount', 'creditAccount']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Target Mapping')
                ->schema([
                    Forms\Components\Select::make('financial_category_id')
                        ->label('Sub Kategori')
                        ->options(fn (Get $get): array => FinancialCategory::query()
                            ->where(function (Builder $query) use ($get): void {
                                $query->where('is_active', true);

                                if (filled($get('financial_category_id'))) {
                                    $query->orWhere('id', $get('financial_category_id'));
                                }
                            })
                            ->orderBy('transaction_type')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (FinancialCategory $category): array => [
                                $category->getKey() => $category->name.' ('.$category->type_label.')',
                            ])
                            ->all())
                        ->searchable()
                        ->live()
                        ->unique(ignoreRecord: true)
                        ->required(fn (Get $get): bool => blank($get('financial_parent_category_id')))
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            if (blank($state)) {
                                return;
                            }

                            $set('financial_parent_category_id', null);
                            $set('transaction_type', FinancialCategory::query()
                                ->whereKey($state)
                                ->value('transaction_type'));
                        }),

                    Forms\Components\Select::make('financial_parent_category_id')
                        ->label('Kategori Utama (fallback)')
                        ->options(fn (Get $get): array => FinancialParentCategory::query()
                            ->where(function (Builder $query) use ($get): void {
                                $query->where('is_active', true);

                                if (filled($get('financial_parent_category_id'))) {
                                    $query->orWhere('id', $get('financial_parent_category_id'));
                                }
                            })
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->live()
                        ->unique(ignoreRecord: true)
                        ->helperText('Dipakai sebagai fallback bila sub kategori belum punya mapping.')
                        ->required(fn (Get $get): bool => blank($get('financial_category_id')))
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            if (filled($state)) {
                                $set('financial_category_id', null);
                            }
                        }),

                    Forms\Components\ToggleButtons::make('transaction_type')
                        ->label('Tipe Transaksi')
                        ->options(FinancialCategory::typeOptions())
                        ->colors([
                            FinancialCategory::TYPE_INCOME => 'success',
                            FinancialCategory::TYPE_EXPENSE => 'danger',
                        ])
                        ->inline()
                        ->default(FinancialCategory::TYPE_EXPENSE)
                        ->required()
                        ->live()
                        ->disabled(fn (Get $get): bool => filled($get('financial_category_id')))
                        ->dehydrated()
                        ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $categoryId = $get('financial_category_id');

                            if (blank($categoryId)) {
                                return;
                            }

                            $categoryType = FinancialCategory::query()
                                ->whereKey($categoryId)
                                ->value('transaction_type');

                            if ($categoryType && $categoryType !== $value) {
                                $fail('Tipe transaksi harus sama dengan tipe sub kategori.');
                            }
                        }),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Mapping Aktif')
                        ->default(true)
                        ->helperText('Mapping nonaktif tidak dipakai sebagai fallback.'),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('change_reason')
                        ->label('Alasan Perubahan')
                        ->rows(2)
                        ->maxLength(500)
                        ->required()
                        ->helperText('Wajib diisi; disimpan pada audit trail master data.')
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Forms\Components\Section::make('Akun Debit dan Kredit')
                ->schema([
                    Forms\Components\Select::make('debit_account_id')
                        ->label('Akun Debit')
                        ->options(fn (Get $get): array => self::accountOptions($get('debit_account_id')))
                        ->searchable()
                        ->required(fn (Get $get): bool => $get('transaction_type') === FinancialCategory::TYPE_EXPENSE)
                        ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            self::validateAccountSelection(
                                $value,
                                (string) $get('transaction_type'),
                                'debit',
                                $fail
                            );
                        }),

                    Forms\Components\Select::make('credit_account_id')
                        ->label('Akun Kredit')
                        ->options(fn (Get $get): array => self::accountOptions($get('credit_account_id')))
                        ->searchable()
                        ->required(fn (Get $get): bool => $get('transaction_type') === FinancialCategory::TYPE_INCOME)
                        ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            self::validateAccountSelection(
                                $value,
                                (string) $get('transaction_type'),
                                'credit',
                                $fail
                            );
                        }),
                ])
                ->columns(2)
                ->description('Satu sisi boleh dikosongkan jika akan diselesaikan dari akun kas/bank transaksi pada fase integrasi.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('target_name')
                    ->label('Kategori')
                    ->description(fn (CategoryAccountMapping $record): string => $record->target_level)
                    ->wrap(),

                Tables\Columns\TextColumn::make('transaction_type')
                    ->label('Tipe')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => FinancialCategory::typeOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => $state === FinancialCategory::TYPE_INCOME ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('debit_label')
                    ->label('Akun Debit')
                    ->wrap()
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('credit_label')
                    ->label('Akun Kredit')
                    ->wrap()
                    ->placeholder('-'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->defaultSort('transaction_type')
            ->filters([
                Tables\Filters\SelectFilter::make('transaction_type')
                    ->label('Tipe')
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
                    ->modalHeading('Edit Mapping Kategori')
                    ->modalSubmitActionLabel('Simpan Perubahan')
                    ->successNotificationTitle('Mapping berhasil diperbarui.')
                    ->using(function (CategoryAccountMapping $record, array $data): CategoryAccountMapping {
                        $reason = $data['change_reason'] ?? null;
                        unset($data['change_reason']);

                        return app(AccountingMasterDataService::class)->updateMapping(
                            $record,
                            $data,
                            Auth::user(),
                            $reason
                        );
                    }),

                Tables\Actions\DeleteAction::make()
                    ->label('Hapus')
                    ->modalHeading('Hapus mapping ini?')
                    ->modalSubmitActionLabel('Hapus')
                    ->successNotificationTitle('Mapping berhasil dihapus.')
                    ->using(fn (CategoryAccountMapping $record): bool => app(AccountingMasterDataService::class)->deleteMapping(
                        $record,
                        Auth::user(),
                        'Penghapusan mapping melalui Filament'
                    )),
            ])
            ->emptyStateHeading('Belum ada mapping')
            ->emptyStateDescription('Petakan kategori transaksi ke akun COA agar jurnal dapat dibentuk.')
            ->emptyStateIcon('heroicon-o-arrows-right-left');
    }

    /**
     * @return array<int, string>
     */
    public static function accountOptions(mixed $selectedId = null): array
    {
        return ChartOfAccount::query()
            ->where(function (Builder $query) use ($selectedId): void {
                $query->where('is_active', true);

                if (filled($selectedId)) {
                    $query->orWhere('id', $selectedId);
                }
            })
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (ChartOfAccount $account): array => [
                $account->getKey() => $account->code.' - '.$account->name.' ('.$account->type_label.')',
            ])
            ->all();
    }

    private static function validateAccountSelection(
        mixed $accountId,
        string $transactionType,
        string $side,
        Closure $fail
    ): void {
        if (blank($accountId)) {
            return;
        }

        $account = ChartOfAccount::query()->find($accountId);

        if (! $account || ! $account->is_active) {
            $fail('Akun harus aktif dan valid.');

            return;
        }

        if ($transactionType === FinancialCategory::TYPE_INCOME) {
            if ($side === 'credit' && ! in_array($account->type, [
                ChartOfAccount::TYPE_REVENUE,
                ChartOfAccount::TYPE_ASSET,
                ChartOfAccount::TYPE_LIABILITY,
                ChartOfAccount::TYPE_EQUITY,
            ], true)) {
                $fail('Akun kredit mapping income harus bertipe Pendapatan, Aset, Liabilitas, atau Ekuitas.');
            }

            if ($side === 'debit' && $account->type !== ChartOfAccount::TYPE_ASSET) {
                $fail('Akun debit mapping income harus bertipe Aset.');
            }

            return;
        }

        if ($side === 'debit' && ! in_array($account->type, [
            ChartOfAccount::TYPE_EXPENSE,
            ChartOfAccount::TYPE_COGS,
            ChartOfAccount::TYPE_LIABILITY,
            ChartOfAccount::TYPE_ASSET,
            ChartOfAccount::TYPE_EQUITY,
        ], true)) {
            $fail('Akun debit mapping expense harus bertipe Beban, HPP, Liabilitas, Aset, atau Ekuitas.');
        }

        if ($side === 'credit' && ! in_array($account->type, [ChartOfAccount::TYPE_ASSET, ChartOfAccount::TYPE_LIABILITY], true)) {
            $fail('Akun kredit mapping expense harus bertipe Aset atau Liabilitas.');
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategoryAccountMappings::route('/'),
        ];
    }
}
