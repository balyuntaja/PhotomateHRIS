<?php

namespace Tests\Feature;

use App\Models\CategoryAccountMapping;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Karyawan;
use App\Services\JournalPostingService;
use App\Services\KeuanganService;
use App\Services\TransactionAccountingService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AccountingTestHelpers;
use Tests\TestCase;

class AccountingTreatmentClassificationTest extends TestCase
{
    use AccountingTestHelpers;
    use RefreshDatabase;

    protected Karyawan $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccountingData();

        $this->admin = $this->makeKaryawan('K0001', 'R01');

        $this->actingAs($this->admin);
    }

    public function test_expense_bisa_mendebit_aset_tanpa_menambah_beban(): void
    {
        $category = $this->makeExpenseCategory('Peralatan Aset Tetap Uji', '1500');

        $transaction = $this->expenseTransaction($category, 2_000_000, 'Pembelian kamera uji');

        $journal = $this->postedJournal($transaction);

        $this->assertJournalLine($journal, '1500', 2000000, 0);
        $this->assertJournalLine($journal, '1004', 0, 2000000);
        $this->assertFalse($this->hasExpenseLine($journal));
        $this->assertTrue($journal->is_balanced);

        $this->assertSame(2000000, $this->netForCode('1500'));
        $this->assertSame(0, $this->accountingExpenseTotal());
    }

    public function test_expense_bisa_mendebit_liabilitas_untuk_pembayaran_pokok(): void
    {
        $category = $this->makeExpenseCategory('Pembayaran Pokok Pinjaman Uji', '2000');

        $transaction = $this->expenseTransaction($category, 1_000_000, 'Pembayaran pokok pinjaman uji');

        $journal = $this->postedJournal($transaction);

        $this->assertJournalLine($journal, '2000', 1000000, 0);
        $this->assertJournalLine($journal, '1004', 0, 1000000);
        $this->assertFalse($this->hasExpenseLine($journal));
        $this->assertTrue($journal->is_balanced);

        $this->assertSame(1000000, $this->netForCode('2000'));
        $this->assertSame(0, $this->accountingExpenseTotal());
    }

    public function test_expense_bisa_mendebit_ekuitas_untuk_distribusi_laba(): void
    {
        $category = $this->makeExpenseCategory('Distribusi Laba Uji', '3000');

        $transaction = $this->expenseTransaction($category, 500_000, 'Distribusi laba uji');

        $journal = $this->postedJournal($transaction);

        $this->assertJournalLine($journal, '3000', 500000, 0);
        $this->assertJournalLine($journal, '1004', 0, 500000);
        $this->assertFalse($this->hasExpenseLine($journal));
        $this->assertTrue($journal->is_balanced);

        $this->assertSame(500000, $this->netForCode('3000'));
        $this->assertSame(0, $this->accountingExpenseTotal());
    }

    public function test_expense_bisa_mendebit_beban_bunga(): void
    {
        $category = $this->makeExpenseCategory('Beban Bunga Pinjaman Uji', '5100');

        $transaction = $this->expenseTransaction($category, 100_000, 'Bunga pinjaman uji');

        $journal = $this->postedJournal($transaction);

        $this->assertJournalLine($journal, '5100', 100000, 0);
        $this->assertJournalLine($journal, '1004', 0, 100000);
        $this->assertTrue($journal->is_balanced);

        $this->assertSame(100000, $this->accountingExpenseTotal());
    }

    public function test_pembayaran_utang_tidak_mengakui_beban_dua_kali(): void
    {
        $expenseCategory = $this->makeExpenseCategory('Beban Utang Uji', '5100');
        $paymentCategory = $this->makeExpenseCategory('Pembayaran Utang Uji', '2000');

        $recognition = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 300000,
            'category_id' => $expenseCategory->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_UTANG,
            'transaction_date' => '2026-11-10',
            'description' => 'Beban utang uji',
        ]);

        $payment = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 300000,
            'category_id' => $paymentCategory->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-11',
            'description' => 'Pembayaran utang uji',
        ]);

        $this->assertJournalLine($this->postedJournal($recognition), '5100', 300000, 0);
        $this->assertJournalLine($this->postedJournal($recognition), '2000', 0, 300000);

        $paymentJournal = $this->postedJournal($payment);

        $this->assertJournalLine($paymentJournal, '2000', 300000, 0);
        $this->assertJournalLine($paymentJournal, '1004', 0, 300000);
        $this->assertFalse($this->hasExpenseLine($paymentJournal));

        $this->assertSame(300000, $this->accountingExpenseTotal());
        $this->assertSame(0, $this->netForCode('2000'));
    }

    public function test_reversal_pembelian_aset_menghasilkan_net_nol(): void
    {
        $category = $this->makeExpenseCategory('Peralatan Reversal Uji', '1500');
        $transaction = $this->expenseTransaction($category, 750_000, 'Aset reversal uji');

        $journal = $this->postedJournal($transaction);

        $reversal = app(JournalPostingService::class)->reverse($journal, 'Uji reversal aset', '2026-11-11', $this->admin);

        $this->assertSame(JournalEntry::STATUS_POSTED, $journal->fresh()->status);
        $this->assertSame(JournalEntry::STATUS_POSTED, $reversal->status);
        $this->assertSame(0, $this->netForCode('1500'));
        $this->assertSame(0, $this->accountingExpenseTotal());
    }

    public function test_mapping_expense_ke_akun_revenue_ditolak(): void
    {
        $category = FinancialCategory::create([
            'name' => 'Mapping Expense Invalid Uji',
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'is_active' => true,
        ]);

        $this->expectException(DomainException::class);

        CategoryAccountMapping::create([
            'financial_category_id' => $category->getKey(),
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'debit_account_id' => $this->accountingAccount('4000')->getKey(),
            'credit_account_id' => null,
            'is_active' => true,
        ]);
    }

    public function test_mapping_income_ke_akun_beban_ditolak(): void
    {
        $category = FinancialCategory::create([
            'name' => 'Mapping Income Invalid Uji',
            'transaction_type' => FinancialCategory::TYPE_INCOME,
            'is_active' => true,
        ]);

        $this->expectException(DomainException::class);

        CategoryAccountMapping::create([
            'financial_category_id' => $category->getKey(),
            'transaction_type' => FinancialCategory::TYPE_INCOME,
            'debit_account_id' => null,
            'credit_account_id' => $this->accountingAccount('5100')->getKey(),
            'is_active' => true,
        ]);
    }

    public function test_laporan_operasional_adalah_kas_keluar_bukan_laba_akuntansi(): void
    {
        $asset = $this->expenseTransaction($this->makeExpenseCategory('Aset Laporan Uji', '1500'), 1_000_000, 'Aset laporan');
        $liability = $this->expenseTransaction($this->makeExpenseCategory('Liabilitas Laporan Uji', '2000'), 500_000, 'Liabilitas laporan');
        $equity = $this->expenseTransaction($this->makeExpenseCategory('Ekuitas Laporan Uji', '3000'), 300_000, 'Ekuitas laporan');
        $expense = $this->expenseTransaction($this->makeExpenseCategory('Beban Laporan Uji', '5100'), 200_000, 'Beban laporan');

        $summary = app(KeuanganService::class)->summary(
            Carbon::parse('2026-11-01'),
            Carbon::parse('2026-11-30')
        );

        // Laporan operasional existing adalah laporan kas keluar: semua
        // transaksi bertipe expense ikut dihitung.
        $this->assertSame(2000000, $summary['expense']);
        $this->assertSame(0, $summary['income']);
        $this->assertSame(-2000000, $summary['balance']);

        // Laporan laba akuntansi (diuji dari COA jurnal) hanya memuat 5100.
        $this->assertSame(200000, $this->accountingExpenseTotal());
        $this->assertSame(1000000, $this->netForCode('1500'));
        $this->assertSame(500000, $this->netForCode('2000'));
        $this->assertSame(300000, $this->netForCode('3000'));

        foreach ([$asset, $liability, $equity, $expense] as $transaction) {
            $this->assertTrue($this->postedJournal($transaction)->is_balanced);
        }
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
            'notes' => 'Uji accounting treatment',
        ]);

        return $category;
    }

    private function expenseTransaction(FinancialCategory $category, int $amount, string $description): FinancialTransaction
    {
        return $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => $amount,
            'category_id' => $category->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-10',
            'description' => $description,
        ]);
    }

    private function postedJournal(FinancialTransaction $transaction): JournalEntry
    {
        return JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_FINANCIAL_TRANSACTION)
            ->where('reference_id', (string) $transaction->getKey())
            ->where('status', JournalEntry::STATUS_POSTED)
            ->whereNull('reversal_of_id')
            ->whereDoesntHave('reversals', fn ($query) => $query->where('status', JournalEntry::STATUS_POSTED))
            ->latest('id')
            ->firstOrFail();
    }

    private function assertJournalLine(JournalEntry $journal, string $accountCode, int $debit, int $credit): void
    {
        $line = $journal->lines()
            ->whereHas('chartOfAccount', fn ($query) => $query->where('code', $accountCode))
            ->first();

        $this->assertNotNull($line, 'Baris akun '.$accountCode.' tidak ditemukan.');
        $this->assertSame($debit * 100, (int) round(((float) $line->debit) * 100));
        $this->assertSame($credit * 100, (int) round(((float) $line->credit) * 100));
    }

    private function hasExpenseLine(JournalEntry $journal): bool
    {
        return $journal->lines()
            ->whereHas('chartOfAccount', fn ($query) => $query->whereIn('type', [
                \App\Models\ChartOfAccount::TYPE_EXPENSE,
                \App\Models\ChartOfAccount::TYPE_COGS,
            ]))
            ->exists();
    }

    private function netForCode(string $code): int
    {
        $accountId = $this->accountingAccount($code)->getKey();

        $debit = (int) round((float) JournalLine::query()
            ->where('chart_of_account_id', $accountId)
            ->whereHas('journalEntry', fn ($query) => $query->where('status', JournalEntry::STATUS_POSTED))
            ->sum('debit'));

        $credit = (int) round((float) JournalLine::query()
            ->where('chart_of_account_id', $accountId)
            ->whereHas('journalEntry', fn ($query) => $query->where('status', JournalEntry::STATUS_POSTED))
            ->sum('credit'));

        return $debit - $credit;
    }

    private function accountingExpenseTotal(): int
    {
        $debit = (int) round((float) JournalLine::query()
            ->whereHas('journalEntry', fn ($query) => $query->where('status', JournalEntry::STATUS_POSTED))
            ->whereHas('chartOfAccount', fn ($query) => $query->whereIn('type', [
                \App\Models\ChartOfAccount::TYPE_EXPENSE,
                \App\Models\ChartOfAccount::TYPE_COGS,
            ]))
            ->sum('debit'));

        $credit = (int) round((float) JournalLine::query()
            ->whereHas('journalEntry', fn ($query) => $query->where('status', JournalEntry::STATUS_POSTED))
            ->whereHas('chartOfAccount', fn ($query) => $query->whereIn('type', [
                \App\Models\ChartOfAccount::TYPE_EXPENSE,
                \App\Models\ChartOfAccount::TYPE_COGS,
            ]))
            ->sum('credit'));

        return $debit - $credit;
    }

    private function account(string $name): FinancialAccount
    {
        return FinancialAccount::query()->where('name', $name)->firstOrFail();
    }
}
