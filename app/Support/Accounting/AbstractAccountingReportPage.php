<?php

namespace App\Support\Accounting;

use App\Models\ChartOfAccount;
use App\Services\AccountingReportService;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class AbstractAccountingReportPage extends Page
{
    protected static ?string $navigationGroup = 'Laporan Akuntansi';

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.accounting-report';

    protected static string $reportKey = '';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin() || ($user?->can('view_keuangan_report') ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $defaultAccountId = ChartOfAccount::query()->orderBy('code')->value('id');

        $this->form->fill([
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->toDateString(),
            'account_id' => static::$reportKey === 'general_ledger' ? $defaultAccountId : null,
            'journal_number' => null,
            'source_type' => null,
            'include_zero' => false,
        ]);
    }

    public function form(Form $form): Form
    {
        $isGeneralLedger = static::$reportKey === 'general_ledger';
        $isTrialBalance = static::$reportKey === 'trial_balance';
        $isBalanceSheet = static::$reportKey === 'balance_sheet';

        return $form
            ->schema([
                Forms\Components\DatePicker::make('start_date')
                    ->label('Dari Tanggal')
                    ->required()
                    ->live()
                    ->visible(fn (): bool => ! $isBalanceSheet),

                Forms\Components\DatePicker::make('end_date')
                    ->label($isBalanceSheet ? 'Per Tanggal' : 'Sampai Tanggal')
                    ->required()
                    ->live(),

                Forms\Components\Select::make('account_id')
                    ->label('Akun COA')
                    ->options(fn (): array => ChartOfAccount::query()
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (ChartOfAccount $account): array => [
                            $account->getKey() => $account->code.' - '.$account->name,
                        ])
                        ->all())
                    ->searchable()
                    ->required()
                    ->visible($isGeneralLedger)
                    ->live(),

                Forms\Components\TextInput::make('journal_number')
                    ->label('Nomor Jurnal')
                    ->placeholder('Opsional')
                    ->visible($isGeneralLedger)
                    ->live(onBlur: true),

                Forms\Components\Select::make('source_type')
                    ->label('Sumber Transaksi')
                    ->options([
                        'manual' => 'Manual / Tanpa Referensi',
                        'financial_transaction' => 'Transaksi Operasional',
                        'invoice' => 'Invoice',
                        'session_report' => 'Rekap Sesi',
                        'luminash_csv' => 'Luminash CSV',
                    ])
                    ->placeholder('Semua Sumber')
                    ->visible($isGeneralLedger)
                    ->live(),

                Forms\Components\Toggle::make('include_zero')
                    ->label('Tampilkan akun tanpa saldo')
                    ->visible($isTrialBalance)
                    ->live(),
            ])
            ->statePath('data')
            ->columns(4);
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $service = app(AccountingReportService::class);
        $start = Carbon::parse($this->data['start_date'] ?? now()->startOfMonth());
        $end = Carbon::parse($this->data['end_date'] ?? now());
        $reportKey = static::$reportKey;

        $report = match ($reportKey) {
            'trial_balance' => $service->trialBalance(
                $start,
                $end,
                (bool) ($this->data['include_zero'] ?? false)
            ),
            'general_ledger' => $service->generalLedger(
                $this->resolveAccountId(),
                $start,
                $end,
                $this->data['journal_number'] ?? null,
                $this->data['source_type'] ?? null
            ),
            'profit_loss' => $service->profitLoss($start, $end) + [
                'monthly' => $service->monthlyProfitLoss($start, $end),
            ],
            'balance_sheet' => $service->balanceSheet($end),
            'cash_flow' => $service->cashFlow($start, $end),
            default => [],
        };

        return [
            'reportKey' => $reportKey,
            'report' => $report,
            'start' => $start,
            'end' => $end,
            'reportTitle' => static::$title,
        ];
    }

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $view = $this->getViewData();
        $report = $view['report'];
        $reportKey = $view['reportKey'];
        $headers = [];
        $rows = [];

        if ($reportKey === 'trial_balance') {
            $headers = ['Kode', 'Nama Akun', 'Tipe', 'Opening Debit', 'Opening Kredit', 'Mutasi Debit', 'Mutasi Kredit', 'Ending Debit', 'Ending Kredit'];

            foreach ($report['rows'] as $row) {
                $rows[] = [
                    $row['code'],
                    $row['name'],
                    $row['type'],
                    $this->money($row['opening_debit']),
                    $this->money($row['opening_credit']),
                    $this->money($row['movement_debit']),
                    $this->money($row['movement_credit']),
                    $this->money($row['ending_debit']),
                    $this->money($row['ending_credit']),
                ];
            }
        } elseif ($reportKey === 'general_ledger') {
            $headers = ['Tanggal', 'Nomor Jurnal', 'Deskripsi', 'Sumber', 'Debit', 'Kredit', 'Saldo Berjalan'];

            foreach ($report['rows'] as $row) {
                $rows[] = [
                    $row['journal_date'],
                    $row['journal_number'],
                    $row['description'],
                    $row['source_label'],
                    $this->money($row['debit']),
                    $this->money($row['credit']),
                    $this->money($row['running_balance']),
                ];
            }
        } elseif ($reportKey === 'profit_loss') {
            $headers = ['Section', 'Kode', 'Nama Akun', 'Nominal'];

            foreach ($report['sections'] as $section => $sectionRows) {
                foreach ($sectionRows as $row) {
                    $rows[] = [$section, $row['code'], $row['name'], $this->money($row['amount'])];
                }
            }
        } elseif ($reportKey === 'balance_sheet') {
            $headers = ['Section', 'Kode', 'Nama Akun', 'Saldo'];

            foreach ($report['sections'] as $section => $sectionRows) {
                foreach ($sectionRows as $row) {
                    $rows[] = [$section, $row['code'], $row['name'], $this->money($row['balance'])];
                }
            }

            $rows[] = ['equity', '-', 'Current Earnings', $this->money($report['current_earnings'])];
            $rows[] = ['equity', '-', 'Prior Earnings', $this->money($report['prior_earnings'])];
        } elseif ($reportKey === 'cash_flow') {
            $headers = ['Kategori', 'Tanggal', 'Nomor Jurnal', 'Deskripsi', 'Akun Lawan', 'Nominal'];

            foreach ($report['rows'] as $row) {
                $rows[] = [
                    $row['category_label'],
                    $row['journal_date'],
                    $row['journal_number'],
                    $row['description'],
                    $row['account_code'].' - '.$row['account_name'],
                    $this->money($row['amount']),
                ];
            }
        }

        $filename = 'accounting-'.$reportKey.'-'.$view['start']->format('Ymd').'-'.$view['end']->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($headers, $rows): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $headers);

            foreach ($rows as $row) {
                fputcsv($output, $row);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function resolveAccountId(): int
    {
        $accountId = (int) ($this->data['account_id'] ?? 0);

        if ($accountId > 0) {
            return $accountId;
        }

        return (int) ChartOfAccount::query()->orderBy('code')->value('id');
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }
}
