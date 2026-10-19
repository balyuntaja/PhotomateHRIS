<?php

namespace Tests\Feature;

use App\Filament\Pages\KeuanganReport;
use App\Filament\Resources\FinancialTransactionResource\Pages\ListFinancialTransactions;
use App\Models\Cabang;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialPaymentMethod;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Role;
use App\Models\SessionReport;
use App\Services\ExpressRekapService;
use App\Services\KeuanganService;
use App\Services\SessionReportFinancialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpressRekapTest extends TestCase
{
    use RefreshDatabase;

    protected Cabang $express;

    protected Cabang $expressSewa;

    protected Cabang $expressSelfRun;

    protected Cabang $expressSharingProfit;

    protected Cabang $janus;

    protected FinancialCategory $pendapatanBooth;

    protected FinancialPaymentMethod $qris;

    protected FinancialAccount $expressAccount;

    protected FinancialAccount $janusAccount;

    protected FinancialAccount $golioAccount;

    protected Karyawan $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        $this->express = Cabang::create([
            'cabang_id' => 'C0005',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Photomate Express',
        ]);

        $this->expressSewa = Cabang::create([
            'cabang_id' => 'C0006',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Express Sewa',
        ]);

        $this->expressSelfRun = Cabang::create([
            'cabang_id' => 'C0007',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Express Self Run',
        ]);

        $this->expressSharingProfit = Cabang::create([
            'cabang_id' => 'C0008',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Express Sharing Profit',
        ]);

        $this->janus = Cabang::create([
            'cabang_id' => 'C0004',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Newspaper Janus',
        ]);

        $this->pendapatanBooth = FinancialCategory::query()
            ->where('name', SessionReportFinancialService::CATEGORY_NAME)
            ->where('transaction_type', FinancialCategory::TYPE_INCOME)
            ->firstOrFail();

        $this->qris = FinancialPaymentMethod::where('name', 'QRIS')->firstOrFail();
        $this->expressAccount = FinancialAccount::where('name', 'Photomate Express')->firstOrFail();
        $this->janusAccount = FinancialAccount::where('name', 'Janus')->firstOrFail();
        $this->golioAccount = FinancialAccount::where('name', 'Golio')->firstOrFail();

        $role = Role::firstOrCreate(
            ['name' => 'Super Admin', 'guard_name' => 'web'],
            ['role_id' => 'R01'],
        );

        $this->admin = Karyawan::create([
            'karyawan_id' => 'K0001',
            'role_id' => $role->role_id,
            'perusahaan_id' => 'P0001',
            'nik' => '999999999999',
            'nama_lengkap' => 'Super Admin Uji',
            'email' => 'superadmin.express@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);
    }

    public function test_hanya_branch_express_sharing_profit_yang_dikenali(): void
    {
        $service = app(ExpressRekapService::class);

        $this->assertTrue($service->isExpressBranch($this->reportForBranch($this->expressSharingProfit)));
        $this->assertFalse($service->isExpressBranch($this->reportForBranch($this->express)));
        $this->assertFalse($service->isExpressBranch($this->reportForBranch($this->expressSewa)));
        $this->assertFalse($service->isExpressBranch($this->reportForBranch($this->expressSelfRun)));
        $this->assertFalse($service->isExpressBranch($this->reportForBranch($this->janus)));
    }

    public function test_label_metode_gabungan_hanya_untuk_consolidated_express(): void
    {
        $report = $this->makeReport($this->expressSharingProfit);

        $consolidated = $this->makeTransaction($report, [
            'account_id' => $this->expressAccount->id,
            'payment_method_id' => null,
            'source_id' => (string) $report->id,
        ]);

        $singleMethod = $this->makeTransaction($report, [
            'account_id' => $this->expressAccount->id,
            'payment_method_id' => $this->qris->id,
            'source_id' => $report->id . '-QRIS',
        ]);

        $sharingReport = $this->makeReport($this->expressSharingProfit);
        $sharingConsolidated = $this->makeTransaction($sharingReport, [
            'account_id' => $this->expressAccount->id,
            'payment_method_id' => null,
            'source_id' => (string) $sharingReport->id,
        ]);

        $janusReport = $this->makeReport($this->janus);
        $janusConsolidated = $this->makeTransaction($janusReport, [
            'account_id' => $this->janusAccount->id,
            'payment_method_id' => null,
            'source_id' => (string) $janusReport->id,
        ]);

        $luminashRekap = $this->makeTransaction($report, [
            'account_id' => $this->golioAccount->id,
            'payment_method_id' => null,
            'source_type' => 'luminash_csv',
            'source_id' => 'REKAP-2026-10-19',
        ]);

        $manual = $this->makeTransaction($report, [
            'account_id' => $this->expressAccount->id,
            'payment_method_id' => null,
            'source_type' => null,
            'source_id' => null,
        ]);

        $photomateReport = $this->makeReport($this->express);
        $photomateConsolidated = $this->makeTransaction($photomateReport, [
            'account_id' => $this->expressAccount->id,
            'payment_method_id' => null,
            'source_id' => (string) $photomateReport->id,
        ]);

        $this->assertSame('Gabungan', $consolidated->payment_method_label);
        $this->assertSame('Gabungan', $sharingConsolidated->payment_method_label);
        $this->assertSame('QRIS', $singleMethod->payment_method_label);
        $this->assertSame('-', $janusConsolidated->payment_method_label);
        $this->assertSame('-', $luminashRekap->payment_method_label);
        $this->assertSame('-', $manual->payment_method_label);
        $this->assertSame('-', $photomateConsolidated->payment_method_label);

        $this->assertTrue(app(ExpressRekapService::class)->isConsolidated($consolidated));
        $this->assertTrue(app(ExpressRekapService::class)->isConsolidated($sharingConsolidated));
        $this->assertFalse(app(ExpressRekapService::class)->isConsolidated($singleMethod));
        $this->assertFalse(app(ExpressRekapService::class)->isConsolidated($janusConsolidated));
    }

    public function test_detail_rows_menampilkan_tunai_dan_qris(): void
    {
        $report = $this->makeReport($this->expressSharingProfit);

        $transaction = $this->makeTransaction($report, [
            'account_id' => $this->expressAccount->id,
            'payment_method_id' => null,
            'source_id' => (string) $report->id,
        ]);

        $detail = app(ExpressRekapService::class)->detailRows($transaction);

        $this->assertSame($report->id, $detail['report']?->id);
        $this->assertSame(75000, $detail['total']);
        $this->assertCount(2, $detail['rows']);
        $this->assertSame('Tunai', $detail['rows'][0]['method']);
        $this->assertSame(30000, $detail['rows'][0]['amount']);
        $this->assertSame('QRIS', $detail['rows'][1]['method']);
        $this->assertSame(45000, $detail['rows'][1]['amount']);

        $html = view('filament.resources.financial-transaction-resource.express-rekap-detail', [
            'rekap' => $transaction,
            'report' => $detail['report'],
            'rows' => $detail['rows'],
            'total' => $detail['total'],
        ])->render();

        $this->assertStringContainsString('Tunai', $html);
        $this->assertStringContainsString('QRIS', $html);
        $this->assertStringContainsString('Rp 75.000', $html);
    }

    public function test_tabel_laporan_csv_dan_pdf_menampilkan_gabungan(): void
    {
        $report = $this->makeReport($this->expressSharingProfit);

        $transaction = $this->makeTransaction($report, [
            'account_id' => $this->expressAccount->id,
            'payment_method_id' => null,
            'source_id' => (string) $report->id,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ListFinancialTransactions::class)
            ->assertCanSeeTableRecords([$transaction])
            ->assertSee('Gabungan')
            ->assertTableActionVisible('rincian', $transaction)
            ->assertTableActionHidden('edit', $transaction);

        Livewire::test(KeuanganReport::class)
            ->assertCanSeeTableRecords([$transaction])
            ->assertSee('Gabungan');

        $headers = KeuanganService::EXPORT_HEADERS;
        $row = app(KeuanganService::class)->exportRow($transaction);

        $this->assertSame('Gabungan', $row[array_search('Metode Pembayaran', $headers, true)]);

        $period = app(KeuanganService::class)->resolvePeriod(KeuanganService::PRESET_THIS_MONTH);

        $html = view('pdf.keuangan-laporan', [
            'period' => $period,
            'accountName' => 'Photomate Express',
            'summary' => ['income' => 75000, 'expense' => 0, 'balance' => 75000],
            'transactions' => collect([$transaction]),
            'judulDokumen' => 'Laporan Keuangan Photomate',
            'periode' => $period['label'],
            'tanggalCetak' => '19 Oktober 2026',
        ])->render();

        $this->assertStringContainsString('Gabungan', $html);
    }

    protected function reportForBranch(Cabang $branch): SessionReport
    {
        $report = new SessionReport(['cabang_id' => $branch->cabang_id]);
        $report->setRelation('cabang', $branch);

        return $report;
    }

    protected function makeReport(Cabang $branch): SessionReport
    {
        return SessionReport::create([
            'report_date' => now()->toDateString(),
            'cabang_id' => $branch->cabang_id,
            'status' => SessionReport::STATUS_DRAFT,
            'total_cash_amount' => 30000,
            'total_qris_amount' => 45000,
            'total_cash_transactions' => 1,
            'total_qris_transactions' => 2,
            'total_cash_sessions' => 1,
            'total_qris_sessions' => 2,
            'grand_total_amount' => 75000,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeTransaction(SessionReport $report, array $attributes): FinancialTransaction
    {
        return FinancialTransaction::create(array_merge([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 75000,
            'category_id' => $this->pendapatanBooth->id,
            'account_id' => $this->expressAccount->id,
            'cabang_id' => $report->cabang_id,
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => $report->report_date,
            'description' => 'Rekap Sesi ' . $report->report_number . ' · ' . ($report->cabang?->nama_cabang ?? '-') . ' · Gabungan',
            'source_type' => SessionReportFinancialService::SOURCE,
            'source_id' => (string) $report->id,
        ], $attributes));
    }
}
