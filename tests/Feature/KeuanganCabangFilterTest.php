<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\Perusahaan;
use App\Services\KeuanganService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeuanganCabangFilterTest extends TestCase
{
    use RefreshDatabase;

    protected KeuanganService $service;

    protected FinancialCategory $incomeCategory;

    protected FinancialCategory $expenseCategory;

    /**
     * @var array<string, Cabang>
     */
    protected array $branches = [];

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

        foreach ([
            'C0004' => 'Newspaper Janus',
            'C0005' => 'Photomate Express',
            'C0006' => 'Express Sewa',
            'C0007' => 'Express Self Run',
            'C0008' => 'Express Sharing Profit',
            'C0009' => 'Golio',
            'C0010' => 'Wedding Photomate',
        ] as $cabangId => $namaCabang) {
            $this->branches[$namaCabang] = Cabang::create([
                'cabang_id' => $cabangId,
                'perusahaan_id' => 'P0001',
                'nama_cabang' => $namaCabang,
            ]);
        }

        $this->incomeCategory = FinancialCategory::query()
            ->where('name', 'Pelunasan')
            ->where('transaction_type', FinancialCategory::TYPE_INCOME)
            ->firstOrFail();

        $this->expenseCategory = FinancialCategory::query()
            ->where('name', 'Bensin')
            ->where('transaction_type', FinancialCategory::TYPE_EXPENSE)
            ->firstOrFail();

        $this->service = app(KeuanganService::class);
    }

    public function test_opsi_filter_cabang_hanya_menampilkan_express_golio_dan_newspaper_janus(): void
    {
        $this->assertSame([
            KeuanganService::CABANG_FILTER_EXPRESS => 'Express',
            'C0009' => 'Golio',
            'C0004' => 'Newspaper Janus',
        ], $this->service->cabangFilterOptions());
    }

    public function test_filter_express_mengakumulasi_seluruh_transaksi_cabang_express(): void
    {
        $this->makeTransaction('Express Self Run', FinancialTransaction::TYPE_INCOME, 100000);
        $this->makeTransaction('Express Sewa', FinancialTransaction::TYPE_EXPENSE, 25000);
        $this->makeTransaction('Express Sharing Profit', FinancialTransaction::TYPE_INCOME, 50000);
        $this->makeTransaction('Golio', FinancialTransaction::TYPE_INCOME, 30000);
        $this->makeTransaction('Newspaper Janus', FinancialTransaction::TYPE_INCOME, 15000);
        $this->makeTransaction('Photomate Express', FinancialTransaction::TYPE_INCOME, 5000);
        $this->makeTransaction('Wedding Photomate', FinancialTransaction::TYPE_INCOME, 1000);

        $period = $this->service->resolvePeriod(KeuanganService::PRESET_THIS_MONTH);

        $express = $this->service->summary(
            $period['start'],
            $period['end'],
            KeuanganService::CABANG_FILTER_EXPRESS
        );

        $this->assertSame(150000, $express['income']);
        $this->assertSame(25000, $express['expense']);
        $this->assertSame(125000, $express['balance']);

        $series = $this->service->chartSeries(
            $period['start'],
            $period['end'],
            KeuanganService::CABANG_FILTER_EXPRESS
        );

        $this->assertSame(150000, array_sum($series['income']));
        $this->assertSame(25000, array_sum($series['expense']));

        $latest = $this->service->latestTransactions(
            $period['start'],
            $period['end'],
            KeuanganService::CABANG_FILTER_EXPRESS,
            10
        );

        $this->assertCount(3, $latest);
        $this->assertSame(
            ['C0006', 'C0007', 'C0008'],
            $latest->pluck('cabang_id')->sort()->values()->all()
        );

        $semua = $this->service->summary($period['start'], $period['end']);

        $this->assertSame(201000, $semua['income']);
        $this->assertSame(25000, $semua['expense']);
        $this->assertCount(7, $this->service->latestTransactions($period['start'], $period['end'], null, 10));
    }

    public function test_filter_express_tetap_menghitung_data_historis_dari_cabang_yang_sudah_soft_deleted(): void
    {
        $this->makeTransaction('Express Sewa', FinancialTransaction::TYPE_INCOME, 75000);

        $this->branches['Express Sewa']->delete();

        $period = $this->service->resolvePeriod(KeuanganService::PRESET_THIS_MONTH);

        $express = $this->service->summary(
            $period['start'],
            $period['end'],
            KeuanganService::CABANG_FILTER_EXPRESS
        );

        $this->assertSame(75000, $express['income']);
        $this->assertArrayHasKey(KeuanganService::CABANG_FILTER_EXPRESS, $this->service->cabangFilterOptions());
    }

    protected function makeTransaction(string $branchName, string $type, int $amount): FinancialTransaction
    {
        return FinancialTransaction::create([
            'transaction_type' => $type,
            'amount' => $amount,
            'category_id' => $type === FinancialTransaction::TYPE_INCOME
                ? $this->incomeCategory->id
                : $this->expenseCategory->id,
            'cabang_id' => $this->branches[$branchName]->cabang_id,
            'transaction_date' => Carbon::today()->toDateString(),
            'description' => 'Transaksi uji ' . $branchName,
        ]);
    }
}
