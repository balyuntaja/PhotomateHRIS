<?php

namespace Tests\Feature;

use App\Models\CategoryAccountMapping;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Models\SessionTransaction;
use App\Services\InvoiceFinancialService;
use App\Services\JournalPostingService;
use App\Services\LuminashImportService;
use App\Services\PricingService;
use App\Services\SessionReportFinancialService;
use App\Services\SessionWorkflowService;
use App\Services\TransactionAccountingService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\AccountingTestHelpers;
use Tests\TestCase;

class AccountingTransactionIntegrationTest extends TestCase
{
    use AccountingTestHelpers;
    use RefreshDatabase;

    protected Karyawan $admin;

    protected Karyawan $crew;

    protected \App\Models\Cabang $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00'));

        Storage::fake('local');

        $this->setUpAccountingData();

        $this->admin = $this->makeKaryawan('K0001', 'R01');
        $this->crew = $this->makeKaryawan('K0002', 'R07');

        $this->branch = \App\Models\Cabang::firstOrCreate(
            ['nama_cabang' => 'Ekspansi'],
            [
                'cabang_id' => 'C9001',
                'perusahaan_id' => 'P0001',
                'alamat' => 'Ekspansi Uji',
            ]
        );

        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_pemasukan_lunas_membentuk_jurnal_kas_dan_pendapatan(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 125000,
            'category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-05',
            'description' => 'Pemasukan booth uji',
        ]);

        $journal = $this->journalForFinancialTransaction($transaction);

        $this->assertNotNull($journal);
        $this->assertSame(JournalEntry::STATUS_POSTED, $journal->status);
        $this->assertSame('2026-11-05', $journal->journal_date->toDateString());
        $this->assertSame((string) $transaction->getKey(), $journal->reference_id);
        $this->assertJournalLine($journal, '1004', 125000, 0);
        $this->assertJournalLine($journal, '4000', 0, 125000);
        $this->assertTrue($journal->is_balanced);
    }

    public function test_pengeluaran_lunas_membentuk_jurnal_beban_dan_kas(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 75000,
            'category_id' => $this->category('Kertas', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-06',
        ]);

        $journal = $this->journalForFinancialTransaction($transaction);

        $this->assertNotNull($journal);
        $this->assertJournalLine($journal, '5000', 75000, 0);
        $this->assertJournalLine($journal, '1004', 0, 75000);
        $this->assertSame(0, $this->netForCode('2000'));
    }

    public function test_pengeluaran_utang_membentuk_jurnal_beban_dan_utang_usaha(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 80000,
            'category_id' => $this->category('Kertas', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_UTANG,
            'transaction_date' => '2026-11-06',
            'due_date' => '2026-12-06',
        ]);

        $journal = $this->journalForFinancialTransaction($transaction);

        $this->assertNotNull($journal);
        $this->assertJournalLine($journal, '5000', 80000, 0);
        $this->assertJournalLine($journal, '2000', 0, 80000);
        $this->assertSame(0, $this->netForCode('1004'));
    }

    public function test_pembayaran_utang_tidak_mencatat_beban_lagi(): void
    {
        $category = FinancialCategory::create([
            'name' => 'Pembayaran Utang Uji',
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'is_active' => true,
        ]);

        CategoryAccountMapping::create([
            'financial_category_id' => $category->getKey(),
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'debit_account_id' => $this->accountingAccount('2000')->getKey(),
            'credit_account_id' => null,
            'is_active' => true,
            'notes' => 'Uji pembayaran utang',
        ]);

        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 50000,
            'category_id' => $category->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-07',
            'description' => 'Pembayaran utang usaha',
        ]);

        $journal = $this->journalForFinancialTransaction($transaction);

        $this->assertNotNull($journal);
        $this->assertJournalLine($journal, '2000', 50000, 0);
        $this->assertJournalLine($journal, '1004', 0, 50000);
        $this->assertSame(0, $this->netForCode('5000'));
        $this->assertSame(0, $this->netForCode('5100'));
    }

    public function test_approval_rekap_sesi_membentuk_jurnal_pendapatan(): void
    {
        $report = $this->makeSessionReport([
            ['CASH', 30000],
            ['QRIS', 45000],
        ]);

        app(SessionWorkflowService::class)->approve($report, $this->admin);

        $journals = $this->effectiveJournals(
            TransactionAccountingService::REF_SESSION_REPORT,
            (string) $report->id
        );

        $this->assertCount(2, $journals);
        $this->assertSame(75000, $this->netCreditForCode('4000', TransactionAccountingService::REF_SESSION_REPORT, (string) $report->id));

        foreach ($journals as $journal) {
            $this->assertJournalLine($journal, '1004', (int) round((float) $journal->total_debit), 0);
            $this->assertJournalLine($journal, '4000', 0, (int) round((float) $journal->total_credit));
        }
    }

    public function test_approval_rekap_sesi_berulang_tidak_membuat_jurnal_ganda(): void
    {
        $report = $this->makeSessionReport([
            ['CASH', 30000],
            ['QRIS', 45000],
        ]);

        app(SessionWorkflowService::class)->approve($report, $this->admin);
        $report->refresh();

        $countAfterApproval = JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_SESSION_REPORT)
            ->where('reference_id', (string) $report->id)
            ->count();

        app(TransactionAccountingService::class)->syncSessionReport($report, $this->admin);
        app(TransactionAccountingService::class)->syncSessionReport($report, $this->admin);

        $this->assertSame(2, $countAfterApproval);
        $this->assertSame($countAfterApproval, JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_SESSION_REPORT)
            ->where('reference_id', (string) $report->id)
            ->count());

        try {
            app(SessionWorkflowService::class)->approve($report, $this->admin);
            $this->fail('Approval ulang seharusnya ditolak.');
        } catch (RuntimeException) {
            // Rekap sudah approved.
        }

        $this->assertSame(2, JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_SESSION_REPORT)
            ->where('reference_id', (string) $report->id)
            ->whereNull('reversal_of_id')
            ->count());
    }

    public function test_reversal_rekap_sesi_tidak_menghapus_jurnal_asli(): void
    {
        $report = $this->makeSessionReport([
            ['CASH', 30000],
            ['QRIS', 45000],
        ]);

        app(SessionWorkflowService::class)->approve($report, $this->admin);
        $report->refresh();

        $originals = $this->effectiveJournals(
            TransactionAccountingService::REF_SESSION_REPORT,
            (string) $report->id
        );

        $this->assertCount(2, $originals);

        $report->delete();

        $this->assertDatabaseMissing('session_reports', ['id' => $report->getKey()]);

        $this->assertSame(2, JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_SESSION_REPORT)
            ->where('reference_id', (string) $report->id)
            ->whereNotNull('reversal_of_id')
            ->count());

        foreach ($originals as $original) {
            $original->refresh();
            $this->assertSame(JournalEntry::STATUS_POSTED, $original->status);
        }

        $this->assertSame(0, $this->netCreditForCode('4000', TransactionAccountingService::REF_SESSION_REPORT, (string) $report->id));
        $this->assertCount(0, $this->effectiveJournals(TransactionAccountingService::REF_SESSION_REPORT, (string) $report->id));
    }

    public function test_import_luminash_membentuk_jurnal_rekap_harian(): void
    {
        $path = $this->storeLuminashCsv('luminash-jurnal.csv', [
            $this->luminashRow('ORD-JOURNAL-1', '05 Nov 2026 10:00', 'Complete', 'QRIS', '25000.0'),
            $this->luminashRow('ORD-JOURNAL-2', '05 Nov 2026 11:00', 'Complete', 'QRIS', '40000.0'),
            $this->luminashRow('ORD-JOURNAL-3', '05 Nov 2026 12:00', 'Incomplete', 'QRIS', '50000.0'),
            $this->luminashRow('ORD-JOURNAL-4', '05 Nov 2026 13:00', 'Complete', 'Voucher', '0.0', 'VCH-01'),
        ]);

        $batch = app(LuminashImportService::class)->import($path, 'local', $this->admin, 'luminash-jurnal.csv');

        $this->assertSame(2, $batch->imported_count);

        $journal = $this->journalByKey('luminash:rekap:2026-11-05');

        $this->assertNotNull($journal);
        $this->assertSame(JournalEntry::STATUS_POSTED, $journal->status);
        $this->assertJournalLine($journal, '1002', 65000, 0);
        $this->assertJournalLine($journal, '4000', 0, 65000);

        $this->assertSame(1, JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_LUMINASH)
            ->count());

        $this->assertSame(0, JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_LUMINASH)
            ->where('reference_id', 'ORD-JOURNAL-1')
            ->count());
    }

    public function test_import_luminash_berulang_tidak_membuat_jurnal_ganda(): void
    {
        $path = $this->storeLuminashCsv('luminash-ulang-jurnal.csv', [
            $this->luminashRow('ORD-REPEAT-1', '05 Nov 2026 10:00', 'Complete', 'QRIS', '25000.0'),
        ]);

        app(LuminashImportService::class)->import($path, 'local', $this->admin);
        app(LuminashImportService::class)->import($path, 'local', $this->admin);

        $this->assertSame(1, JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_LUMINASH)
            ->count());

        $journal = $this->journalByKey('luminash:rekap:2026-11-05');
        $this->assertNotNull($journal);
        $this->assertSame('25000.00', $journal->total_debit);
    }

    public function test_import_luminash_tambahan_pada_tanggal_sama_memperbarui_jurnal(): void
    {
        $first = $this->storeLuminashCsv('luminash-tahap-1.csv', [
            $this->luminashRow('ORD-STAGE-1', '05 Nov 2026 10:00', 'Complete', 'QRIS', '25000.0'),
        ]);

        $second = $this->storeLuminashCsv('luminash-tahap-2.csv', [
            $this->luminashRow('ORD-STAGE-2', '05 Nov 2026 11:00', 'Complete', 'QRIS', '40000.0'),
        ]);

        app(LuminashImportService::class)->import($first, 'local', $this->admin);
        app(LuminashImportService::class)->import($second, 'local', $this->admin);

        $journal = $this->journalByKey('luminash:rekap:2026-11-05');

        $this->assertNotNull($journal);
        $this->assertSame('65000.00', $journal->total_debit);
        $this->assertJournalLine($journal, '1002', 65000, 0);
        $this->assertJournalLine($journal, '4000', 0, 65000);

        $this->assertSame(1, JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_LUMINASH)
            ->whereNotNull('reversal_of_id')
            ->count());

        $this->assertSame(1, $this->effectiveJournals(TransactionAccountingService::REF_LUMINASH, null, 'luminash:rekap:2026-11-05')->count());
    }

    public function test_pengakuan_invoice_membentuk_piutang_dan_pendapatan(): void
    {
        $invoice = $this->makeInvoice(['status' => 'Belum Dibayar']);

        $journal = $this->journalByKey('invoice:issue:'.$invoice->id);

        $this->assertNotNull($journal);
        $this->assertSame('2026-11-01', $journal->journal_date->toDateString());
        $this->assertJournalLine($journal, '1100', 1000000, 0);
        $this->assertJournalLine($journal, '4000', 0, 1000000);
        $this->assertNull($this->journalByKey('invoice:dp:'.$invoice->id));
        $this->assertNull($this->journalByKey('invoice:settlement:'.$invoice->id));
    }

    public function test_pembayaran_dp_invoice_mengurangi_piutang_tanpa_mengakui_pendapatan_baru(): void
    {
        $invoice = $this->makeInvoice(['status' => 'Draft']);

        $invoice->update([
            'status' => 'Sebagian Dibayar',
            'down_payment' => 300000,
            'dp_date' => '2026-11-05',
        ]);

        $issue = $this->journalByKey('invoice:issue:'.$invoice->id);
        $dp = $this->journalByKey('invoice:dp:'.$invoice->id);

        $this->assertNotNull($issue);
        $this->assertNotNull($dp);
        $this->assertJournalLine($dp, '1001', 300000, 0);
        $this->assertJournalLine($dp, '1100', 0, 300000);
        $this->assertSame(1000000, $this->netCreditForCode('4000', TransactionAccountingService::REF_INVOICE, (string) $invoice->id));
        $this->assertSame(700000, $this->netForCodeByReference(
            '1100',
            TransactionAccountingService::REF_INVOICE,
            (string) $invoice->id
        ));
    }

    public function test_pelunasan_invoice_melunasi_sisa_piutang(): void
    {
        $invoice = $this->makeInvoice(['status' => 'Draft']);

        $invoice->update([
            'status' => 'Sebagian Dibayar',
            'down_payment' => 300000,
            'dp_date' => '2026-11-05',
        ]);

        $invoice->update(['status' => 'Lunas']);

        $settlement = $this->journalByKey('invoice:settlement:'.$invoice->id);

        $this->assertNotNull($settlement);
        $this->assertJournalLine($settlement, '1001', 700000, 0);
        $this->assertJournalLine($settlement, '1100', 0, 700000);
        $this->assertSame(0, $this->netForCodeByReference(
            '1100',
            TransactionAccountingService::REF_INVOICE,
            (string) $invoice->id
        ));
        $this->assertSame(1000000, $this->netCreditForCode('4000', TransactionAccountingService::REF_INVOICE, (string) $invoice->id));
        $this->assertSame(1000000, $this->netForCodeByReference(
            '1001',
            TransactionAccountingService::REF_INVOICE,
            (string) $invoice->id
        ));
    }

    public function test_pembayaran_invoice_parsial_didukung(): void
    {
        $invoice = $this->makeInvoice([
            'status' => 'Sebagian Dibayar',
            'down_payment' => 250000,
            'dp_date' => '2026-11-03',
        ]);

        $dp = $this->journalByKey('invoice:dp:'.$invoice->id);

        $this->assertNotNull($dp);
        $this->assertJournalLine($dp, '1001', 250000, 0);
        $this->assertJournalLine($dp, '1100', 0, 250000);
        $this->assertSame(750000, $this->netForCodeByReference(
            '1100',
            TransactionAccountingService::REF_INVOICE,
            (string) $invoice->id
        ));
        $this->assertNull($this->journalByKey('invoice:settlement:'.$invoice->id));
    }

    public function test_pembayaran_invoice_melebihi_saldo_ditolak(): void
    {
        $invoice = $this->makeInvoice(['status' => 'Draft']);

        try {
            $invoice->update([
                'status' => 'Sebagian Dibayar',
                'down_payment' => 1500000,
                'dp_date' => '2026-11-05',
            ]);

            $this->fail('DP melebihi total invoice seharusnya ditolak.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('melebihi total tagihan', $exception->getMessage());
        }

        $invoice->refresh();

        $this->assertSame('Draft', $invoice->status);
        $this->assertSame(0, JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_INVOICE)
            ->where('reference_id', (string) $invoice->id)
            ->count());
    }

    public function test_rekap_sesi_bertanda_invoice_tidak_membuat_jurnal_pendapatan_sesi(): void
    {
        $invoice = $this->makeInvoice([
            'status' => 'Draft',
            'total' => 50000,
            'subtotal' => 50000,
        ]);

        $report = $this->makeSessionReport([
            ['QRIS', 50000],
        ], invoiceId: $invoice->getKey());

        app(SessionWorkflowService::class)->approve($report, $this->admin);

        $this->assertCount(0, $this->effectiveJournals(
            TransactionAccountingService::REF_SESSION_REPORT,
            (string) $report->id
        ));

        $invoice->update(['status' => 'Belum Dibayar']);

        $issue = $this->journalByKey('invoice:issue:'.$invoice->id);
        $this->assertNotNull($issue);
        $this->assertJournalLine($issue, '1100', 50000, 0);
        $this->assertJournalLine($issue, '4000', 0, 50000);
        $this->assertSame(50000, $this->netCreditForCode('4000', TransactionAccountingService::REF_INVOICE, (string) $invoice->id));
        $this->assertSame(0, $this->netCreditForCode('4000', TransactionAccountingService::REF_SESSION_REPORT, (string) $report->id));
    }

    public function test_invoice_dari_rekap_sesi_yang_sudah_diakui_tidak_mengakui_pendapatan_ganda(): void
    {
        $report = $this->makeSessionReport([
            ['QRIS', 50000],
        ]);

        app(SessionWorkflowService::class)->approve($report, $this->admin);
        $report->refresh();

        $this->assertSame(50000, $this->netCreditForCode('4000', TransactionAccountingService::REF_SESSION_REPORT, (string) $report->id));

        $invoice = $this->makeInvoice([
            'status' => 'Draft',
            'total' => 50000,
            'subtotal' => 50000,
        ]);

        DB::table('session_reports')
            ->where('id', $report->getKey())
            ->update(['invoice_id' => $invoice->getKey()]);

        $invoice->update(['status' => 'Belum Dibayar']);

        $this->assertNull($this->journalByKey('invoice:issue:'.$invoice->id));
        $this->assertSame(50000, $this->netCreditForCode('4000', TransactionAccountingService::REF_SESSION_REPORT, (string) $report->id));
        $this->assertSame(0, $this->netCreditForCode('4000', TransactionAccountingService::REF_INVOICE, (string) $invoice->id));
    }

    public function test_transaksi_tanpa_mapping_tidak_membentuk_jurnal(): void
    {
        $category = FinancialCategory::create([
            'name' => 'Tanpa Mapping Uji',
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'is_active' => true,
        ]);

        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 10000,
            'category_id' => $category->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-08',
        ]);

        $this->assertNotNull($transaction->fresh());
        $this->assertNull($this->journalForFinancialTransaction($transaction));
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_transaksi_pada_periode_closed_tidak_diposting(): void
    {
        FiscalPeriod::create([
            'year' => 2026,
            'month' => 11,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => FiscalPeriod::STATUS_CLOSED,
        ]);

        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 100000,
            'category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-10',
        ]);

        $this->assertNotNull($transaction->fresh());
        $this->assertNull($this->journalForFinancialTransaction($transaction));
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_reversal_transaksi_pada_periode_terbuka_memakai_tanggal_asli(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 100000,
            'category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-05',
        ]);

        $original = $this->journalForFinancialTransaction($transaction);
        $this->assertNotNull($original);

        $transaction->delete();

        $reversal = JournalEntry::query()
            ->where('reversal_of_id', $original->getKey())
            ->first();

        $this->assertNotNull($reversal);
        $this->assertSame('2026-11-05', $reversal->journal_date->toDateString());
        $this->assertSame(JournalEntry::STATUS_POSTED, $reversal->status);
        $this->assertSame(JournalEntry::STATUS_POSTED, $original->fresh()->status);
        $this->assertSame(0, $this->netForCode('4000'));
    }

    public function test_reversal_transaksi_pada_periode_closed_memakai_periode_berjalan(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 100000,
            'category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-10-15',
        ]);

        $original = $this->journalForFinancialTransaction($transaction);
        $this->assertNotNull($original);

        FiscalPeriod::query()->where('year', 2026)->where('month', 10)->firstOrFail()->close();

        $transaction->delete();

        $reversal = JournalEntry::query()
            ->where('reversal_of_id', $original->getKey())
            ->first();

        $this->assertNotNull($reversal);
        $this->assertSame('2026-11-10', $reversal->journal_date->toDateString());
        $this->assertSame(
            FiscalPeriod::query()->where('year', 2026)->where('month', 11)->value('id'),
            $reversal->fiscal_period_id
        );
        $this->assertSame(0, $this->netForCode('4000'));
    }

    public function test_koreksi_transaksi_dari_periode_closed_diposting_ke_periode_berjalan(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 100000,
            'category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-10-15',
        ]);

        $first = $this->journalForFinancialTransaction($transaction);
        $this->assertNotNull($first);
        $this->assertSame('2026-10-15', $first->journal_date->toDateString());

        FiscalPeriod::query()->where('year', 2026)->where('month', 10)->firstOrFail()->close();

        $transaction->update(['amount' => 150000]);

        $correction = $this->journalForFinancialTransaction($transaction);

        $this->assertNotNull($correction);
        $this->assertSame('2026-11-10', $correction->journal_date->toDateString());
        $this->assertSame('150000.00', $correction->total_debit);

        $reversal = JournalEntry::query()->where('reversal_of_id', $first->getKey())->firstOrFail();
        $this->assertSame('2026-11-10', $reversal->journal_date->toDateString());
    }

    public function test_koreksi_transaksi_posted_membuat_reversal_dan_versi_baru(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 100000,
            'category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-05',
        ]);

        $first = $this->journalForFinancialTransaction($transaction);
        $this->assertNotNull($first);

        $transaction->update(['amount' => 150000]);

        $second = $this->journalForFinancialTransaction($transaction);
        $this->assertNotNull($second);
        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame('150000.00', $second->total_debit);
        $this->assertSame(1, JournalEntry::query()->where('reversal_of_id', $first->getKey())->count());

        $transaction->update(['amount' => 120000]);

        $third = $this->journalForFinancialTransaction($transaction);
        $this->assertNotNull($third);
        $this->assertSame('120000.00', $third->total_debit);
        $this->assertSame(2, JournalEntry::query()->whereNotNull('reversal_of_id')->count());
        $this->assertSame(1, $this->effectiveJournals(
            TransactionAccountingService::REF_FINANCIAL_TRANSACTION,
            (string) $transaction->getKey()
        )->count());
    }

    public function test_backfill_menangani_invoice_rekap_sesi_dan_luminash(): void
    {
        $invoice = Invoice::withoutEvents(fn (): Invoice => Invoice::create([
            'invoice_number' => 'INV-LEGACY-001',
            'client_name' => 'Klien Legacy',
            'event_name' => 'Event Legacy',
            'event_location' => 'Malang',
            'person_in_charge' => 'PIC Legacy',
            'event_date' => '2026-11-07',
            'event_end_date' => '2026-11-07',
            'invoice_date' => '2026-11-01',
            'due_date' => '2026-11-07',
            'subtotal' => 200000,
            'discount' => 0,
            'total' => 200000,
            'down_payment' => 0,
            'status' => 'Lunas',
        ]));

        $report = SessionReport::create([
            'report_date' => '2026-11-09',
            'cabang_id' => $this->branch->cabang_id,
            'status' => SessionReport::STATUS_APPROVED,
            'submitted_by' => $this->crew->karyawan_id,
            'total_qris_amount' => 50000,
            'grand_total_amount' => 50000,
            'total_transactions' => 1,
            'total_sessions' => 1,
        ]);

        $golio = \App\Models\Cabang::firstOrCreate(
            ['nama_cabang' => 'Golio'],
            [
                'cabang_id' => 'C9010',
                'perusahaan_id' => 'P0001',
                'alamat' => 'Golio Booth',
            ]
        );

        FinancialTransaction::withoutEvents(function () use ($invoice, $report, $golio): void {
            FinancialTransaction::create([
                'transaction_type' => FinancialTransaction::TYPE_INCOME,
                'amount' => 200000,
                'category_id' => $this->category('Pelunasan Invoice', FinancialCategory::TYPE_INCOME)->getKey(),
                'account_id' => $this->account('Photomate Express')->getKey(),
                'cabang_id' => $this->branch->cabang_id,
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                'transaction_date' => '2026-11-07',
                'description' => 'Legacy invoice',
                'source_type' => \App\Services\InvoiceFinancialService::SOURCE,
                'source_id' => (string) $invoice->getKey(),
            ]);

            FinancialTransaction::create([
                'transaction_type' => FinancialTransaction::TYPE_INCOME,
                'amount' => 50000,
                'category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
                'account_id' => $this->account('Ekspansi')->getKey(),
                'cabang_id' => $this->branch->cabang_id,
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                'transaction_date' => '2026-11-09',
                'description' => 'Legacy rekap sesi',
                'source_type' => SessionReportFinancialService::SOURCE,
                'source_id' => $report->getKey().'-QRIS',
            ]);

            FinancialTransaction::create([
                'transaction_type' => FinancialTransaction::TYPE_INCOME,
                'amount' => 30000,
                'category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
                'account_id' => $this->account('Golio')->getKey(),
                'cabang_id' => $golio->cabang_id,
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                'transaction_date' => '2026-11-08',
                'description' => 'Legacy rekap Luminash',
                'source_type' => LuminashImportService::SOURCE,
                'source_id' => 'REKAP-2026-11-08',
            ]);
        });

        $this->assertSame(0, JournalEntry::query()->count());

        $this->artisan('akuntansi:backfill-transaksi')->assertSuccessful();

        $this->assertSame(2, JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_INVOICE)
            ->where('reference_id', (string) $invoice->getKey())
            ->count());
        $this->assertSame(1, JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_SESSION_REPORT)
            ->where('reference_id', (string) $report->getKey())
            ->count());
        $this->assertSame(1, JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_LUMINASH)
            ->count());
        $this->assertSame(4, JournalEntry::query()->count());

        $this->artisan('akuntansi:backfill-transaksi')->assertSuccessful();

        $this->assertSame(4, JournalEntry::query()->count());
    }

    public function test_backfill_dry_run_dan_idempoten(): void
    {
        $existing = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 100000,
            'category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-05',
        ]);

        FinancialTransaction::withoutEvents(function (): void {
            $this->makeFinancialTransaction([
                'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
                'amount' => 50000,
                'category_id' => $this->category('Kertas', FinancialCategory::TYPE_EXPENSE)->getKey(),
                'account_id' => $this->account('Ekspansi')->getKey(),
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                'transaction_date' => '2026-11-06',
            ]);

            $unmapped = FinancialCategory::create([
                'name' => 'Tanpa Mapping Backfill',
                'transaction_type' => FinancialCategory::TYPE_EXPENSE,
                'is_active' => true,
            ]);

            $this->makeFinancialTransaction([
                'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
                'amount' => 20000,
                'category_id' => $unmapped->getKey(),
                'account_id' => $this->account('Ekspansi')->getKey(),
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                'transaction_date' => '2026-11-07',
            ]);
        });

        $this->assertNotNull($this->journalForFinancialTransaction($existing));
        $this->assertSame(1, JournalEntry::query()->count());

        $this->artisan('akuntansi:backfill-transaksi', ['--dry-run' => true])
            ->expectsOutputToContain('MODE DRY-RUN')
            ->expectsOutputToContain('Total Transaksi Tanpa Mapping Kategori')
            ->assertSuccessful();

        $this->assertSame(1, JournalEntry::query()->count());

        $this->artisan('akuntansi:backfill-transaksi')->assertSuccessful();

        $this->assertSame(2, JournalEntry::query()->count());
        $this->assertSame(1, JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_FINANCIAL_TRANSACTION)
            ->where('reference_id', (string) $existing->getKey())
            ->count());

        $this->artisan('akuntansi:backfill-transaksi')->assertSuccessful();

        $this->assertSame(2, JournalEntry::query()->count());
    }

    public function test_backfill_menghormati_filter_tanggal_dan_akun(): void
    {
        FinancialTransaction::withoutEvents(function (): void {
            $this->makeFinancialTransaction([
                'transaction_type' => FinancialTransaction::TYPE_INCOME,
                'amount' => 100000,
                'category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
                'account_id' => $this->account('Ekspansi')->getKey(),
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                'transaction_date' => '2026-11-05',
            ]);

            $this->makeFinancialTransaction([
                'transaction_type' => FinancialTransaction::TYPE_INCOME,
                'amount' => 200000,
                'category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
                'account_id' => $this->account('Photomate Express')->getKey(),
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                'transaction_date' => '2026-10-05',
            ]);
        });

        $this->artisan('akuntansi:backfill-transaksi', [
            '--from' => '2026-11-01',
            '--to' => '2026-11-30',
            '--account' => 'Ekspansi',
        ])
            ->expectsOutputToContain('Ekspansi (COA: 1004)')
            ->doesntExpectOutputToContain('Photomate Express (COA: 1001)')
            ->assertSuccessful();

        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertSame(100000, (int) round((float) JournalEntry::query()->sum('total_debit')));
    }

    public function test_invoice_dibatalkan_mereversal_jurnal(): void
    {
        $invoice = $this->makeInvoice(['status' => 'Belum Dibayar']);

        $issue = $this->journalByKey('invoice:issue:'.$invoice->id);
        $this->assertNotNull($issue);

        $invoice->update(['status' => 'Dibatalkan']);

        $reversal = JournalEntry::query()
            ->where('reversal_of_id', $issue->getKey())
            ->first();

        $this->assertNotNull($reversal);
        $this->assertSame(JournalEntry::STATUS_POSTED, $issue->fresh()->status);
        $this->assertSame(0, $this->netForCodeByReference(
            '4000',
            TransactionAccountingService::REF_INVOICE,
            (string) $invoice->id
        ));
    }

    public function test_invoice_tidak_tersimpan_saat_posting_jurnal_periode_closed(): void
    {
        FiscalPeriod::create([
            'year' => 2026,
            'month' => 11,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => FiscalPeriod::STATUS_CLOSED,
        ]);

        try {
            $this->makeInvoice([
                'status' => 'Belum Dibayar',
                'invoice_date' => '2026-11-01',
                'event_date' => '2026-11-20',
            ]);

            $this->fail('Invoice seharusnya tidak tersimpan ketika jurnal gagal diposting.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('sudah ditutup', $exception->getMessage());
        }

        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, FinancialTransaction::query()
            ->where('source_type', InvoiceFinancialService::SOURCE)
            ->count());
    }

    public function test_invoice_tidak_berubah_status_saat_akun_tidak_valid(): void
    {
        $invoice = $this->makeInvoice(['status' => 'Draft']);

        $this->accountingAccount('4000')->update(['is_active' => false]);

        try {
            $invoice->update(['status' => 'Belum Dibayar']);

            $this->fail('Perubahan status invoice seharusnya dibatalkan ketika akun jurnal tidak valid.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('tidak aktif', $exception->getMessage());
        }

        $invoice->refresh();

        $this->assertSame('Draft', $invoice->status);
        $this->assertNull($this->journalByKey('invoice:issue:'.$invoice->id));
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_pembayaran_invoice_gagal_di_tengah_tidak_mengubah_status_dan_jurnal(): void
    {
        $invoice = $this->makeInvoice([
            'status' => 'Draft',
            'total' => 1000000,
            'subtotal' => 1000000,
        ]);

        $invoice->update([
            'status' => 'Sebagian Dibayar',
            'down_payment' => 300000,
            'dp_date' => '2026-11-05',
        ]);

        $this->assertNotNull($this->journalByKey('invoice:issue:'.$invoice->id));
        $this->assertNotNull($this->journalByKey('invoice:dp:'.$invoice->id));

        $this->accountingAccount('1001')->update(['is_active' => false]);

        try {
            $invoice->update(['status' => 'Lunas']);

            $this->fail('Pelunasan seharusnya dibatalkan ketika jurnal settlement gagal.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('tidak aktif', $exception->getMessage());
        }

        $invoice->refresh();

        $this->assertSame('Sebagian Dibayar', $invoice->status);
        $this->assertSame(300000.0, (float) $invoice->down_payment);
        $this->assertNotNull($this->journalByKey('invoice:issue:'.$invoice->id));
        $this->assertNotNull($this->journalByKey('invoice:dp:'.$invoice->id));
        $this->assertNull($this->journalByKey('invoice:settlement:'.$invoice->id));
        $this->assertSame(0, FinancialTransaction::query()
            ->where('source_type', InvoiceFinancialService::SOURCE)
            ->where('source_id', (string) $invoice->id)
            ->count());
    }

    public function test_invoice_tidak_terhapus_saat_reversal_gagal(): void
    {
        $invoice = $this->makeInvoice([
            'status' => 'Belum Dibayar',
            'invoice_date' => '2026-10-15',
            'event_date' => '2026-10-20',
        ]);

        $issue = $this->journalByKey('invoice:issue:'.$invoice->id);
        $this->assertNotNull($issue);

        FiscalPeriod::query()->where('year', 2026)->where('month', 10)->firstOrFail()->close();

        FiscalPeriod::create([
            'year' => 2026,
            'month' => 11,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => FiscalPeriod::STATUS_CLOSED,
        ]);

        try {
            $invoice->delete();

            $this->fail('Invoice seharusnya tidak terhapus ketika reversal jurnal gagal.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('sudah ditutup', $exception->getMessage());
        }

        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
        $this->assertSame(JournalEntry::STATUS_POSTED, $issue->fresh()->status);
        $this->assertSame(0, JournalEntry::query()
            ->where('reversal_of_id', $issue->getKey())
            ->count());
    }

    public function test_pembatalan_invoice_setelah_dp_mereversal_semua_tahap(): void
    {
        $invoice = $this->makeInvoice([
            'status' => 'Draft',
            'total' => 1000000,
            'subtotal' => 1000000,
        ]);

        $invoice->update([
            'status' => 'Sebagian Dibayar',
            'down_payment' => 300000,
            'dp_date' => '2026-11-05',
        ]);

        $issue = $this->journalByKey('invoice:issue:'.$invoice->id);
        $dp = $this->journalByKey('invoice:dp:'.$invoice->id);

        $this->assertNotNull($issue);
        $this->assertNotNull($dp);

        $invoice->update(['status' => 'Dibatalkan']);

        $this->assertSame(1, JournalEntry::query()->where('reversal_of_id', $issue->getKey())->count());
        $this->assertSame(1, JournalEntry::query()->where('reversal_of_id', $dp->getKey())->count());
        $this->assertCount(0, $this->effectiveJournals(TransactionAccountingService::REF_INVOICE, (string) $invoice->id));
        $this->assertSame(0, $this->netForCodeByReference('4000', TransactionAccountingService::REF_INVOICE, (string) $invoice->id));
        $this->assertSame(0, $this->netForCodeByReference('1100', TransactionAccountingService::REF_INVOICE, (string) $invoice->id));
        $this->assertSame(0, $this->netForCodeByReference('1001', TransactionAccountingService::REF_INVOICE, (string) $invoice->id));
    }

    public function test_actor_posting_dan_reversal_tercatat(): void
    {
        $draft = app(JournalPostingService::class)->createDraft([
            'journal_date' => '2026-11-10',
            'description' => 'Jurnal audit actor',
            'reference_type' => 'audit_actor',
            'reference_id' => '1',
        ], [
            ['chart_of_account_id' => $this->accountingAccount('1001')->getKey(), 'debit' => 10000, 'credit' => 0],
            ['chart_of_account_id' => $this->accountingAccount('4000')->getKey(), 'debit' => 0, 'credit' => 10000],
        ], $this->admin);

        $this->assertSame($this->admin->karyawan_id, $draft->created_by);

        $posted = app(JournalPostingService::class)->post($draft, $this->admin);

        $this->assertSame($this->admin->karyawan_id, $posted->posted_by);

        $reversal = app(JournalPostingService::class)->reverse(
            $posted,
            'Reversal audit actor',
            '2026-11-10',
            $this->admin
        );

        $this->assertSame($this->admin->karyawan_id, $reversal->created_by);
        $this->assertSame($this->admin->karyawan_id, $reversal->posted_by);
    }

    public function test_user_tanpa_permission_tidak_dapat_memposting_atau_mereversal(): void
    {
        $draft = app(JournalPostingService::class)->createDraft([
            'journal_date' => '2026-11-10',
            'description' => 'Jurnal permission audit',
        ], [
            ['chart_of_account_id' => $this->accountingAccount('1001')->getKey(), 'debit' => 10000, 'credit' => 0],
            ['chart_of_account_id' => $this->accountingAccount('4000')->getKey(), 'debit' => 0, 'credit' => 10000],
        ]);

        $this->assertTrue(Gate::forUser($this->crew)->denies('post', $draft));

        $posted = app(JournalPostingService::class)->post($draft);

        $this->assertTrue(Gate::forUser($this->crew)->denies('reverse', $posted));
        $this->assertTrue(Gate::forUser($this->crew)->denies('update', $posted));
        $this->assertTrue(Gate::forUser($this->crew)->denies('delete', $posted));
        $this->assertTrue(Gate::forUser($this->crew)->denies('viewAny', JournalEntry::class));
    }

    public function test_jurnal_posted_tetap_read_only(): void
    {
        $posted = app(JournalPostingService::class)->postJournal([
            'journal_date' => '2026-11-10',
            'description' => 'Deskripsi asli',
            'idempotency_key' => 'posted-read-only',
        ], [
            ['chart_of_account_id' => $this->accountingAccount('1001')->getKey(), 'debit' => 10000, 'credit' => 0],
            ['chart_of_account_id' => $this->accountingAccount('4000')->getKey(), 'debit' => 0, 'credit' => 10000],
        ]);

        try {
            $posted->update(['description' => 'Deskripsi diubah']);

            $this->fail('Jurnal posted seharusnya tidak dapat diubah.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('tidak dapat diubah', $exception->getMessage());
        }

        try {
            $posted->delete();

            $this->fail('Jurnal posted seharusnya tidak dapat dihapus.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('tidak dapat dihapus', $exception->getMessage());
        }

        $this->assertDatabaseHas('journal_entries', [
            'id' => $posted->getKey(),
            'description' => 'Deskripsi asli',
            'status' => JournalEntry::STATUS_POSTED,
        ]);
    }

    public function test_backfill_dry_run_tidak_mengubah_database(): void
    {
        FinancialTransaction::withoutEvents(function (): void {
            $this->makeFinancialTransaction([
                'transaction_type' => FinancialTransaction::TYPE_INCOME,
                'amount' => 100000,
                'category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
                'account_id' => $this->account('Ekspansi')->getKey(),
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                'transaction_date' => '2026-11-05',
            ]);

            $this->makeFinancialTransaction([
                'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
                'amount' => 40000,
                'category_id' => $this->category('Kertas', FinancialCategory::TYPE_EXPENSE)->getKey(),
                'account_id' => $this->account('Ekspansi')->getKey(),
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                'transaction_date' => '2026-11-06',
            ]);
        });

        $snapshot = [
            'financial_transactions' => FinancialTransaction::withTrashed()->count(),
            'journal_entries' => JournalEntry::query()->count(),
            'journal_lines' => JournalLine::query()->count(),
            'fiscal_periods' => FiscalPeriod::query()->count(),
            'invoices' => Invoice::query()->count(),
            'session_reports' => SessionReport::query()->count(),
            'audit_logs' => DB::table('financial_audit_logs')->count(),
        ];

        $this->artisan('akuntansi:backfill-transaksi', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($snapshot, [
            'financial_transactions' => FinancialTransaction::withTrashed()->count(),
            'journal_entries' => JournalEntry::query()->count(),
            'journal_lines' => JournalLine::query()->count(),
            'fiscal_periods' => FiscalPeriod::query()->count(),
            'invoices' => Invoice::query()->count(),
            'session_reports' => SessionReport::query()->count(),
            'audit_logs' => DB::table('financial_audit_logs')->count(),
        ]);
    }

    public function test_backfill_dengan_jurnal_reversal_tidak_menduplikasi(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 100000,
            'category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-05',
        ]);

        $transaction->update(['amount' => 150000]);

        $journalCount = JournalEntry::query()->count();
        $this->assertSame(3, $journalCount);

        $this->artisan('akuntansi:backfill-transaksi')->assertSuccessful();

        $this->assertSame($journalCount, JournalEntry::query()->count());
        $this->assertSame(1, JournalEntry::query()->whereNotNull('reversal_of_id')->count());
        $this->assertSame(1, $this->effectiveJournals(
            TransactionAccountingService::REF_FINANCIAL_TRANSACTION,
            (string) $transaction->getKey()
        )->count());
    }

    public function test_reversal_rekap_sesi_tertaut_invoice_menjaga_total_pendapatan(): void
    {
        $invoice = $this->makeInvoice([
            'status' => 'Draft',
            'total' => 100000,
            'subtotal' => 100000,
        ]);

        $reportA = $this->makeSessionReport([['QRIS', 40000]]);
        $reportB = $this->makeSessionReport([['QRIS', 30000]]);

        app(SessionWorkflowService::class)->approve($reportA, $this->admin);
        app(SessionWorkflowService::class)->approve($reportB, $this->admin);

        DB::table('session_reports')
            ->whereIn('id', [$reportA->getKey(), $reportB->getKey()])
            ->update(['invoice_id' => $invoice->getKey()]);

        $invoice->update(['status' => 'Belum Dibayar']);

        $issue = $this->journalByKey('invoice:issue:'.$invoice->id);

        $this->assertNotNull($issue);
        $this->assertSame('30000.00', $issue->total_debit);
        $this->assertSame(-100000, $this->netForCode('4000'));

        $reportA->refresh();
        $reportA->delete();

        $this->assertDatabaseMissing('session_reports', ['id' => $reportA->getKey()]);
        $this->assertTrue(JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_SESSION_REPORT)
            ->where('reference_id', (string) $reportA->getKey())
            ->exists());

        $issueAfterDelete = $this->journalByKey('invoice:issue:'.$invoice->id);

        $this->assertNotNull($issueAfterDelete);
        $this->assertSame('70000.00', $issueAfterDelete->total_debit);
        $this->assertSame(-100000, $this->netForCode('4000'));
    }

    public function test_pemanggilan_ganda_sync_invoice_tidak_membuat_jurnal_ganda(): void
    {
        $invoice = $this->makeInvoice(['status' => 'Belum Dibayar']);

        $journalCount = JournalEntry::query()->count();

        app(TransactionAccountingService::class)->syncInvoice($invoice, $this->admin);
        app(TransactionAccountingService::class)->syncInvoice($invoice, $this->admin);

        $this->assertSame($journalCount, JournalEntry::query()->count());
        $this->assertSame(1, $this->effectiveJournals(
            TransactionAccountingService::REF_INVOICE,
            (string) $invoice->getKey()
        )->count());
    }

    public function test_idempotency_key_unik_mencegah_jurnal_ganda_di_level_database(): void
    {
        $draft = app(JournalPostingService::class)->createDraft([
            'journal_date' => '2026-11-10',
            'description' => 'Jurnal unique constraint',
            'idempotency_key' => 'unique-constraint-audit',
        ], [
            ['chart_of_account_id' => $this->accountingAccount('1001')->getKey(), 'debit' => 10000, 'credit' => 0],
            ['chart_of_account_id' => $this->accountingAccount('4000')->getKey(), 'debit' => 0, 'credit' => 10000],
        ]);

        $period = FiscalPeriod::ensureOpenForDate('2026-11-10');

        try {
            DB::table('journal_entries')->insert([
                'journal_number' => 'JE-CONFLICT-0001',
                'journal_date' => '2026-11-10',
                'description' => 'Duplikat idempotency',
                'reference_type' => null,
                'reference_id' => null,
                'status' => JournalEntry::STATUS_DRAFT,
                'fiscal_period_id' => $period->getKey(),
                'total_debit' => 10000,
                'total_credit' => 10000,
                'created_at' => now(),
                'updated_at' => now(),
                'idempotency_key' => 'unique-constraint-audit',
            ]);

            $this->fail('Unique constraint idempotency_key seharusnya menolak duplikat.');
        } catch (QueryException) {
            // Ditolak oleh constraint database.
        }

        $this->assertSame(1, JournalEntry::query()
            ->where('idempotency_key', 'unique-constraint-audit')
            ->count());
        $this->assertSame($draft->getKey(), JournalEntry::query()
            ->where('idempotency_key', 'unique-constraint-audit')
            ->value('id'));
    }

    public function test_invoice_dihapus_setelah_jurnal_posted_mereversal_dan_menyisakan_referensi(): void
    {
        $invoice = $this->makeInvoice(['status' => 'Belum Dibayar']);

        $issue = $this->journalByKey('invoice:issue:'.$invoice->id);
        $this->assertNotNull($issue);

        $invoice->delete();

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->getKey()]);
        $this->assertTrue(JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_INVOICE)
            ->where('reference_id', (string) $invoice->getKey())
            ->whereKey($issue->getKey())
            ->exists());

        $reversal = JournalEntry::query()->where('reversal_of_id', $issue->getKey())->firstOrFail();

        $this->assertSame(JournalEntry::STATUS_POSTED, $reversal->status);
        $this->assertCount(0, $this->effectiveJournals(
            TransactionAccountingService::REF_INVOICE,
            (string) $invoice->getKey()
        ));
    }

    public function test_pembatalan_invoice_setelah_lunas_mereversal_dp_dan_pelunasan(): void
    {
        $invoice = $this->makeInvoice([
            'status' => 'Draft',
            'total' => 1000000,
            'subtotal' => 1000000,
        ]);

        $invoice->update([
            'status' => 'Sebagian Dibayar',
            'down_payment' => 300000,
            'dp_date' => '2026-11-05',
        ]);

        $invoice->update(['status' => 'Lunas']);

        $issue = $this->journalByKey('invoice:issue:'.$invoice->id);
        $dp = $this->journalByKey('invoice:dp:'.$invoice->id);
        $settlement = $this->journalByKey('invoice:settlement:'.$invoice->id);

        $this->assertNotNull($issue);
        $this->assertNotNull($dp);
        $this->assertNotNull($settlement);

        $invoice->update(['status' => 'Dibatalkan']);

        foreach ([$issue, $dp, $settlement] as $journal) {
            $this->assertSame(1, JournalEntry::query()->where('reversal_of_id', $journal->getKey())->count());
        }

        $this->assertCount(0, $this->effectiveJournals(TransactionAccountingService::REF_INVOICE, (string) $invoice->id));
        $this->assertSame(0, $this->netForCodeByReference('4000', TransactionAccountingService::REF_INVOICE, (string) $invoice->id));
        $this->assertSame(0, $this->netForCodeByReference('1100', TransactionAccountingService::REF_INVOICE, (string) $invoice->id));
        $this->assertSame(0, $this->netForCodeByReference('1001', TransactionAccountingService::REF_INVOICE, (string) $invoice->id));

        $this->assertTrue(FinancialTransaction::withTrashed()
            ->where('source_type', InvoiceFinancialService::SOURCE)
            ->where('source_id', (string) $invoice->id)
            ->whereNotNull('deleted_at')
            ->exists());
    }

    public function test_reversal_invoice_dengan_beberapa_sumber_pendapatan(): void
    {
        $invoice = $this->makeInvoice([
            'status' => 'Draft',
            'total' => 100000,
            'subtotal' => 100000,
        ]);

        $reportA = $this->makeSessionReport([['QRIS', 40000]]);
        $reportB = $this->makeSessionReport([['QRIS', 30000]]);

        app(SessionWorkflowService::class)->approve($reportA, $this->admin);
        app(SessionWorkflowService::class)->approve($reportB, $this->admin);

        DB::table('session_reports')
            ->whereIn('id', [$reportA->getKey(), $reportB->getKey()])
            ->update(['invoice_id' => $invoice->getKey()]);

        $invoice->update(['status' => 'Belum Dibayar']);

        $this->assertSame('30000.00', $this->journalByKey('invoice:issue:'.$invoice->id)?->total_debit);
        $this->assertSame(-100000, $this->netForCode('4000'));

        $invoice->update(['status' => 'Dibatalkan']);

        $this->assertCount(0, $this->effectiveJournals(
            TransactionAccountingService::REF_INVOICE,
            (string) $invoice->getKey()
        ));

        // Pendapatan yang sudah diakui rekap sesi tetap menjadi sumbernya.
        $this->assertSame(-70000, $this->netForCode('4000'));
    }

    public function test_retry_setelah_unique_violation_tidak_membuat_jurnal_ganda(): void
    {
        $triggered = false;

        JournalEntry::creating(function (JournalEntry $entry) use (&$triggered): void {
            if ($triggered || $entry->idempotency_key !== 'simulated-race-key') {
                return;
            }

            $triggered = true;

            throw new QueryException(
                'sqlite',
                'insert into journal_entries',
                [],
                new \RuntimeException('UNIQUE constraint failed: journal_entries.idempotency_key')
            );
        });

        $journal = app(JournalPostingService::class)->postJournal([
            'journal_date' => '2026-11-10',
            'description' => 'Jurnal setelah unique violation',
            'reference_type' => 'race_test',
            'reference_id' => '1',
            'idempotency_key' => 'simulated-race-key',
        ], [
            ['chart_of_account_id' => $this->accountingAccount('1001')->getKey(), 'debit' => 10000, 'credit' => 0],
            ['chart_of_account_id' => $this->accountingAccount('4000')->getKey(), 'debit' => 0, 'credit' => 10000],
        ]);

        $this->assertTrue($triggered);
        $this->assertSame(JournalEntry::STATUS_POSTED, $journal->status);
        $this->assertSame(1, JournalEntry::query()->where('idempotency_key', 'simulated-race-key')->count());
        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_rekap_luminash_tidak_dapat_ditautkan_ke_invoice(): void
    {
        $golio = \App\Models\Cabang::firstOrCreate(
            ['nama_cabang' => 'Golio'],
            ['cabang_id' => 'C9010', 'perusahaan_id' => 'P0001', 'alamat' => 'Golio Booth']
        );

        $invoice = $this->makeInvoice(['status' => 'Draft']);

        try {
            SessionReport::create([
                'report_date' => '2026-11-10',
                'cabang_id' => $golio->cabang_id,
                'status' => SessionReport::STATUS_DRAFT,
                'event_note' => 'Impor Luminash CSV: uji guard',
                'invoice_id' => $invoice->getKey(),
            ]);

            $this->fail('Rekap Luminash/Golio seharusnya tidak dapat ditautkan ke invoice.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('tidak dapat ditautkan ke invoice', $exception->getMessage());
        }

        $this->assertSame(0, SessionReport::query()->where('invoice_id', $invoice->getKey())->count());
    }

    public function test_invoice_tidak_dapat_mengakui_pendapatan_rekap_luminash_yang_ditautkan_langsung(): void
    {
        $golio = \App\Models\Cabang::firstOrCreate(
            ['nama_cabang' => 'Golio'],
            ['cabang_id' => 'C9010', 'perusahaan_id' => 'P0001', 'alamat' => 'Golio Booth']
        );

        $invoice = $this->makeInvoice(['status' => 'Draft']);

        DB::table('session_reports')->insert([
            'report_number' => 'SR-GUARD-001',
            'report_date' => '2026-11-10',
            'status' => SessionReport::STATUS_APPROVED,
            'event_note' => 'Impor Luminash CSV: tautan langsung',
            'cabang_id' => $golio->cabang_id,
            'invoice_id' => $invoice->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $invoice->update(['status' => 'Belum Dibayar']);

            $this->fail('Invoice seharusnya tidak mengakui pendapatan rekap Luminash.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('rekap Luminash/Golio', $exception->getMessage());
        }

        $invoice->refresh();

        $this->assertSame('Draft', $invoice->status);
        $this->assertNull($this->journalByKey('invoice:issue:'.$invoice->id));
    }

    public function test_audit_orphan_jurnal_menemukan_jurnal_tanpa_sumber(): void
    {
        $period = FiscalPeriod::ensureOpenForDate('2026-11-10');

        DB::table('journal_entries')->insert([
            'journal_number' => 'JE-AUDIT-ORPHAN-0001',
            'journal_date' => '2026-11-10',
            'description' => 'Jurnal orphan uji',
            'reference_type' => 'financial_transaction',
            'reference_id' => '999999',
            'status' => JournalEntry::STATUS_POSTED,
            'fiscal_period_id' => $period->getKey(),
            'total_debit' => 10000,
            'total_credit' => 10000,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('akuntansi:audit-jurnal')
            ->expectsOutputToContain('Referensi financial_transaction tidak ditemukan')
            ->expectsOutputToContain('Jurnal posted tanpa baris')
            ->assertSuccessful();
    }

    public function test_audit_orphan_jurnal_bersih_pada_database_tanpa_jurnal(): void
    {
        $this->artisan('akuntansi:audit-jurnal')
            ->expectsOutputToContain('Tidak ada masalah integritas jurnal ditemukan.')
            ->assertSuccessful();
    }

    private function account(string $name): FinancialAccount
    {
        return FinancialAccount::query()->where('name', $name)->firstOrFail();
    }

    private function storeLuminashCsv(string $name, array $rows): string
    {
        $headers = [
            'Order ID', 'Timestamp', 'Booth Name', 'Payment Method', 'Payment Type', 'Amount',
            'Print Count', 'Extra Prints', 'Status', 'Frame Name', 'Voucher Code', 'Email', 'Consent', 'QR URL',
        ];

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        $path = 'luminash-imports/'.$name;
        Storage::disk('local')->put($path, $content);

        return $path;
    }

    /**
     * @return array<int, string>
     */
    private function luminashRow(
        string $orderId,
        string $timestamp,
        string $status,
        string $paymentMethod = 'QRIS',
        string $amount = '25000.0',
        string $voucherCode = '-'
    ): array {
        return [
            $orderId,
            $timestamp,
            'BOOTH_1',
            $paymentMethod,
            strtolower($paymentMethod),
            $amount,
            '1',
            '0',
            $status,
            'Golio 1',
            $voucherCode,
            '',
            'No',
            'https://media.luminash.id/session/'.$orderId,
        ];
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $transactions
     */
    private function makeSessionReport(
        array $transactions,
        string $date = '2026-11-10',
        ?int $invoiceId = null
    ): SessionReport {
        $report = SessionReport::create([
            'report_date' => $date,
            'cabang_id' => $this->branch->cabang_id,
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'submitted_by' => $this->crew->karyawan_id,
            'invoice_id' => $invoiceId,
        ]);

        foreach ($transactions as [$method, $amount]) {
            SessionTransaction::create([
                'session_report_id' => $report->getKey(),
                'payment_method' => $method,
                'session_count' => 1,
                'amount' => $amount,
            ]);
        }

        $report->crews()->attach($this->crew->karyawan_id);

        app(PricingService::class)->recalculateReport($report);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeInvoice(array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'client_name' => 'Klien Uji',
            'event_name' => 'Event Uji',
            'event_location' => 'Malang',
            'person_in_charge' => 'PIC Uji',
            'event_date' => '2026-11-20',
            'event_end_date' => '2026-11-20',
            'invoice_date' => '2026-11-01',
            'due_date' => '2026-11-15',
            'subtotal' => 1000000,
            'discount' => 0,
            'total' => 1000000,
            'down_payment' => 0,
            'status' => 'Draft',
            'notes' => null,
        ], $attributes));
    }

    private function journalForFinancialTransaction(FinancialTransaction $transaction): ?JournalEntry
    {
        return $this->effectiveJournal(
            TransactionAccountingService::REF_FINANCIAL_TRANSACTION,
            (string) $transaction->getKey()
        );
    }

    private function effectiveJournal(string $referenceType, string $referenceId): ?JournalEntry
    {
        return $this->effectiveJournals($referenceType, $referenceId)->first();
    }

    /**
     * @return Collection<int, JournalEntry>
     */
    private function effectiveJournals(
        string $referenceType,
        ?string $referenceId,
        ?string $baseKey = null
    ): Collection {
        return JournalEntry::query()
            ->with('lines.chartOfAccount')
            ->where('reference_type', $referenceType)
            ->when($referenceId !== null, fn ($query) => $query->where('reference_id', $referenceId))
            ->where('status', JournalEntry::STATUS_POSTED)
            ->whereNull('reversal_of_id')
            ->when($baseKey !== null, fn ($query) => $query->where(function ($inner) use ($baseKey): void {
                $inner->where('idempotency_key', $baseKey)
                    ->orWhere('idempotency_key', 'like', $baseKey.':v%');
            }))
            ->whereDoesntHave('reversals', fn ($query) => $query->where('status', JournalEntry::STATUS_POSTED))
            ->orderBy('id')
            ->get();
    }

    private function journalByKey(string $baseKey): ?JournalEntry
    {
        return JournalEntry::query()
            ->with('lines.chartOfAccount')
            ->where('status', JournalEntry::STATUS_POSTED)
            ->whereNull('reversal_of_id')
            ->where(function ($query) use ($baseKey): void {
                $query->where('idempotency_key', $baseKey)
                    ->orWhere('idempotency_key', 'like', $baseKey.':v%');
            })
            ->whereDoesntHave('reversals', fn ($query) => $query->where('status', JournalEntry::STATUS_POSTED))
            ->orderByDesc('id')
            ->first();
    }

    private function assertJournalLine(JournalEntry $journal, string $accountCode, int $debit, int $credit): void
    {
        $line = $journal->lines()
            ->whereHas('chartOfAccount', fn ($query) => $query->where('code', $accountCode))
            ->first();

        $this->assertNotNull($line, "Baris akun {$accountCode} tidak ditemukan pada jurnal {$journal->journal_number}.");
        $this->assertSame($debit * 100, (int) round(((float) $line->debit) * 100));
        $this->assertSame($credit * 100, (int) round(((float) $line->credit) * 100));
    }

    private function netForCode(string $code): int
    {
        return $this->netForCodeByReference($code, null, null);
    }

    private function netForCodeByReference(string $code, ?string $referenceType, ?string $referenceId): int
    {
        $accountId = $this->accountingAccount($code)->getKey();

        $query = JournalLine::query()
            ->where('chart_of_account_id', $accountId)
            ->whereHas('journalEntry', function ($inner) use ($referenceType, $referenceId): void {
                $inner->where('status', JournalEntry::STATUS_POSTED)
                    ->when($referenceType !== null, fn ($q) => $q->where('reference_type', $referenceType))
                    ->when($referenceId !== null, fn ($q) => $q->where('reference_id', $referenceId));
            });

        $debit = (int) round((float) (clone $query)->sum('debit'));
        $credit = (int) round((float) (clone $query)->sum('credit'));

        return $debit - $credit;
    }

    private function netCreditForCode(string $code, string $referenceType, string $referenceId): int
    {
        $accountId = $this->accountingAccount($code)->getKey();

        $query = JournalLine::query()
            ->where('chart_of_account_id', $accountId)
            ->whereHas('journalEntry', function ($inner) use ($referenceType, $referenceId): void {
                $inner->where('status', JournalEntry::STATUS_POSTED)
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId);
            });

        $credit = (int) round((float) (clone $query)->sum('credit'));
        $debit = (int) round((float) (clone $query)->sum('debit'));

        return $credit - $debit;
    }
}
