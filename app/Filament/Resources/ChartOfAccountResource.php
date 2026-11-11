<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChartOfAccountResource\Pages;
use App\Models\ChartOfAccount;
use App\Services\AccountingMasterDataService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ChartOfAccountResource extends Resource
{
    protected static ?string $model = ChartOfAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Chart of Accounts';

    protected static ?string $modelLabel = 'Akun';

    protected static ?string $pluralModelLabel = 'Chart of Accounts';

    protected static ?string $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'akuntansi-chart-of-accounts';

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_chart_of_account') ?? false);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('parent')
            ->withCount(['children', 'financialAccounts']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Informasi Akun')
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Kode Akun')
                        ->required()
                        ->maxLength(20)
                        ->unique(ignoreRecord: true)
                        ->validationMessages([
                            'required' => 'Kode akun wajib diisi.',
                            'unique' => 'Kode akun sudah digunakan.',
                        ]),

                    Forms\Components\TextInput::make('name')
                        ->label('Nama Akun')
                        ->required()
                        ->maxLength(150),

                    Forms\Components\Select::make('type')
                        ->label('Tipe Akun')
                        ->options(ChartOfAccount::typeOptions())
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            $set('normal_balance', ChartOfAccount::normalBalanceForType((string) $state));
                            $set('report_group', array_key_first(ChartOfAccount::reportGroupOptions($state)));
                        }),

                    Forms\Components\Select::make('normal_balance')
                        ->label('Normal Balance')
                        ->options(ChartOfAccount::NORMAL_BALANCE_LABELS)
                        ->default(ChartOfAccount::NORMAL_DEBIT)
                        ->disabled()
                        ->dehydrated()
                        ->required(),

                    Forms\Components\Select::make('report_group')
                        ->label('Report Group')
                        ->options(fn (Get $get): array => ChartOfAccount::reportGroupOptions($get('type')))
                        ->required(),

                    Forms\Components\Select::make('cash_flow_category')
                        ->label('Klasifikasi Arus Kas')
                        ->options(ChartOfAccount::CASH_FLOW_LABELS)
                        ->placeholder('Infer otomatis dari tipe/report group')
                        ->helperText('Kosongkan untuk infer otomatis. Aktifkan hanya jika akun ini khusus untuk satu aktivitas arus kas.'),

                    Forms\Components\Select::make('parent_id')
                        ->label('Parent Account')
                        ->options(fn (Get $get, ?ChartOfAccount $record): array => self::parentOptions($record, $get('type')))
                        ->searchable()
                        ->placeholder('Tanpa parent (akun utama)')
                        ->helperText('Parent harus memiliki tipe yang sama dan tidak boleh membentuk siklus.'),

                    Forms\Components\TextInput::make('sort_order')
                        ->label('Urutan Tampilan')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->default(0),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true)
                        ->helperText('Akun nonaktif tidak dapat dipakai untuk posting jurnal baru.'),

                    Forms\Components\Textarea::make('description')
                        ->label('Deskripsi')
                        ->rows(3)
                        ->maxLength(1000)
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
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Akun')
                    ->formatStateUsing(fn (ChartOfAccount $record): string => $record->indented_name)
                    ->description(fn (ChartOfAccount $record): ?string => $record->parent?->name)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipe')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ChartOfAccount::TYPE_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        ChartOfAccount::TYPE_ASSET => 'info',
                        ChartOfAccount::TYPE_LIABILITY => 'warning',
                        ChartOfAccount::TYPE_EQUITY => 'success',
                        ChartOfAccount::TYPE_REVENUE => 'primary',
                        ChartOfAccount::TYPE_COGS => 'danger',
                        ChartOfAccount::TYPE_EXPENSE => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('report_group')
                    ->label('Report Group')
                    ->formatStateUsing(fn (string $state): string => ChartOfAccount::REPORT_GROUP_LABELS[$state] ?? $state)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('normal_balance')
                    ->label('Normal Balance')
                    ->formatStateUsing(fn (string $state): string => ChartOfAccount::NORMAL_BALANCE_LABELS[$state] ?? $state)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('cash_flow_category')
                    ->label('Arus Kas')
                    ->formatStateUsing(fn (?string $state): string => ChartOfAccount::CASH_FLOW_LABELS[$state] ?? 'Infer otomatis')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('children_count')
                    ->label('Subakun')
                    ->badge()
                    ->color('gray')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('financial_accounts_count')
                    ->label('Akun Kas')
                    ->badge()
                    ->color('info')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipe Akun')
                    ->options(ChartOfAccount::typeOptions()),

                Tables\Filters\SelectFilter::make('report_group')
                    ->label('Report Group')
                    ->options(ChartOfAccount::REPORT_GROUP_LABELS),

                Tables\Filters\SelectFilter::make('cash_flow_category')
                    ->label('Klasifikasi Arus Kas')
                    ->options(ChartOfAccount::CASH_FLOW_LABELS),

                Tables\Filters\SelectFilter::make('parent_id')
                    ->label('Parent Account')
                    ->options(fn (): array => ChartOfAccount::query()
                        ->orderBy('code')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Edit')
                    ->modalHeading('Edit Akun')
                    ->modalSubmitActionLabel('Simpan Perubahan')
                    ->successNotificationTitle('Akun berhasil diperbarui.')
                    ->using(function (ChartOfAccount $record, array $data): ChartOfAccount {
                        $reason = $data['change_reason'] ?? null;
                        unset($data['change_reason']);

                        return app(AccountingMasterDataService::class)->updateAccount(
                            $record,
                            $data,
                            Auth::user(),
                            $reason
                        );
                    }),

                Tables\Actions\Action::make('deactivate')
                    ->label('Nonaktifkan')
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (ChartOfAccount $record): bool => $record->is_active
                        && ! $record->isDeletable()
                        && (Auth::user()?->can('update', $record) ?? false))
                    ->action(function (ChartOfAccount $record): void {
                        app(AccountingMasterDataService::class)->deactivateAccount(
                            $record,
                            Auth::user(),
                            'Deaktivasi COA melalui Filament'
                        );

                        Notification::make()
                            ->title('Akun berhasil dinonaktifkan.')
                            ->body('Akun tetap tersimpan agar histori jurnal dan mapping tidak rusak.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->label('Hapus')
                    ->modalHeading('Hapus akun ini?')
                    ->modalSubmitActionLabel('Hapus')
                    ->visible(fn (ChartOfAccount $record): bool => $record->isDeletable()
                        && (Auth::user()?->can('delete', $record) ?? false))
                    ->successNotificationTitle('Akun berhasil dihapus.')
                    ->using(fn (ChartOfAccount $record): bool => app(AccountingMasterDataService::class)->deleteAccount(
                        $record,
                        Auth::user(),
                        'Penghapusan COA melalui Filament'
                    )),
            ])
            ->emptyStateHeading('Belum ada akun')
            ->emptyStateDescription('Tambahkan akun COA Photomate untuk fondasi jurnal akuntansi.')
            ->emptyStateIcon('heroicon-o-book-open');
    }

    /**
     * @return array<int, string>
     */
    public static function parentOptions(?ChartOfAccount $record = null, ?string $type = null): array
    {
        return ChartOfAccount::query()
            ->orderBy('code')
            ->get()
            ->when(filled($type), fn ($accounts) => $accounts->where('type', $type))
            ->reject(function (ChartOfAccount $account) use ($record): bool {
                if (! $record) {
                    return false;
                }

                return (int) $account->getKey() === (int) $record->getKey()
                    || $account->isDescendantOf($record);
            })
            ->mapWithKeys(fn (ChartOfAccount $account): array => [
                $account->getKey() => $account->code.' - '.$account->name,
            ])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChartOfAccounts::route('/'),
        ];
    }
}
