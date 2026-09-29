<?php

namespace Tests\Unit;

use App\Models\Cabang;
use App\Models\FinancialCategory;
use App\Models\FinancialPaymentMethod;
use App\Models\FinancialTransaction;
use App\Models\Perusahaan;
use App\Services\KeuanganService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeuanganServiceTest extends TestCase
{
    use RefreshDatabase;

    protected KeuanganService $service;

    protected FinancialCategory $incomeCategory;

    protected FinancialCategory $tintaCategory;

    protected FinancialCategory $bensinCategory;

    protected FinancialPaymentMethod $paymentMethod;

    protected Cabang $express;

    protected Cabang $janus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(KeuanganService::class);

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

        $this->janus = Cabang::create([
            'cabang_id' => 'C0004',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Newspaper Janus',
        ]);

        // Kategori & metode di bawah ini berasal dari migration master data keuangan.
        $this->incomeCategory = FinancialCategory::where('name', 'Pelunasan')
            ->where('transaction_type', FinancialCategory::TYPE_INCOME)
            ->firstOrFail();

        $this->tintaCategory = FinancialCategory::where('name', 'Tinta')
            ->where('transaction_type', FinancialCategory::TYPE_EXPENSE)
            ->firstOrFail();

        $this->bensinCategory = FinancialCategory::where('name', 'Bensin')
            ->where('transaction_type', FinancialCategory::TYPE_EXPENSE)
            ->firstOrFail();

        $this->paymentMethod = FinancialPaymentMethod::where('name', 'Transfer')->firstOrFail();
    }

    public function test_master_data_keuangan_terpasang_dari_migration(): void
    {
        $this->assertSame(8, FinancialCategory::where('transaction_type', FinancialCategory::TYPE_INCOME)->count());
        $this->assertGreaterThanOrEqual(20, FinancialCategory::where('transaction_type', FinancialCategory::TYPE_EXPENSE)->count());
        $this->assertGreaterThanOrEqual(6, FinancialPaymentMethod::count());

        $this->assertDatabaseHas('financial_categories', [
            'name' => 'Tinta',
            'group_name' => 'Operasional',
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('financial_payment_methods', ['name' => 'QRIS', 'is_active' => true]);
    }

    public function test_summary_menghitung_pemasukan_pengeluaran_dan_saldo(): void
    {
        $this->seedContohTransaksi();

        $period = $this->service->resolvePeriod(null);
        $summary = $this->service->summary($period['start'], $period['end']);

        $this->assertSame(2000000, $summary['income']);
        $this->assertSame(481550, $summary['expense']);
        $this->assertSame(1518450, $summary['balance']);
    }

    public function test_summary_hanya_menghitung_cabang_terpilih(): void
    {
        $this->seedContohTransaksi();

        $period = $this->service->resolvePeriod(null);

        $express = $this->service->summary($period['start'], $period['end'], 'C0005');
        $this->assertSame(2000000, $express['income']);
        $this->assertSame(100000, $express['expense']);
        $this->assertSame(1900000, $express['balance']);

        $janus = $this->service->summary($period['start'], $period['end'], 'C0004');
        $this->assertSame(0, $janus['income']);
        $this->assertSame(381550, $janus['expense']);
        $this->assertSame(-381550, $janus['balance']);
    }

    public function test_transaksi_terhapus_tidak_dihitung_dalam_laporan(): void
    {
        $this->seedContohTransaksi();

        FinancialTransaction::where('amount', 381550)->firstOrFail()->delete();

        $period = $this->service->resolvePeriod(null);
        $summary = $this->service->summary($period['start'], $period['end']);

        $this->assertSame(100000, $summary['expense']);
        $this->assertSame(1900000, $summary['balance']);
    }

    public function test_chart_series_mengembalikan_bucket_harian(): void
    {
        $this->seedContohTransaksi();

        $period = $this->service->resolvePeriod(null);
        $series = $this->service->chartSeries($period['start'], $period['end']);

        $this->assertCount((int) Carbon::today()->daysInMonth, $series['labels']);
        $this->assertSame(2000000, array_sum($series['income']));
        $this->assertSame(481550, array_sum($series['expense']));

        $todayIndex = array_search(Carbon::today()->format('d/m'), $series['labels'], true);
        $this->assertNotFalse($todayIndex);
        $this->assertSame(2000000, $series['income'][$todayIndex]);
    }

    public function test_category_breakdown_mengelompokkan_pengeluaran_per_grup(): void
    {
        $this->seedContohTransaksi();

        $period = $this->service->resolvePeriod(null);
        $breakdown = $this->service->categoryBreakdown($period['start'], $period['end']);

        $this->assertSame(481550, $breakdown[FinancialTransaction::TYPE_EXPENSE]['total']);
        $this->assertSame(2000000, $breakdown[FinancialTransaction::TYPE_INCOME]['total']);

        // Pemasukan tidak dikelompokkan (group_name null).
        $incomeGroups = $breakdown[FinancialTransaction::TYPE_INCOME]['groups'];
        $this->assertCount(1, $incomeGroups);
        $this->assertNull($incomeGroups[0]['label']);
        $this->assertSame('Pelunasan', $incomeGroups[0]['items'][0]['name']);

        $expenseGroups = $breakdown[FinancialTransaction::TYPE_EXPENSE]['groups'];
        $this->assertCount(1, $expenseGroups);
        $this->assertSame('Operasional', $expenseGroups[0]['label']);
        $this->assertSame(481550, $expenseGroups[0]['total']);
        $this->assertSame(100.0, $expenseGroups[0]['percentage']);

        // Item diurutkan dari nominal terbesar.
        $this->assertSame('Tinta', $expenseGroups[0]['items'][0]['name']);
        $this->assertSame(381550, $expenseGroups[0]['items'][0]['total']);
        $this->assertSame('Bensin', $expenseGroups[0]['items'][1]['name']);
    }

    public function test_branch_breakdown_menghitung_selisih_kas(): void
    {
        $this->seedContohTransaksi();

        $period = $this->service->resolvePeriod(null);
        $branches = collect($this->service->branchBreakdown($period['start'], $period['end']))
            ->keyBy('branch');

        $this->assertSame(2000000, $branches['Photomate Express']['income']);
        $this->assertSame(100000, $branches['Photomate Express']['expense']);
        $this->assertSame(1900000, $branches['Photomate Express']['net']);

        $this->assertSame(381550, $branches['Newspaper Janus']['expense']);
        $this->assertSame(-381550, $branches['Newspaper Janus']['net']);
    }

    public function test_latest_transactions_diurutkan_dari_tanggal_terbaru(): void
    {
        $this->seedContohTransaksi();

        $period = $this->service->resolvePeriod(null);
        $latest = $this->service->latestTransactions($period['start'], $period['end'], null, 2);

        $this->assertCount(2, $latest);
        $this->assertTrue($latest->first()->transaction_date->greaterThanOrEqualTo($latest->last()->transaction_date));
    }

    public function test_resolve_period_presets(): void
    {
        $today = Carbon::today();

        $period = $this->service->resolvePeriod(KeuanganService::PRESET_TODAY);
        $this->assertTrue($period['start']->isSameDay($today));
        $this->assertTrue($period['end']->isSameDay($today));

        $period = $this->service->resolvePeriod(null);
        $this->assertTrue($period['start']->isSameDay($today->copy()->startOfMonth()));
        $this->assertTrue($period['end']->isSameDay($today->copy()->endOfMonth()));

        $period = $this->service->resolvePeriod(KeuanganService::PRESET_LAST_MONTH);
        $this->assertTrue($period['start']->isSameDay($today->copy()->subMonthNoOverflow()->startOfMonth()));
        $this->assertTrue($period['end']->isSameDay($today->copy()->subMonthNoOverflow()->endOfMonth()));

        $period = $this->service->resolvePeriod(KeuanganService::PRESET_CUSTOM, '2026-09-30', '2026-09-01');
        $this->assertSame('2026-09-01', $period['start']->toDateString());
        $this->assertSame('2026-09-30', $period['end']->toDateString());
        $this->assertSame('September 2026', $period['label']);

        $period = $this->service->resolvePeriod(KeuanganService::PRESET_CUSTOM, '2026-09-28', '2026-10-03');
        $this->assertSame('28/09/2026 - 03/10/2026', $period['label']);
    }

    protected function seedContohTransaksi(): void
    {
        $today = Carbon::today()->toDateString();

        $this->makeTransaction(FinancialTransaction::TYPE_INCOME, 2000000, $this->incomeCategory->id, 'C0005', $today);
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 381550, $this->tintaCategory->id, 'C0004', $today);
        $this->makeTransaction(FinancialTransaction::TYPE_EXPENSE, 100000, $this->bensinCategory->id, 'C0005', $today);
    }

    protected function makeTransaction(
        string $type,
        int $amount,
        int $categoryId,
        ?string $cabangId,
        string $date
    ): FinancialTransaction {
        return FinancialTransaction::create([
            'transaction_type' => $type,
            'amount' => $amount,
            'category_id' => $categoryId,
            'cabang_id' => $cabangId,
            'payment_method_id' => $this->paymentMethod->id,
            'transaction_date' => $date,
            'description' => 'Transaksi uji',
        ]);
    }
}
