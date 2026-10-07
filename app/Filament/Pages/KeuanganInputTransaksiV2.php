<?php

namespace App\Filament\Pages;

use App\Filament\Resources\FinancialTransactionResource;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialParty;
use App\Models\FinancialTransaction;
use App\Utils\MonthHelper;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class KeuanganInputTransaksiV2 extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithFormActions;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationLabel = 'Input Transaksi V2';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Input Transaksi V2';

    protected static ?string $slug = 'keuangan-input-transaksi-v2';

    protected static string $view = 'filament.pages.keuangan-input-transaksi-v2';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin() || ($user?->can('create_keuangan_transaction') ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->form->fill($this->defaultFormState());
    }

    public function getSubheading(): ?string
    {
        return 'Catat transaksi keuangan dengan cepat dan sederhana.';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Transaksi Baru')
                    ->description('Akun dan periode tetap tersimpan setelah transaksi berhasil disimpan agar input berikutnya lebih cepat.')
                    ->schema([
                        Forms\Components\ToggleButtons::make('account_id')
                            ->label('Akun')
                            ->options(fn (): array => FinancialAccount::query()
                                ->active()
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->inline()
                            ->required()
                            ->exists('financial_accounts', 'id')
                            ->columnSpanFull()
                            ->validationMessages([
                                'required' => 'Akun wajib dipilih.',
                                'exists' => 'Akun tidak valid.',
                            ]),

                        Forms\Components\Select::make('period')
                            ->label('Periode')
                            ->options(fn (): array => self::periodOptions())
                            ->default(fn (): string => now()->format('Y-m'))
                            ->selectablePlaceholder(false)
                            ->searchable()
                            ->required()
                            ->validationMessages(['required' => 'Periode wajib dipilih.']),

                        Forms\Components\DatePicker::make('transaction_date')
                            ->label('Tanggal Transaksi')
                            ->default(now())
                            ->required()
                            ->validationMessages(['required' => 'Tanggal transaksi wajib diisi.']),

                        Forms\Components\Select::make('category_id')
                            ->label('Kategori')
                            ->options(fn (Get $get): array => FinancialCategory::query()
                                ->ofType($get('transaction_type') ?: FinancialTransaction::TYPE_INCOME)
                                ->active()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required()
                            ->exists('financial_categories', 'id')
                            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $valid = FinancialCategory::query()
                                    ->whereKey($value)
                                    ->ofType($get('transaction_type') ?: FinancialTransaction::TYPE_INCOME)
                                    ->exists();

                                if (! $valid) {
                                    $fail('Kategori tidak sesuai dengan jenis transaksi.');
                                }
                            })
                            ->validationMessages([
                                'required' => 'Kategori wajib dipilih.',
                                'exists' => 'Kategori tidak valid.',
                            ]),

                        Forms\Components\ToggleButtons::make('transaction_type')
                            ->label('Jenis Transaksi')
                            ->options(FinancialTransaction::TYPE_LABELS)
                            ->colors([
                                FinancialTransaction::TYPE_INCOME => 'success',
                                FinancialTransaction::TYPE_EXPENSE => 'danger',
                            ])
                            ->icons([
                                FinancialTransaction::TYPE_INCOME => 'heroicon-o-arrow-down-circle',
                                FinancialTransaction::TYPE_EXPENSE => 'heroicon-o-arrow-up-circle',
                            ])
                            ->inline()
                            ->default(FinancialTransaction::TYPE_INCOME)
                            ->required()
                            ->in(array_keys(FinancialTransaction::TYPE_LABELS))
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('category_id', null))
                            ->validationMessages(['in' => 'Jenis transaksi tidak valid.']),

                        FinancialTransactionResource::amountInput(),

                        Forms\Components\Select::make('party_id')
                            ->label(fn (Get $get): string => $get('transaction_type') === FinancialTransaction::TYPE_INCOME
                                ? 'Diterima Oleh'
                                : 'Dibayarkan Oleh')
                            ->options(fn (): array => FinancialParty::query()
                                ->active()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required()
                            ->exists('financial_parties', 'id')
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nama Pihak')
                                    ->required()
                                    ->maxLength(100)
                                    ->validationMessages(['required' => 'Nama pihak wajib diisi.']),

                                Forms\Components\Select::make('type')
                                    ->label('Tipe')
                                    ->options(FinancialParty::TYPE_LABELS)
                                    ->default(FinancialParty::TYPE_OTHER)
                                    ->selectablePlaceholder(false)
                                    ->required(),
                            ])
                            ->createOptionModalHeading('Tambah Pihak')
                            ->createOptionUsing(fn (array $data): int => FinancialParty::create($data)->getKey())
                            ->validationMessages(['required' => 'Pihak pembayar/penerima wajib dipilih.']),

                        Forms\Components\ToggleButtons::make('payment_status')
                            ->label('Status Pembayaran')
                            ->options(FinancialTransaction::PAYMENT_STATUS_LABELS)
                            ->colors([
                                FinancialTransaction::PAYMENT_STATUS_LUNAS => 'success',
                                FinancialTransaction::PAYMENT_STATUS_UTANG => 'warning',
                            ])
                            ->icons([
                                FinancialTransaction::PAYMENT_STATUS_LUNAS => 'heroicon-o-check-circle',
                                FinancialTransaction::PAYMENT_STATUS_UTANG => 'heroicon-o-clock',
                            ])
                            ->inline()
                            ->default(FinancialTransaction::PAYMENT_STATUS_LUNAS)
                            ->required()
                            ->in(array_keys(FinancialTransaction::PAYMENT_STATUS_LABELS))
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state === FinancialTransaction::PAYMENT_STATUS_UTANG) {
                                    return;
                                }

                                $set('due_date', null);
                                $set('counterparty', null);
                            })
                            ->columnSpanFull(),

                        Forms\Components\DatePicker::make('due_date')
                            ->label('Jatuh Tempo')
                            ->visible(fn (Get $get): bool => $get('payment_status') === FinancialTransaction::PAYMENT_STATUS_UTANG)
                            ->required(fn (Get $get): bool => $get('payment_status') === FinancialTransaction::PAYMENT_STATUS_UTANG)
                            ->validationMessages(['required' => 'Jatuh tempo wajib diisi untuk transaksi utang.']),

                        Forms\Components\TextInput::make('counterparty')
                            ->label('Pihak / Kepada')
                            ->placeholder('Contoh: Supplier ABC')
                            ->maxLength(150)
                            ->visible(fn (Get $get): bool => $get('payment_status') === FinancialTransaction::PAYMENT_STATUS_UTANG)
                            ->required(fn (Get $get): bool => $get('payment_status') === FinancialTransaction::PAYMENT_STATUS_UTANG)
                            ->validationMessages(['required' => 'Pihak/kepada wajib diisi untuk transaksi utang.']),

                        Forms\Components\Textarea::make('description')
                            ->label('Deskripsi')
                            ->maxLength(500)
                            ->rows(2)
                            ->placeholder('Contoh: Pembelian tinta printer Janus')
                            ->helperText('Opsional.')
                            ->columnSpanFull(),

                        Forms\Components\FileUpload::make('evidence_path')
                            ->label('Bukti Transaksi')
                            ->disk('public')
                            ->directory('keuangan/evidence')
                            ->visibility('public')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->maxSize(5120)
                            ->helperText('Opsional. Format JPG, PNG, WEBP, atau PDF (maksimal 5MB).')
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ]),
            ])
            ->statePath('data');
    }

    public function simpan(): void
    {
        Gate::authorize('create', FinancialTransaction::class);

        $data = $this->form->getState();

        $period = $data['period'] ?? now()->format('Y-m');
        unset($data['period']);

        [$year, $month] = array_pad(explode('-', (string) $period), 2, null);

        $data['period_year'] = (int) $year;
        $data['period_month'] = (int) $month;

        try {
            $transaction = FinancialTransaction::create($data);
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Transaksi gagal disimpan. Silakan coba lagi.')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Transaksi berhasil disimpan.')
            ->body($transaction->type_label . ' ' . $transaction->formatted_amount . ' · ' . ($transaction->category?->name ?? '-'))
            ->success()
            ->send();

        // Akun, periode, dan jenis transaksi dipertahankan untuk input berikutnya.
        $this->form->fill($this->defaultFormState([
            'account_id' => $transaction->account_id,
            'period' => $period,
            'transaction_type' => $transaction->transaction_type,
        ]));
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('simpan')
                ->label('Simpan Transaksi')
                ->icon('heroicon-o-check')
                ->submit('simpan'),
        ];
    }

    /**
     * @param  array<string, mixed>  $keep  Nilai yang dipertahankan dari input sebelumnya.
     * @return array<string, mixed>
     */
    protected function defaultFormState(array $keep = []): array
    {
        return [
            'account_id' => null,
            'period' => now()->format('Y-m'),
            'transaction_date' => now(),
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'category_id' => null,
            'amount' => null,
            'party_id' => null,
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'due_date' => null,
            'counterparty' => null,
            'description' => null,
            'evidence_path' => null,
            ...$keep,
        ];
    }

    /**
     * Pilihan periode bulan-tahun, bulan berjalan lebih dahulu.
     *
     * @return array<string, string>
     */
    protected static function periodOptions(int $backMonths = 24): array
    {
        $options = [];
        $cursor = Carbon::now()->startOfMonth();

        for ($index = 0; $index <= $backMonths; $index++) {
            $options[$cursor->format('Y-m')] = MonthHelper::formatPeriod((int) $cursor->month, (int) $cursor->year);

            $cursor = $cursor->subMonthNoOverflow();
        }

        return $options;
    }
}
