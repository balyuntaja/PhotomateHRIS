<?php

namespace App\Filament\Pages;

use App\Filament\Resources\FinancialTransactionResource;
use App\Models\FinancialCategory;
use App\Models\FinancialPaymentMethod;
use App\Models\FinancialTransaction;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class KeuanganInputTransaksi extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithFormActions;

    protected static ?string $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $navigationLabel = 'Input Transaksi';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Input Transaksi';

    protected static ?string $slug = 'keuangan-input-transaksi';

    protected static string $view = 'filament.pages.keuangan-input-transaksi';

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

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Detail Transaksi')
                    ->description('Catat pemasukan atau pengeluaran baru. Transaksi langsung tersimpan dan form dikosongkan untuk input berikutnya.')
                    ->schema([
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
                            ->live()
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('category_id', null))
                            ->columnSpanFull(),

                        FinancialTransactionResource::amountInput(),

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
                            ->validationMessages(['required' => 'Kategori wajib dipilih.']),

                        Forms\Components\DatePicker::make('transaction_date')
                            ->label('Tanggal Transaksi')
                            ->default(now())
                            ->required()
                            ->validationMessages(['required' => 'Tanggal transaksi wajib diisi.']),

                        Forms\Components\Select::make('payment_method_id')
                            ->label('Metode Pembayaran')
                            ->options(fn (): array => FinancialPaymentMethod::query()
                                ->active()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->default(fn (): ?int => FinancialPaymentMethod::query()->where('name', 'Cash')->value('id'))
                            ->searchable()
                            ->required()
                            ->validationMessages(['required' => 'Metode pembayaran wajib dipilih.']),

                        Forms\Components\Textarea::make('description')
                            ->label('Deskripsi')
                            ->maxLength(500)
                            ->rows(2)
                            ->placeholder('Opsional, contoh: Pembelian tinta printer Janus')
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
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function simpan(): void
    {
        $data = $this->form->getState();

        $transaction = FinancialTransaction::create($data);

        Notification::make()
            ->title('Transaksi berhasil disimpan.')
            ->body($transaction->type_label . ' ' . $transaction->formatted_amount . ' · ' . ($transaction->category?->name ?? '-'))
            ->success()
            ->send();

        $this->form->fill($this->defaultFormState());
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
     * @return array<string, mixed>
     */
    protected function defaultFormState(): array
    {
        return [
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'transaction_date' => now(),
            'payment_method_id' => FinancialPaymentMethod::query()->where('name', 'Cash')->value('id'),
        ];
    }
}
