<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\FinancialCategory;
use App\Models\FinancialPaymentMethod;
use App\Models\FinancialTransaction;
use App\Models\LuminashImportBatch;
use App\Models\LuminashImportRow;
use App\Models\Perusahaan;
use App\Models\SessionReport;
use App\Services\LuminashImportService;
use App\Services\SessionReportFinancialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeuanganBackfillRekapSesiTest extends TestCase
{
    use RefreshDatabase;

    protected Cabang $express;

    protected Cabang $regular;

    protected Cabang $expressSharing;

    protected FinancialCategory $oldCategory;

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
            'alamat' => 'Photomate Express Outlet',
        ]);

        $this->regular = Cabang::create([
            'cabang_id' => 'C0007',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Ekspansi',
            'alamat' => 'Ekspansi Outlet',
        ]);

        $this->expressSharing = Cabang::create([
            'cabang_id' => 'C0008',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Express Sharing Profit',
            'alamat' => 'Express Sharing Profit Outlet',
        ]);

        $this->oldCategory = FinancialCategory::where('name', 'Pendapatan Lain-lain')
            ->where('transaction_type', FinancialCategory::TYPE_INCOME)
            ->firstOrFail();
    }

    public function test_backfill_mencatat_rekap_approved_dan_menyamakan_kategori_transaksi_integrasi_lama(): void
    {
        $booth = $this->pendapatanBoothCategory();

        $report = $this->makeApprovedReport($this->regular, cash: 30000, qris: 45000);

        $importTransaction = $this->makeLuminashTransaction('202610010001', 25000);
        $manualTransaction = $this->makeManualTransaction();
        $manualCategoryId = $manualTransaction->category_id;

        $importedReport = $this->makeApprovedReport($this->regular, cash: 0, qris: 25000, orderId: '202610020002');

        $this->artisan('keuangan:backfill-rekap-sesi')->assertSuccessful();

        $this->assertSame($booth->id, $importTransaction->fresh()->category_id);
        $this->assertSame($manualCategoryId, $manualTransaction->fresh()->category_id);

        $cash = FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)
            ->where('source_id', $report->id . '-CASH')
            ->firstOrFail();
        $qris = FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)
            ->where('source_id', $report->id . '-QRIS')
            ->firstOrFail();

        $this->assertSame($booth->id, $cash->category_id);
        $this->assertSame(30000, $cash->amount);
        $this->assertSame('Ekspansi', $cash->account?->name);
        $this->assertSame($booth->id, $qris->category_id);
        $this->assertSame(45000, $qris->amount);

        $this->assertSame(2, FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->count());

        $this->artisan('keuangan:backfill-rekap-sesi')->assertSuccessful();

        $this->assertSame(2, FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->count());

        $this->assertSame(
            0,
            FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)
                ->where('source_id', 'like', $importedReport->id . '-%')
                ->count()
        );
    }

    public function test_dry_run_tidak_menyimpan_perubahan(): void
    {
        $report = $this->makeApprovedReport($this->express, cash: 30000, qris: 0);

        $importTransaction = $this->makeLuminashTransaction('202610010001', 25000);

        $this->artisan('keuangan:backfill-rekap-sesi', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($this->oldCategory->id, $importTransaction->fresh()->category_id);
        $this->assertSame(0, FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->count());
        $this->assertSame(SessionReport::STATUS_APPROVED, $report->fresh()->status);
    }

    public function test_backfill_gagal_bila_kategori_pendapatan_booth_belum_tersedia(): void
    {
        FinancialCategory::query()
            ->where('name', SessionReportFinancialService::CATEGORY_NAME)
            ->delete();

        $this->artisan('keuangan:backfill-rekap-sesi')->assertFailed();
    }

    public function test_cabang_option_membatasi_backfill_hanya_pada_cabang_tersebut(): void
    {
        $janus = $this->makeCabang('C0006', 'Newspaper Janus');

        $janusReport = $this->makeApprovedReport($janus, cash: 120000, qris: 0);
        $expressReport = $this->makeApprovedReport($this->express, cash: 30000, qris: 0);

        $this->artisan('keuangan:backfill-rekap-sesi', ['--cabang' => 'Newspaper Janus'])->assertSuccessful();

        $this->assertDatabaseHas('financial_transactions', [
            'source_type' => SessionReportFinancialService::SOURCE,
            'source_id' => (string) $janusReport->id,
            'amount' => 120000,
        ]);

        $this->assertDatabaseMissing('financial_transactions', [
            'source_type' => SessionReportFinancialService::SOURCE,
            'source_id' => $expressReport->id . '-CASH',
        ]);
    }

    public function test_cabang_option_menerima_cabang_id(): void
    {
        $janus = $this->makeCabang('C0006', 'Newspaper Janus');
        $report = $this->makeApprovedReport($janus, cash: 120000, qris: 0);

        $this->artisan('keuangan:backfill-rekap-sesi', ['--cabang' => 'C0006'])->assertSuccessful();

        $this->assertDatabaseHas('financial_transactions', [
            'source_type' => SessionReportFinancialService::SOURCE,
            'source_id' => (string) $report->id,
            'amount' => 120000,
        ]);
    }

    public function test_cabang_yang_tidak_dikenal_menyebabkan_kegagalan(): void
    {
        $this->makeApprovedReport($this->express, cash: 30000, qris: 0);

        $this->artisan('keuangan:backfill-rekap-sesi', ['--cabang' => 'Cabang Tidak Ada'])->assertFailed();

        $this->assertSame(0, FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->count());
    }

    public function test_rekap_tersinkron_sebagian_hanya_melengkapi_referensi_yang_hilang(): void
    {
        $report = $this->makeApprovedReport($this->regular, cash: 30000, qris: 45000);

        FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 30000,
            'category_id' => $this->pendapatanBoothCategory()->id,
            'transaction_date' => '2026-10-11',
            'source_type' => SessionReportFinancialService::SOURCE,
            'source_id' => $report->id . '-CASH',
        ]);

        $this->artisan('keuangan:backfill-rekap-sesi')->assertSuccessful();

        $this->assertSame(2, FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->count());
        $this->assertDatabaseHas('financial_transactions', [
            'source_id' => $report->id . '-QRIS',
            'amount' => 45000,
        ]);
    }

    public function test_transaksi_duplikat_dengan_referensi_berbeda_dilaporkan_tanpa_dihapus_dan_tanpa_transaksi_baru(): void
    {
        $report = $this->makeApprovedReport($this->regular, cash: 30000, qris: 0);

        $suspect = FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 30000,
            'category_id' => $this->pendapatanBoothCategory()->id,
            'transaction_date' => '2026-10-11',
            'description' => 'Rekap Sesi ' . $report->report_number . ' · ' . ($report->cabang?->nama_cabang ?? '-') . ' · Tunai',
            'source_type' => SessionReportFinancialService::SOURCE,
            'source_id' => $report->id . '-GRAND',
        ]);

        $this->artisan('keuangan:backfill-rekap-sesi')->assertSuccessful();

        $this->assertNotNull($suspect->fresh());
        $this->assertSame(1, FinancialTransaction::withTrashed()->where('source_type', SessionReportFinancialService::SOURCE)->count());
        $this->assertDatabaseMissing('financial_transactions', ['source_id' => $report->id . '-CASH']);
    }

    public function test_transaksi_yang_dihapus_tidak_dibuat_ulang_dan_dilaporkan(): void
    {
        $report = $this->makeApprovedReport($this->regular, cash: 30000, qris: 0);

        $trashed = FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 30000,
            'category_id' => $this->pendapatanBoothCategory()->id,
            'transaction_date' => '2026-10-11',
            'source_type' => SessionReportFinancialService::SOURCE,
            'source_id' => $report->id . '-CASH',
        ]);

        $trashed->delete();

        $this->artisan('keuangan:backfill-rekap-sesi')->assertSuccessful();

        $this->assertTrue($trashed->fresh()->trashed());
        $this->assertSame(1, FinancialTransaction::withTrashed()->where('source_type', SessionReportFinancialService::SOURCE)->count());
    }

    public function test_rekap_dengan_grand_total_nol_tidak_diproses(): void
    {
        SessionReport::create([
            'report_date' => '2026-10-11',
            'cabang_id' => $this->express->cabang_id,
            'status' => SessionReport::STATUS_APPROVED,
            'approved_at' => now(),
            'grand_total_amount' => 0,
        ]);

        $this->artisan('keuangan:backfill-rekap-sesi')->assertSuccessful();

        $this->assertSame(0, FinancialTransaction::count());
    }

    public function test_transaksi_lama_per_metode_digabung_menjadi_satu_transaksi_grand_total(): void
    {
        $janus = $this->makeCabang('C0006', 'Newspaper Janus');
        $report = $this->makeApprovedReport($janus, cash: 60000, qris: 60000);

        $cash = $this->makeLegacyTransaction($report, 'CASH', 60000);
        $qris = $this->makeLegacyTransaction($report, 'QRIS', 60000);

        $this->assertSame(120000, (int) FinancialTransaction::sum('amount'));

        $this->artisan('keuangan:backfill-rekap-sesi')->assertSuccessful();

        // Tidak ada penghitungan ganda: total pendapatan tetap sama.
        $this->assertSame(120000, (int) FinancialTransaction::sum('amount'));
        $this->assertSame(1, FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->count());

        $merged = FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->firstOrFail();

        $this->assertSame((string) $report->id, $merged->source_id);
        $this->assertSame(120000, $merged->amount);
        $this->assertSame('Rekap Sesi ' . $report->report_number . ' · Newspaper Janus', $merged->description);
        $this->assertNull($merged->payment_method_id);
        $this->assertSame('Janus', $merged->account?->name);
        $this->assertSame(60000, $qris->fresh()->amount);

        // Baris kedua tetap tersimpan (soft delete) sebagai jejak audit.
        $this->assertFalse($cash->fresh()->trashed());
        $this->assertTrue($qris->fresh()->trashed());
        $this->assertSame($report->id . '-QRIS', $qris->fresh()->source_id);

        // Idempoten: dijalankan ulang tidak mengubah apa pun lagi.
        $this->artisan('keuangan:backfill-rekap-sesi')->assertSuccessful();

        $this->assertSame(1, FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->count());
        $this->assertSame(120000, (int) FinancialTransaction::sum('amount'));
    }

    public function test_transaksi_lama_express_cash_dan_qris_digabung_menjadi_satu_transaksi(): void
    {
        $report = $this->makeApprovedReport($this->expressSharing, cash: 60000, qris: 60000);

        $cash = $this->makeLegacyTransaction($report, 'CASH', 60000);
        $qris = $this->makeLegacyTransaction($report, 'QRIS', 60000);

        $this->assertSame(120000, (int) FinancialTransaction::sum('amount'));

        $this->artisan('keuangan:backfill-rekap-sesi')->assertSuccessful();

        $this->assertSame(120000, (int) FinancialTransaction::sum('amount'));
        $this->assertSame(1, FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->count());

        $merged = FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->firstOrFail();

        $this->assertSame((string) $report->id, $merged->source_id);
        $this->assertSame(120000, $merged->amount);
        $this->assertSame('Rekap Sesi ' . $report->report_number . ' · Express Sharing Profit · Gabungan', $merged->description);
        $this->assertNull($merged->payment_method_id);
        $this->assertSame('Photomate Express', $merged->account?->name);
        $this->assertSame($this->pendapatanBoothCategory()->id, $merged->category_id);

        // Baris pertama dipertahankan dan diubah menjadi consolidated; baris kedua soft delete.
        $this->assertFalse($cash->fresh()->trashed());
        $this->assertTrue($qris->fresh()->trashed());

        // Idempoten.
        $this->artisan('keuangan:backfill-rekap-sesi')->assertSuccessful();

        $this->assertSame(1, FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->count());
        $this->assertSame(120000, (int) FinancialTransaction::sum('amount'));
    }

    public function test_transaksi_lama_express_dengan_kategori_berbeda_tidak_digabung(): void
    {
        $report = $this->makeApprovedReport($this->expressSharing, cash: 60000, qris: 60000);

        $cash = $this->makeLegacyTransaction($report, 'CASH', 60000);
        $qris = $this->makeLegacyTransaction($report, 'QRIS', 60000);

        $qris->forceFill(['category_id' => $this->oldCategory->id])->saveQuietly();

        $this->artisan('keuangan:backfill-rekap-sesi', ['--dry-run' => true])->assertSuccessful();

        // Dry-run tidak menormalkan kategori, sehingga penggabungan ditolak.
        $this->assertSame($report->id . '-CASH', $cash->fresh()->source_id);
        $this->assertSame($report->id . '-QRIS', $qris->fresh()->source_id);
        $this->assertSame(2, FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->count());
    }

    public function test_transaksi_lama_express_dengan_tanggal_berbeda_tidak_digabung(): void
    {
        $report = $this->makeApprovedReport($this->expressSharing, cash: 60000, qris: 60000);

        $cash = $this->makeLegacyTransaction($report, 'CASH', 60000);
        $qris = $this->makeLegacyTransaction($report, 'QRIS', 60000);

        $qris->forceFill(['transaction_date' => '2026-10-12'])->saveQuietly();

        $this->artisan('keuangan:backfill-rekap-sesi', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($report->id . '-CASH', $cash->fresh()->source_id);
        $this->assertSame($report->id . '-QRIS', $qris->fresh()->source_id);
        $this->assertSame(2, FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->count());
    }

    public function test_transaksi_lama_express_dengan_akun_berbeda_tidak_digabung(): void
    {
        $report = $this->makeApprovedReport($this->expressSharing, cash: 60000, qris: 60000);

        $cash = $this->makeLegacyTransaction($report, 'CASH', 60000);
        $qris = $this->makeLegacyTransaction($report, 'QRIS', 60000);

        $qris->forceFill([
            'account_id' => \App\Models\FinancialAccount::where('name', 'Janus')->value('id'),
        ])->saveQuietly();

        $this->artisan('keuangan:backfill-rekap-sesi', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($report->id . '-CASH', $cash->fresh()->source_id);
        $this->assertSame($report->id . '-QRIS', $qris->fresh()->source_id);
        $this->assertSame(2, FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->count());
    }

    public function test_backfill_express_sharing_profit_memakai_nama_cabang_langsung(): void
    {
        $report = $this->makeApprovedReport($this->expressSharing, cash: 60000, qris: 60000);

        $cash = $this->makeLegacyTransaction($report, 'CASH', 60000);
        $qris = $this->makeLegacyTransaction($report, 'QRIS', 60000);

        $this->artisan('keuangan:backfill-rekap-sesi', ['--cabang' => 'Express Sharing Profit'])->assertSuccessful();

        $this->assertSame(1, FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->count());

        $merged = FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->firstOrFail();

        $this->assertSame((string) $report->id, $merged->source_id);
        $this->assertSame(120000, $merged->amount);
        $this->assertSame('Express Sharing Profit', $merged->cabang?->nama_cabang);
        $this->assertSame('Photomate Express', $merged->account?->name);
        $this->assertNull($merged->payment_method_id);
        $this->assertStringContainsString('Gabungan', (string) $merged->description);
    }

    public function test_transaksi_lama_express_satu_metode_tidak_digabung(): void
    {
        $report = $this->makeApprovedReport($this->expressSharing, cash: 0, qris: 25000);

        $qris = $this->makeLegacyTransaction($report, 'QRIS', 25000);

        $this->artisan('keuangan:backfill-rekap-sesi')->assertSuccessful();

        $qris->refresh();

        $this->assertSame($report->id . '-QRIS', $qris->source_id);
        $this->assertSame(25000, $qris->amount);
        $this->assertSame('QRIS', $qris->paymentMethod?->name);
        $this->assertSame(1, FinancialTransaction::count());
    }

    public function test_dry_run_tidak_menggabungkan_transaksi_lama_express(): void
    {
        $report = $this->makeApprovedReport($this->expressSharing, cash: 60000, qris: 60000);

        $cash = $this->makeLegacyTransaction($report, 'CASH', 60000);
        $qris = $this->makeLegacyTransaction($report, 'QRIS', 60000);

        $this->artisan('keuangan:backfill-rekap-sesi', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($report->id . '-CASH', $cash->fresh()->source_id);
        $this->assertSame($report->id . '-QRIS', $qris->fresh()->source_id);
        $this->assertSame(2, FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->count());
        $this->assertSame(120000, (int) FinancialTransaction::sum('amount'));
    }

    public function test_transaksi_lama_tunggal_yang_sudah_sesuai_grand_total_hanya_dinormalkan(): void
    {
        $janus = $this->makeCabang('C0006', 'Newspaper Janus');
        $report = $this->makeApprovedReport($janus, cash: 120000, qris: 0);

        $legacy = $this->makeLegacyTransaction($report, 'CASH', 120000);

        $this->artisan('keuangan:backfill-rekap-sesi')->assertSuccessful();

        $legacy->refresh();

        $this->assertSame((string) $report->id, $legacy->source_id);
        $this->assertSame(120000, $legacy->amount);
        $this->assertSame('Rekap Sesi ' . $report->report_number . ' · Newspaper Janus', $legacy->description);
        $this->assertNull($legacy->payment_method_id);
        $this->assertSame(1, FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->count());
        $this->assertSame(120000, (int) FinancialTransaction::sum('amount'));
    }

    public function test_transaksi_lama_yang_totalnya_tidak_sesuai_grand_total_hanya_dilaporkan(): void
    {
        $janus = $this->makeCabang('C0006', 'Newspaper Janus');
        $report = $this->makeApprovedReport($janus, cash: 60000, qris: 60000);

        $legacy = $this->makeLegacyTransaction($report, 'CASH', 60000);

        $this->artisan('keuangan:backfill-rekap-sesi')->assertSuccessful();

        $legacy->refresh();

        $this->assertSame($report->id . '-CASH', $legacy->source_id);
        $this->assertSame(60000, $legacy->amount);
        $this->assertSame('Rekap Sesi ' . $report->report_number . ' · Newspaper Janus · Tunai', $legacy->description);
        $this->assertSame(1, FinancialTransaction::count());
        $this->assertSame(60000, (int) FinancialTransaction::sum('amount'));
    }

    public function test_transaksi_lama_cabang_lain_tidak_digabung(): void
    {
        $report = $this->makeApprovedReport($this->regular, cash: 60000, qris: 60000);

        $cash = $this->makeLegacyTransaction($report, 'CASH', 60000);
        $qris = $this->makeLegacyTransaction($report, 'QRIS', 60000);

        $this->artisan('keuangan:backfill-rekap-sesi')->assertSuccessful();

        $this->assertSame(2, FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->count());
        $this->assertSame($report->id . '-CASH', $cash->fresh()->source_id);
        $this->assertSame(60000, $cash->fresh()->amount);
        $this->assertSame($report->id . '-QRIS', $qris->fresh()->source_id);
        $this->assertSame(60000, $qris->fresh()->amount);
    }

    public function test_transaksi_manual_dengan_nominal_dan_tanggal_sama_tidak_ikut_digabung(): void
    {
        $janus = $this->makeCabang('C0006', 'Newspaper Janus');
        $report = $this->makeApprovedReport($janus, cash: 60000, qris: 60000);

        $manual = FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 60000,
            'category_id' => $this->pendapatanBoothCategory()->id,
            'transaction_date' => '2026-10-11',
            'description' => 'Rekap Sesi ' . $report->report_number . ' · Newspaper Janus · Tunai',
        ]);

        $this->artisan('keuangan:backfill-rekap-sesi')->assertSuccessful();

        $manual->refresh();

        $this->assertNull($manual->source_type);
        $this->assertNull($manual->source_id);
        $this->assertSame(60000, $manual->amount);

        $merged = FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->firstOrFail();

        $this->assertSame((string) $report->id, $merged->source_id);
        $this->assertSame(120000, $merged->amount);
    }

    public function test_dry_run_tidak_menggabungkan_transaksi_lama(): void
    {
        $janus = $this->makeCabang('C0006', 'Newspaper Janus');
        $report = $this->makeApprovedReport($janus, cash: 60000, qris: 60000);

        $this->makeLegacyTransaction($report, 'CASH', 60000);
        $this->makeLegacyTransaction($report, 'QRIS', 60000);

        $this->artisan('keuangan:backfill-rekap-sesi', ['--dry-run' => true])->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [$report->id . '-CASH', $report->id . '-QRIS'],
            FinancialTransaction::where('source_type', SessionReportFinancialService::SOURCE)->pluck('source_id')->all()
        );
        $this->assertSame(120000, (int) FinancialTransaction::sum('amount'));
    }

    protected function makeCabang(string $cabangId, string $nama): Cabang
    {
        return Cabang::create([
            'cabang_id' => $cabangId,
            'perusahaan_id' => 'P0001',
            'nama_cabang' => $nama,
            'alamat' => $nama . ' Outlet',
        ]);
    }

    protected function makeApprovedReport(Cabang $cabang, int $cash, int $qris, ?string $orderId = null): SessionReport
    {
        $report = SessionReport::create([
            'report_date' => '2026-10-11',
            'cabang_id' => $cabang->cabang_id,
            'status' => SessionReport::STATUS_APPROVED,
            'approved_at' => now(),
            'total_cash_amount' => $cash,
            'total_qris_amount' => $qris,
            'grand_total_amount' => $cash + $qris,
        ]);

        if ($orderId !== null) {
            $batch = LuminashImportBatch::create([
                'file_name' => 'lama.csv',
                'cabang_id' => $cabang->cabang_id,
                'total_rows' => 1,
            ]);

            LuminashImportRow::create([
                'batch_id' => $batch->id,
                'order_id' => $orderId,
                'result' => LuminashImportRow::RESULT_IMPORTED,
                'session_report_id' => $report->id,
            ]);
        }

        return $report;
    }

    protected function makeLegacyTransaction(SessionReport $report, string $method, int $amount): FinancialTransaction
    {
        $isCash = $method === 'CASH';

        return FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => $amount,
            'category_id' => $this->pendapatanBoothCategory()->id,
            'payment_method_id' => FinancialPaymentMethod::query()
                ->where('name', $isCash ? 'Cash' : 'QRIS')
                ->value('id'),
            'cabang_id' => $report->cabang_id,
            'transaction_date' => '2026-10-11',
            'description' => 'Rekap Sesi ' . $report->report_number . ' · ' . ($report->cabang?->nama_cabang ?? '-') . ' · ' . ($isCash ? 'Tunai' : 'QRIS'),
            'source_type' => SessionReportFinancialService::SOURCE,
            'source_id' => $report->id . '-' . $method,
        ]);
    }

    protected function makeLuminashTransaction(string $orderId, int $amount): FinancialTransaction
    {
        return FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => $amount,
            'category_id' => $this->oldCategory->id,
            'transaction_date' => '2026-10-05',
            'source_type' => LuminashImportService::SOURCE,
            'source_id' => $orderId,
        ]);
    }

    protected function makeManualTransaction(): FinancialTransaction
    {
        $expenseCategory = FinancialCategory::where('name', 'Tinta')
            ->where('transaction_type', FinancialCategory::TYPE_EXPENSE)
            ->firstOrFail();

        return FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 5000,
            'category_id' => $expenseCategory->id,
            'transaction_date' => '2026-10-05',
        ]);
    }

    protected function pendapatanBoothCategory(): FinancialCategory
    {
        return FinancialCategory::query()
            ->where('name', SessionReportFinancialService::CATEGORY_NAME)
            ->where('transaction_type', FinancialCategory::TYPE_INCOME)
            ->firstOrFail();
    }
}
