<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\FinancialCategory;
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

        $this->oldCategory = FinancialCategory::where('name', 'Pendapatan Lain-lain')
            ->where('transaction_type', FinancialCategory::TYPE_INCOME)
            ->firstOrFail();
    }

    public function test_backfill_mencatat_rekap_approved_dan_menyamakan_kategori_transaksi_integrasi_lama(): void
    {
        $booth = $this->pendapatanBoothCategory();

        $report = $this->makeApprovedReport($this->express, cash: 30000, qris: 45000);

        $importTransaction = $this->makeLuminashTransaction('202610010001', 25000);
        $manualTransaction = $this->makeManualTransaction();
        $manualCategoryId = $manualTransaction->category_id;

        $importedReport = $this->makeApprovedReport($this->express, cash: 0, qris: 25000, orderId: '202610020002');

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
        $this->assertSame('Photomate Express', $cash->account?->name);
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
