<?php

namespace Tests\Feature;

use App\Models\CategoryAccountMapping;
use App\Models\ChartOfAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Services\JournalPostingService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AccountingTestHelpers;
use Tests\TestCase;

class AccountingFoundationValidationTest extends TestCase
{
    use AccountingTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccountingData();
    }

    public function test_mapping_income_dapat_mengkreditkan_piutang_usaha_untuk_pelunasan_invoice(): void
    {
        $category = $this->category('Pelunasan Invoice', FinancialCategory::TYPE_INCOME);
        $receivableAccount = $this->accountingAccount('1100');

        $mapping = CategoryAccountMapping::updateOrCreate(
            ['financial_category_id' => $category->getKey()],
            [
                'transaction_type' => FinancialCategory::TYPE_INCOME,
                'debit_account_id' => null,
                'credit_account_id' => $receivableAccount->getKey(),
                'is_active' => true,
                'notes' => 'Pelunasan piutang invoice',
            ]
        );

        $this->assertSame($receivableAccount->getKey(), $mapping->credit_account_id);
        $this->assertTrue($mapping->isUsable());
        $this->assertSame($mapping->getKey(), CategoryAccountMapping::resolveForCategory($category)?->getKey());
    }

    public function test_mapping_expense_dapat_mendebitkan_utang_usaha_untuk_pembayaran_utang(): void
    {
        $category = $this->category('Administrasi', FinancialCategory::TYPE_EXPENSE);
        $payableAccount = $this->accountingAccount('2000');

        $mapping = CategoryAccountMapping::updateOrCreate(
            ['financial_category_id' => $category->getKey()],
            [
                'transaction_type' => FinancialCategory::TYPE_EXPENSE,
                'debit_account_id' => $payableAccount->getKey(),
                'credit_account_id' => null,
                'is_active' => true,
                'notes' => 'Pembayaran utang usaha',
            ]
        );

        $this->assertSame($payableAccount->getKey(), $mapping->debit_account_id);
        $this->assertTrue($mapping->isUsable());
        $this->assertSame($mapping->getKey(), CategoryAccountMapping::resolveForCategory($category)?->getKey());
    }

    public function test_mapping_expense_dapat_mendebitkan_persediaan_dan_aset_tetap(): void
    {
        $kertasCategory = $this->category('Kertas', FinancialCategory::TYPE_EXPENSE);
        $inventoryAccount = $this->accountingAccount('1200');

        $inventoryMapping = CategoryAccountMapping::updateOrCreate(
            ['financial_category_id' => $kertasCategory->getKey()],
            [
                'transaction_type' => FinancialCategory::TYPE_EXPENSE,
                'debit_account_id' => $inventoryAccount->getKey(),
                'credit_account_id' => null,
                'is_active' => true,
                'notes' => 'Pembelian persediaan kertas foto',
            ]
        );

        $this->assertSame($inventoryAccount->getKey(), $inventoryMapping->debit_account_id);
        $this->assertTrue($inventoryMapping->isUsable());

        $peralatanCategory = $this->category('Peralatan', FinancialCategory::TYPE_EXPENSE);
        $fixedAssetAccount = $this->accountingAccount('1500');

        $fixedAssetMapping = CategoryAccountMapping::create([
            'financial_category_id' => $peralatanCategory->getKey(),
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'debit_account_id' => $fixedAssetAccount->getKey(),
            'credit_account_id' => null,
            'is_active' => true,
            'notes' => 'Pengadaan peralatan booth (Aset Tetap)',
        ]);

        $this->assertSame($fixedAssetAccount->getKey(), $fixedAssetMapping->debit_account_id);
        $this->assertTrue($fixedAssetMapping->isUsable());
    }

    public function test_jurnal_expense_lunas_dan_utang_didukung_penuh(): void
    {
        $service = app(JournalPostingService::class);
        $opexAccount = $this->accountingAccount('5100');
        $cashExpress = $this->accountingAccount('1001');
        $payableAccount = $this->accountingAccount('2000');

        $lunasJournal = $service->postJournal([
            'journal_date' => '2026-10-10',
            'description' => 'Beban operasional lunas via kas',
            'idempotency_key' => 'exp-lunas-1',
        ], [
            ['chart_of_account_id' => $opexAccount->getKey(), 'debit' => 300000, 'credit' => 0],
            ['chart_of_account_id' => $cashExpress->getKey(), 'debit' => 0, 'credit' => 300000],
        ]);
        $this->assertSame(JournalEntry::STATUS_POSTED, $lunasJournal->status);

        $utangJournal = $service->postJournal([
            'journal_date' => '2026-10-11',
            'description' => 'Beban operasional belum lunas (tempo)',
            'idempotency_key' => 'exp-utang-1',
        ], [
            ['chart_of_account_id' => $opexAccount->getKey(), 'debit' => 500000, 'credit' => 0],
            ['chart_of_account_id' => $payableAccount->getKey(), 'debit' => 0, 'credit' => 500000],
        ]);
        $this->assertSame(JournalEntry::STATUS_POSTED, $utangJournal->status);

        $bayarUtangJournal = $service->postJournal([
            'journal_date' => '2026-10-20',
            'description' => 'Pembayaran utang operasional',
            'idempotency_key' => 'pay-utang-1',
        ], [
            ['chart_of_account_id' => $payableAccount->getKey(), 'debit' => 500000, 'credit' => 0],
            ['chart_of_account_id' => $cashExpress->getKey(), 'debit' => 0, 'credit' => 500000],
        ]);
        $this->assertSame(JournalEntry::STATUS_POSTED, $bayarUtangJournal->status);
    }

    public function test_penerimaan_pembayaran_invoice_mengurangi_piutang_bukan_mencatat_pendapatan(): void
    {
        $service = app(JournalPostingService::class);
        $receivableAccount = $this->accountingAccount('1100');
        $revenueAccount = $this->accountingAccount('4000');
        $cashExpress = $this->accountingAccount('1001');

        $invoiceJournal = $service->postJournal([
            'journal_date' => '2026-10-01',
            'description' => 'Penerbitan Invoice INV-2026-001',
            'reference_type' => 'invoice',
            'reference_id' => '1',
            'idempotency_key' => 'inv-issue-1',
        ], [
            ['chart_of_account_id' => $receivableAccount->getKey(), 'debit' => 2000000, 'credit' => 0],
            ['chart_of_account_id' => $revenueAccount->getKey(), 'debit' => 0, 'credit' => 2000000],
        ]);
        $this->assertSame('2000000.00', $invoiceJournal->total_debit);

        $dpJournal = $service->postJournal([
            'journal_date' => '2026-10-05',
            'description' => 'Penerimaan DP Invoice INV-2026-001',
            'reference_type' => 'invoice',
            'reference_id' => '1',
            'idempotency_key' => 'inv-dp-1',
        ], [
            ['chart_of_account_id' => $cashExpress->getKey(), 'debit' => 500000, 'credit' => 0],
            ['chart_of_account_id' => $receivableAccount->getKey(), 'debit' => 0, 'credit' => 500000],
        ]);
        $this->assertSame('500000.00', $dpJournal->total_debit);

        $settlementJournal = $service->postJournal([
            'journal_date' => '2026-10-12',
            'description' => 'Penerimaan Pelunasan Invoice INV-2026-001',
            'reference_type' => 'invoice',
            'reference_id' => '1',
            'idempotency_key' => 'inv-settle-1',
        ], [
            ['chart_of_account_id' => $cashExpress->getKey(), 'debit' => 1500000, 'credit' => 0],
            ['chart_of_account_id' => $receivableAccount->getKey(), 'debit' => 0, 'credit' => 1500000],
        ]);
        $this->assertSame('1500000.00', $settlementJournal->total_debit);

        $totalReceivableDebit = \App\Models\JournalLine::query()
            ->where('chart_of_account_id', $receivableAccount->getKey())
            ->sum('debit');
        $totalReceivableCredit = \App\Models\JournalLine::query()
            ->where('chart_of_account_id', $receivableAccount->getKey())
            ->sum('credit');
        $this->assertEquals(2000000, (int) $totalReceivableDebit);
        $this->assertEquals(2000000, (int) $totalReceivableCredit);

        $totalRevenueCredit = \App\Models\JournalLine::query()
            ->where('chart_of_account_id', $revenueAccount->getKey())
            ->sum('credit');
        $this->assertEquals(2000000, (int) $totalRevenueCredit);
    }

    public function test_jurnal_pembelian_persediaan_dan_pengakuan_hpp_terpisah(): void
    {
        $service = app(JournalPostingService::class);
        $inventoryAccount = $this->accountingAccount('1200');
        $cogsPaperAccount = $this->accountingAccount('5000');
        $cashExpress = $this->accountingAccount('1001');

        $purchaseJournal = $service->postJournal([
            'journal_date' => '2026-10-02',
            'description' => 'Pembelian persediaan kertas foto 10 roll',
            'idempotency_key' => 'purchase-paper-1',
        ], [
            ['chart_of_account_id' => $inventoryAccount->getKey(), 'debit' => 500000, 'credit' => 0],
            ['chart_of_account_id' => $cashExpress->getKey(), 'debit' => 0, 'credit' => 500000],
        ]);
        $this->assertSame(JournalEntry::STATUS_POSTED, $purchaseJournal->status);

        $usageJournal = $service->postJournal([
            'journal_date' => '2026-10-10',
            'description' => 'Pemakaian kertas foto untuk booth event (4 roll)',
            'idempotency_key' => 'cogs-usage-paper-1',
        ], [
            ['chart_of_account_id' => $cogsPaperAccount->getKey(), 'debit' => 200000, 'credit' => 0],
            ['chart_of_account_id' => $inventoryAccount->getKey(), 'debit' => 0, 'credit' => 200000],
        ]);
        $this->assertSame(JournalEntry::STATUS_POSTED, $usageJournal->status);

        $inventoryDebit = \App\Models\JournalLine::query()->where('chart_of_account_id', $inventoryAccount->getKey())->sum('debit');
        $inventoryCredit = \App\Models\JournalLine::query()->where('chart_of_account_id', $inventoryAccount->getKey())->sum('credit');
        $cogsDebit = \App\Models\JournalLine::query()->where('chart_of_account_id', $cogsPaperAccount->getKey())->sum('debit');

        $this->assertEquals(500000, (int) $inventoryDebit);
        $this->assertEquals(200000, (int) $inventoryCredit);
        $this->assertEquals(300000, (int) ($inventoryDebit - $inventoryCredit));
        $this->assertEquals(200000, (int) $cogsDebit);
    }

    public function test_reversal_jurnal_dari_periode_closed_berhasil_diposting_ke_periode_open_berjalan(): void
    {
        $service = app(JournalPostingService::class);
        $original = $service->postJournal([
            'journal_date' => '2026-10-15',
            'description' => 'Jurnal transaksi periode Oktober',
            'idempotency_key' => 'orig-oct-1',
        ], [
            ['chart_of_account_id' => $this->accountingAccount('1001')->getKey(), 'debit' => 100000, 'credit' => 0],
            ['chart_of_account_id' => $this->accountingAccount('4000')->getKey(), 'debit' => 0, 'credit' => 100000],
        ]);

        $octoberPeriod = FiscalPeriod::query()->where('year', 2026)->where('month', 10)->firstOrFail();
        $octoberPeriod->close();
        $this->assertTrue($octoberPeriod->isClosed());

        $reversal = $service->reverse($original, 'Reversal di periode November', '2026-11-02');

        $this->assertSame(JournalEntry::STATUS_POSTED, $reversal->status);
        $this->assertSame('2026-11-02', $reversal->journal_date->toDateString());
        $this->assertSame($original->getKey(), $reversal->reversal_of_id);

        $novemberPeriod = FiscalPeriod::query()->where('year', 2026)->where('month', 11)->firstOrFail();
        $this->assertTrue($novemberPeriod->isOpen());
        $this->assertSame($novemberPeriod->getKey(), $reversal->fiscal_period_id);

        $original->refresh();
        $this->assertSame(JournalEntry::STATUS_POSTED, $original->status);
        $this->assertSame($octoberPeriod->getKey(), $original->fiscal_period_id);
    }

    public function test_reversal_dengan_tanggal_pada_periode_closed_ditolak(): void
    {
        $service = app(JournalPostingService::class);
        $original = $service->postJournal([
            'journal_date' => '2026-10-15',
            'description' => 'Jurnal transaksi periode Oktober',
            'idempotency_key' => 'orig-oct-2',
        ], [
            ['chart_of_account_id' => $this->accountingAccount('1001')->getKey(), 'debit' => 100000, 'credit' => 0],
            ['chart_of_account_id' => $this->accountingAccount('4000')->getKey(), 'debit' => 0, 'credit' => 100000],
        ]);

        $octoberPeriod = FiscalPeriod::query()->where('year', 2026)->where('month', 10)->firstOrFail();
        $octoberPeriod->close();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah ditutup');

        $service->reverse($original, 'Mencoba reversal pada tanggal di periode closed', '2026-10-20');
    }

    public function test_jurnal_dengan_tanggal_di_luar_rentang_tanggal_periode_ditolak(): void
    {
        FiscalPeriod::create([
            'year' => 2026,
            'month' => 10,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-25',
            'status' => FiscalPeriod::STATUS_OPEN,
        ]);

        $service = app(JournalPostingService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('berada di luar rentang tanggal');

        $service->createDraft([
            'journal_date' => '2026-10-28',
            'description' => 'Tanggal melewati batas akhir periode',
        ], [
            ['chart_of_account_id' => $this->accountingAccount('1001')->getKey(), 'debit' => 100000, 'credit' => 0],
            ['chart_of_account_id' => $this->accountingAccount('4000')->getKey(), 'debit' => 0, 'credit' => 100000],
        ]);
    }

    public function test_resolusi_akun_lawan_dinamis_dari_akun_operasional(): void
    {
        $operationalAccounts = [
            'Photomate Express' => '1001',
            'Golio' => '1002',
            'Janus' => '1003',
            'Ekspansi' => '1004',
        ];

        foreach ($operationalAccounts as $accountName => $expectedCoaCode) {
            $financialAccount = FinancialAccount::query()->where('name', $accountName)->firstOrFail();
            $coa = $financialAccount->chartOfAccount;

            $this->assertNotNull($coa);
            $this->assertSame($expectedCoaCode, $coa->code);
            $this->assertSame(ChartOfAccount::TYPE_ASSET, $coa->type);
            $this->assertTrue($coa->is_active);
        }
    }
}
