<?php

namespace Tests\Unit;

use App\Services\RekapPerbulanService;
use PHPUnit\Framework\TestCase;

class RekapPerbulanServiceTest extends TestCase
{
    private RekapPerbulanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RekapPerbulanService();
    }

    public function test_resolve_booth_name_maps_branches_and_accounts_correctly(): void
    {
        $this->assertSame('Janus', $this->service->resolveBoothName('Newspaper Janus', null));
        $this->assertSame('Janus', $this->service->resolveBoothName(null, 'Janus Coffee Booth'));
        $this->assertSame('Janus', $this->service->resolveBoothName('janus', 'any'));

        $this->assertSame('Golio', $this->service->resolveBoothName('Golio', null));
        $this->assertSame('Golio', $this->service->resolveBoothName(null, 'photomate golio'));

        $this->assertSame('Express', $this->service->resolveBoothName('Photomate Express', null));
        $this->assertSame('Express', $this->service->resolveBoothName('Express Self Run', null));
        $this->assertSame('Express', $this->service->resolveBoothName('Express Sewa', null));
        $this->assertSame('Express', $this->service->resolveBoothName('Express Sharing Profit', null));
        $this->assertSame('Express', $this->service->resolveBoothName(null, 'Express Account'));

        $this->assertSame('Wedding', $this->service->resolveBoothName('Wedding', null));
        $this->assertSame('Lain-lain', $this->service->resolveBoothName(null, null));
    }

    public function test_build_booth_table_computes_exact_math_and_retained_earnings_rates(): void
    {
        $incomeData = [
            'Janus' => 13965000,
            'Golio' => 8805000,
            'Express' => 14993000,
        ];

        $sessionStats = [
            'Janus' => ['lembar' => 540, 'sesi' => 377],
            'Golio' => ['lembar' => 356, 'sesi' => 303],
            'Express' => ['lembar' => 620, 'sesi' => 355],
        ];

        $expenseMatrix = [
            'hpp_by_booth' => [
                'Janus' => 4320000,
                'Golio' => 2409387,
                'Express' => 2472000,
            ],
        ];

        $result = $this->service->buildBoothTable($incomeData, $sessionStats, $expenseMatrix);
        $rows = $result['rows'];
        $total = $result['total'];

        // Janus: 13.965.000 - 4.320.000 = 9.645.000 (Laba Ditahan 25% = 2.411.250)
        $this->assertSame(13965000, $rows['Janus']['income']);
        $this->assertSame(4320000, $rows['Janus']['hpp']);
        $this->assertSame(9645000, $rows['Janus']['nett_profit']);
        $this->assertSame(30.93, $rows['Janus']['hpp_percentage']);
        $this->assertSame(69.07, $rows['Janus']['nett_profit_percentage']);
        $this->assertSame(540, $rows['Janus']['total_lembar']);
        $this->assertSame(377, $rows['Janus']['total_sesi']);
        $this->assertSame(2411250, $rows['Janus']['laba_ditahan']);

        // Golio: 8.805.000 - 2.409.387 = 6.395.613 (Laba Ditahan 25% = 1.598.903)
        $this->assertSame(8805000, $rows['Golio']['income']);
        $this->assertSame(2409387, $rows['Golio']['hpp']);
        $this->assertSame(6395613, $rows['Golio']['nett_profit']);
        $this->assertSame(27.36, $rows['Golio']['hpp_percentage']);
        $this->assertSame(72.64, $rows['Golio']['nett_profit_percentage']);
        $this->assertSame(356, $rows['Golio']['total_lembar']);
        $this->assertSame(303, $rows['Golio']['total_sesi']);
        $this->assertSame(1598903, $rows['Golio']['laba_ditahan']);

        // Express: 14.993.000 - 2.472.000 = 12.521.000 (Laba Ditahan 30% = 3.756.300)
        $this->assertSame(14993000, $rows['Express']['income']);
        $this->assertSame(2472000, $rows['Express']['hpp']);
        $this->assertSame(12521000, $rows['Express']['nett_profit']);
        $this->assertSame(16.49, $rows['Express']['hpp_percentage']);
        $this->assertSame(83.51, $rows['Express']['nett_profit_percentage']);
        $this->assertSame(620, $rows['Express']['total_lembar']);
        $this->assertSame(355, $rows['Express']['total_sesi']);
        $this->assertSame((int) round(12521000 * 0.30), $rows['Express']['laba_ditahan']);

        // TOTAL
        $this->assertSame(37763000, $total['income']);
        $this->assertSame(9201387, $total['hpp']);
        $this->assertSame(28561613, $total['nett_profit']);
        $this->assertSame(1516, $total['total_lembar']);
        $this->assertSame(1035, $total['total_sesi']);
        $this->assertSame(
            $rows['Janus']['laba_ditahan'] + $rows['Golio']['laba_ditahan'] + $rows['Express']['laba_ditahan'],
            $total['laba_ditahan']
        );
    }

    public function test_build_summary_computes_percentages_relative_to_income(): void
    {
        $income = 37763000;
        $nettProfit = 27050088;
        $gaji = 8919007;
        $labaDitahan = 7312996;
        $expenses = 46204;
        $utang = 2659250;

        $summary = $this->service->buildSummary($income, $nettProfit, $gaji, $labaDitahan, $expenses, $utang);

        $keyed = collect($summary)->keyBy('key')->all();

        $this->assertSame(100.0, $keyed['income']['persentase']);
        $this->assertSame('success', $keyed['income']['tone']);

        $this->assertSame(round((27050088 / 37763000) * 100, 2), $keyed['nett_profit']['persentase']);
        $this->assertSame('success', $keyed['nett_profit']['tone']);

        $this->assertSame(round((8919007 / 37763000) * 100, 2), $keyed['gaji']['persentase']);
        $this->assertSame('warning', $keyed['gaji']['tone']);

        $this->assertSame(round((7312996 / 37763000) * 100, 2), $keyed['laba_ditahan']['persentase']);
        $this->assertSame('warning', $keyed['laba_ditahan']['tone']);

        $this->assertSame(round((46204 / 37763000) * 100, 2), $keyed['expenses']['persentase']);
        $this->assertSame('danger', $keyed['expenses']['tone']);

        $this->assertSame(round((2659250 / 37763000) * 100, 2), $keyed['utang']['persentase']);
        $this->assertSame('warning', $keyed['utang']['tone']);
    }
}
