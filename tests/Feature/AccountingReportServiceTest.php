<?php

namespace Tests\Feature;

use App\Models\CategoryAccountMapping;
use App\Models\ChartOfAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Services\AccountingReportService;
use App\Services\JournalPostingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AccountingTestHelpers;
use Tests\TestCase;

class AccountingReportServiceTest extends TestCase
{
    use AccountingTestHelpers;
    use RefreshDatabase;

    protected AccountingReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccountingData();

        $this->actingAs($this->makeKaryawan('K0001', 'R01'));

        $this->service = app(AccountingReportService::class);
    }

    public function test_trial_balance_balance_dan_memuat_opening_movement_ending(): void
    {
        $this->seedReportingDataset();

        $report = $this->service->trialBalance(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

        $this->assertTrue($report['is_balanced']);
        $this->assertSame($report['totals']['opening_debit'], $report['totals']['opening_credit']);
        $this->assertSame($report['totals']['movement_debit'], $report['totals']['movement_credit']);
        $this->assertSame($report['totals']['ending_debit'], $report['totals']['ending_credit']);

        $cashRow = collect($report['rows'])->firstWhere('code', '1004');
        $this->assertNotNull($cashRow);
        $this->assertSame(500000000, $cashRow['opening_debit']);
        $this->assertSame(980000000, $cashRow['ending_debit']);

        $revenueRow = collect($report['rows'])->firstWhere('code', '4000');
        $this->assertNotNull($revenueRow);
        $this->assertSame(1000000000, $revenueRow['movement_credit']);
        $this->assertSame(1000000000, $revenueRow['ending_credit']);
    }

    public function test_general_ledger_mengikuti_normal_balance_dan_filter(): void
    {
        $this->seedReportingDataset();

        $revenue = $this->accountingAccount('4000');
        $ledger = $this->service->generalLedger(
            (int) $revenue->getKey(),
            Carbon::parse('2026-11-01'),
            Carbon::parse('2026-11-30')
        );

        $this->assertSame(0, $ledger['opening_balance']);
        $this->assertSame(1000000000, $ledger['ending_balance']);
        $this->assertCount(1, $ledger['rows']);
        $this->assertSame(1000000000, $ledger['rows'][0]['running_balance']);
        $this->assertSame('Transaksi Operasional', $ledger['rows'][0]['source_label']);

        $cash = $this->accountingAccount('1004');
        $cashLedger = $this->service->generalLedger(
            (int) $cash->getKey(),
            Carbon::parse('2026-11-01'),
            Carbon::parse('2026-11-30')
        );

        $this->assertSame(500000000, $cashLedger['opening_balance']);
        $this->assertSame(980000000, $cashLedger['ending_balance']);
        $this->assertNotEmpty($cashLedger['rows']);
    }

    public function test_profit_loss_hanya_memuat_revenue_cogs_dan_expense(): void
    {
        $this->seedReportingDataset();

        $report = $this->service->profitLoss(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

        $this->assertSame(1000000000, $report['totals']['revenue']);
        $this->assertSame(300000000, $report['totals']['cogs']);
        $this->assertSame(700000000, $report['totals']['gross_profit']);
        $this->assertSame(100000000, $report['totals']['opex']);
        $this->assertSame(600000000, $report['totals']['operating_profit']);
        $this->assertSame(50000000, $report['totals']['other_income']);
        $this->assertSame(20000000, $report['totals']['other_expense']);
        $this->assertSame(630000000, $report['totals']['net_profit']);

        $allAccountCodes = collect($report['sections'])
            ->flatten(1)
            ->pluck('code')
            ->all();

        $this->assertNotContains('1500', $allAccountCodes);
        $this->assertNotContains('2000', $allAccountCodes);
        $this->assertNotContains('3000', $allAccountCodes);
        $this->assertContains('4000', $allAccountCodes);
        $this->assertContains('5000', $allAccountCodes);
        $this->assertContains('5100', $allAccountCodes);

        $monthly = $this->service->monthlyProfitLoss(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $this->assertCount(1, $monthly);
        $this->assertSame('2026-11', $monthly[0]['period']);
        $this->assertSame(630000000, $monthly[0]['totals']['net_profit']);
    }

    public function test_balance_sheet_memenuhi_persamaan_akuntansi(): void
    {
        $this->seedReportingDataset();

        $report = $this->service->balanceSheet(Carbon::parse('2026-11-30'));

        $this->assertTrue($report['is_balanced']);
        $this->assertSame(1180000000, $report['total_assets']);
        $this->assertSame(100000000, $report['total_liabilities']);
        $this->assertSame(450000000, $report['total_equity_accounts']);
        $this->assertSame(630000000, $report['current_earnings']);
        $this->assertSame(0, $report['prior_earnings']);
        $this->assertSame(1080000000, $report['total_equity']);
        $this->assertSame(0, $report['difference']);
    }

    public function test_cash_flow_direkonsiliasi_dengan_mutasi_kas(): void
    {
        $this->seedReportingDataset();

        $report = $this->service->cashFlow(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

        $this->assertTrue($report['is_reconciled']);
        $this->assertSame(500000000, $report['beginning_cash']);
        $this->assertSame(480000000, $report['period_cash_change']);
        $this->assertSame(980000000, $report['ending_cash']);
        $this->assertSame(630000000, $report['totals']['operating']);
        $this->assertSame(-200000000, $report['totals']['investing']);
        $this->assertSame(50000000, $report['totals']['financing']);
        $this->assertSame(0, $report['totals']['unclassified']);
        $this->assertSame($report['period_cash_change'], $report['classified_total']);
        $this->assertSame(0, $report['difference']);
        $this->assertContains('1001', $report['cash_account_codes']);
        $this->assertContains('1004', $report['cash_account_codes']);
    }

    public function test_draft_journal_tidak_masuk_laporan(): void
    {
        $this->seedReportingDataset();

        $before = $this->service->profitLoss(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

        app(JournalPostingService::class)->createDraft([
            'journal_date' => '2026-11-20',
            'description' => 'Draft tidak boleh masuk laporan',
            'idempotency_key' => 'report-draft-exclusion',
        ], [
            ['chart_of_account_id' => $this->accountingAccount('1004')->getKey(), 'debit' => 1000000, 'credit' => 0],
            ['chart_of_account_id' => $this->accountingAccount('4000')->getKey(), 'debit' => 0, 'credit' => 1000000],
        ]);

        $after = $this->service->profitLoss(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

        $this->assertSame($before['totals']['net_profit'], $after['totals']['net_profit']);
        $this->assertSame($before['totals']['revenue'], $after['totals']['revenue']);
    }

    public function test_reversal_tidak_menyebabkan_double_counting(): void
    {
        $this->seedReportingDataset();

        $before = $this->service->profitLoss(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $cashBefore = $this->service->cashFlow(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 1000000,
            'category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-21',
            'description' => 'Reversal report test',
        ]);

        $journal = $transaction->fresh()->postedJournal();
        $this->assertNotNull($journal);

        $reversal = app(JournalPostingService::class)->reverse($journal, 'Reversal report test', '2026-11-30');

        $this->assertSame(JournalEntry::STATUS_POSTED, $reversal->status);
        $this->assertSame(1, JournalEntry::query()->where('reversal_of_id', $journal->getKey())->count());

        $reversalRevenueDebit = \App\Models\JournalLine::query()
            ->where('journal_entry_id', $reversal->getKey())
            ->where('chart_of_account_id', $this->accountingAccount('4000')->getKey())
            ->sum('debit');

        $this->assertSame(1000000, (int) round((float) $reversalRevenueDebit));

        $after = $this->service->profitLoss(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $cashAfter = $this->service->cashFlow(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

        $this->assertSame($before['totals']['revenue'], $after['totals']['revenue']);
        $this->assertSame($before['totals']['net_profit'], $after['totals']['net_profit']);
        $this->assertSame($cashBefore['period_cash_change'], $cashAfter['period_cash_change']);
        $this->assertTrue($this->service->trialBalance(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'))['is_balanced']);
    }

    private function seedReportingDataset(): void
    {
        $otherIncome = $this->makeAccount([
            'code' => '4900',
            'name' => 'Pendapatan Lain Uji',
            'type' => ChartOfAccount::TYPE_REVENUE,
            'report_group' => 'other_income',
        ]);

        $otherExpense = $this->makeAccount([
            'code' => '5900',
            'name' => 'Beban Lain Uji',
            'type' => ChartOfAccount::TYPE_EXPENSE,
            'report_group' => 'other_expense',
        ]);

        $loan = $this->makeAccount([
            'code' => '2100',
            'name' => 'Pinjaman Bank Uji',
            'type' => ChartOfAccount::TYPE_LIABILITY,
            'report_group' => 'long_term_liability',
            'cash_flow_category' => ChartOfAccount::CASH_FLOW_FINANCING,
        ]);

        $this->makeExpenseCategory('Peralatan Aset Laporan', '1500');
        $this->makeExpenseCategory('Pembayaran Pinjaman Laporan', '2100');
        $this->makeExpenseCategory('Distribusi Laba Laporan', '3000');
        $this->makeIncomeCategory('Pendapatan Lain Laporan', '4900');
        $this->makeExpenseCategory('Beban Lain Laporan', '5900');

        $this->postJournal('2026-10-01', 'Modal awal uji', [
            ['chart_of_account_id' => $this->accountingAccount('1004')->getKey(), 'debit' => 5000000, 'credit' => 0],
            ['chart_of_account_id' => $this->accountingAccount('3000')->getKey(), 'debit' => 0, 'credit' => 5000000],
        ], 'report-opening-capital');

        $this->postJournal('2026-11-01', 'Penerimaan pinjaman uji', [
            ['chart_of_account_id' => $this->accountingAccount('1004')->getKey(), 'debit' => 2000000, 'credit' => 0],
            ['chart_of_account_id' => $loan->getKey(), 'debit' => 0, 'credit' => 2000000],
        ], 'report-loan-receipt');

        $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 10000000,
            'category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-05',
            'description' => 'Pendapatan booth laporan',
        ]);

        $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 3000000,
            'category_id' => $this->category('Kertas', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-06',
            'description' => 'HPP kertas laporan',
        ]);

        $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 1000000,
            'category_id' => $this->category('Fee Crew', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-07',
            'description' => 'Beban operasional laporan',
        ]);

        $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 500000,
            'category_id' => $this->category('Pendapatan Lain Laporan', FinancialCategory::TYPE_INCOME)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-08',
            'description' => 'Pendapatan lain laporan',
        ]);

        $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 200000,
            'category_id' => $this->category('Beban Lain Laporan', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-09',
            'description' => 'Beban lain laporan',
        ]);

        $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 2000000,
            'category_id' => $this->category('Peralatan Aset Laporan', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-10',
            'description' => 'Pembelian aset laporan',
        ]);

        $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 1000000,
            'category_id' => $this->category('Pembayaran Pinjaman Laporan', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-11',
            'description' => 'Pembayaran pokok pinjaman laporan',
        ]);

        $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 500000,
            'category_id' => $this->category('Distribusi Laba Laporan', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-12',
            'description' => 'Distribusi laba laporan',
        ]);
    }

    private function makeExpenseCategory(string $name, string $coaCode): FinancialCategory
    {
        $category = FinancialCategory::create([
            'name' => $name,
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'is_active' => true,
        ]);

        CategoryAccountMapping::create([
            'financial_category_id' => $category->getKey(),
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'debit_account_id' => $this->accountingAccount($coaCode)->getKey(),
            'credit_account_id' => null,
            'is_active' => true,
            'notes' => 'Dataset laporan',
        ]);

        return $category;
    }

    private function makeIncomeCategory(string $name, string $coaCode): FinancialCategory
    {
        $category = FinancialCategory::create([
            'name' => $name,
            'transaction_type' => FinancialCategory::TYPE_INCOME,
            'is_active' => true,
        ]);

        CategoryAccountMapping::create([
            'financial_category_id' => $category->getKey(),
            'transaction_type' => FinancialCategory::TYPE_INCOME,
            'debit_account_id' => null,
            'credit_account_id' => $this->accountingAccount($coaCode)->getKey(),
            'is_active' => true,
            'notes' => 'Dataset laporan',
        ]);

        return $category;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function postJournal(string $date, string $description, array $lines, string $key): JournalEntry
    {
        return app(JournalPostingService::class)->postJournal([
            'journal_date' => $date,
            'description' => $description,
            'reference_type' => 'report_test',
            'reference_id' => $key,
            'idempotency_key' => $key,
        ], $lines);
    }

    private function account(string $name): FinancialAccount
    {
        return FinancialAccount::query()->where('name', $name)->firstOrFail();
    }
}
