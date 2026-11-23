<?php

namespace App\Services;

use App\Models\Cabang;
use App\Models\FinancialParentCategory;
use App\Models\FinancialTransaction;
use App\Models\SessionReport;
use App\Utils\MonthHelper;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RekapPerbulanService
{
    public const BOOTH_JANUS = 'Janus';
    public const BOOTH_GOLIO = 'Golio';
    public const BOOTH_EXPRESS = 'Express';

    public const DEFAULT_BOOTHS = [
        self::BOOTH_JANUS,
        self::BOOTH_GOLIO,
        self::BOOTH_EXPRESS,
    ];

    /**
     * Kategori pengeluaran yang secara akuntansi masuk ke HPP (Biaya Pokok).
     * Jika tidak ditemukan kategori spesifik, beban langsung cabang dihitung sebagai biaya booth.
     */
    public const HPP_PARENT_KEYWORDS = [
        'persediaan',
        'box',
        'hpp',
        'pokok',
        'bahan',
        'produksi',
        'tenant',
        'sharprof',
    ];

    /**
     * Ambil data lengkap Rekap Perbulan untuk bulan dan tahun yang dipilih.
     *
     * @return array<string, mixed>
     */
    public function getReportData(int $year, int $month): array
    {
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $monthName = MonthHelper::getMonthName($month);
        $periodLabel = MonthHelper::formatPeriod($month, $year);

        // 1. Data Pendapatan Booth per Cabang
        $incomeData = $this->getBoothIncome($start, $end);

        // 2. Data Lembar & Sesi dari Rekap Sesi Approved
        $sessionStats = $this->getSessionStats($start, $end);

        // 3. Data Pengeluaran per Kategori Utama dan Cabang (Matrix Expenses)
        $expenseMatrix = $this->getExpenseMatrix($start, $end);

        // 4. Hitung HPP, Nett Profit, Laba Ditahan per Booth
        $boothTable = $this->buildBoothTable($incomeData, $sessionStats, $expenseMatrix);

        // 5. Data Tabel Utang
        $utangTable = $this->getUtangTable($start, $end);

        // 6. Summary Keuangan Bulanan
        $summary = $this->buildSummary(
            $boothTable['total']['income'],
            $boothTable['total']['nett_profit'],
            $expenseMatrix['total_gaji'],
            $boothTable['total']['laba_ditahan'],
            $expenseMatrix['operational_expenses'],
            $utangTable['total_perbulan']
        );

        // 7. Visualisasi Charts (Donut & 6-Month Trend)
        $donutChart = $this->buildDonutChartData($boothTable['rows'], $boothTable['total']['income']);
        $trendChart = $this->buildTrendChartData($year, $month);

        return [
            'period' => [
                'year' => $year,
                'month' => $month,
                'month_name' => $monthName,
                'label' => $periodLabel,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ],
            'summary' => $summary,
            'booth_table' => $boothTable,
            'utang_table' => $utangTable,
            'expense_matrix' => $expenseMatrix,
            'donut_chart' => $donutChart,
            'trend_chart' => $trendChart,
        ];
    }

    /**
     * Petakan nama cabang dan nama akun keuangan ke salah satu booth utama: Janus, Golio, Express, atau lainnya.
     */
    public function resolveBoothName(?string $cabangName, ?string $accountName = null): string
    {
        $cabangLower = strtolower(trim((string) $cabangName));
        $accountLower = strtolower(trim((string) $accountName));

        if (str_contains($cabangLower, 'janus') || str_contains($accountLower, 'janus')) {
            return self::BOOTH_JANUS;
        }

        if (str_contains($cabangLower, 'golio') || str_contains($accountLower, 'golio')) {
            return self::BOOTH_GOLIO;
        }

        if (str_contains($cabangLower, 'express') || str_contains($accountLower, 'express')) {
            return self::BOOTH_EXPRESS;
        }

        return filled($cabangName) ? trim((string) $cabangName) : 'Lain-lain';
    }

    /**
     * Ambil pendapatan booth untuk setiap cabang dalam rentang tanggal.
     *
     * @return array<string, int>
     */
    public function getBoothIncome(Carbon $start, Carbon $end): array
    {
        $rows = FinancialTransaction::query()
            ->boothIncome()
            ->whereBetween('financial_transactions.transaction_date', [$start->toDateString(), $end->toDateString()])
            ->leftJoin('cabang', 'cabang.cabang_id', '=', 'financial_transactions.cabang_id')
            ->leftJoin('financial_accounts', 'financial_accounts.id', '=', 'financial_transactions.account_id')
            ->selectRaw('
                financial_transactions.amount,
                cabang.nama_cabang as branch_name,
                financial_accounts.name as account_name
            ')
            ->get();

        $incomes = [
            self::BOOTH_JANUS => 0,
            self::BOOTH_GOLIO => 0,
            self::BOOTH_EXPRESS => 0,
        ];

        foreach ($rows as $row) {
            $booth = $this->resolveBoothName($row->branch_name, $row->account_name);
            $incomes[$booth] = ($incomes[$booth] ?? 0) + (int) $row->amount;
        }

        return $incomes;
    }

    /**
     * Hitung total lembar dan sesi dari Rekap Sesi yang sudah berstatus Approved.
     *
     * @return array<string, array{lembar: int, sesi: int}>
     */
    public function getSessionStats(Carbon $start, Carbon $end): array
    {
        $reports = SessionReport::query()
            ->where('status', SessionReport::STATUS_APPROVED)
            ->whereBetween('report_date', [$start->toDateString(), $end->toDateString()])
            ->with(['cabang', 'transactions'])
            ->get();

        $inputModeService = app(BranchInputModeService::class);

        $stats = [
            self::BOOTH_JANUS => ['lembar' => 0, 'sesi' => 0],
            self::BOOTH_GOLIO => ['lembar' => 0, 'sesi' => 0],
            self::BOOTH_EXPRESS => ['lembar' => 0, 'sesi' => 0],
        ];

        foreach ($reports as $report) {
            $booth = $this->resolveBoothName($report->cabang?->nama_cabang);

            if (! isset($stats[$booth])) {
                $stats[$booth] = ['lembar' => 0, 'sesi' => 0];
            }

            if ($report->cabang && $inputModeService->usesManualSessionInput($report->cabang)) {
                $sesi = (int) ($report->wedding_total_sessions ?: $report->total_transactions ?: $report->total_sessions);
                $lembar = (int) ($report->wedding_total_sheets ?: $report->total_sessions);
            } else {
                // Untuk rekap reguler: total_transactions adalah sesi foto, total_sessions adalah lembar cetak
                $sesi = (int) $report->total_transactions;
                $lembar = (int) $report->total_sessions;

                // Fallback bila sesi rekap belum terhitung
                if ($sesi === 0 && $report->transactions->isNotEmpty()) {
                    $sesi = $report->transactions->count();
                }
                if ($lembar === 0 && $report->transactions->isNotEmpty()) {
                    $lembar = (int) $report->transactions->sum('session_count');
                }
            }

            $stats[$booth]['sesi'] += $sesi;
            $stats[$booth]['lembar'] += $lembar;
        }

        return $stats;
    }

    /**
     * Ambil matriks pengeluaran per kategori utama dan cabang.
     *
     * @return array{
     *     categories: array<string>,
     *     booths: array<string>,
     *     matrix: array<string, array<string, int>>,
     *     column_totals: array<string, int>,
     *     grand_total: int,
     *     total_gaji: int,
     *     operational_expenses: int,
     *     hpp_by_booth: array<string, int>
     * }
     */
    public function getExpenseMatrix(Carbon $start, Carbon $end): array
    {
        // 1. Ambil seluruh kategori utama pengeluaran aktif dari database
        $parentCategories = FinancialParentCategory::query()
            ->where('is_active', true)
            ->where('name', '!=', FinancialParentCategory::NAME_INCOME)
            ->orderBy('id')
            ->pluck('name')
            ->all();

        // Standard categories fallback bila database belum memiliki seeder lengkap
        $defaultCategories = [
            'Box',
            'Transportasi',
            'Pengadaan Peralatan',
            'Gaji',
            'Persediaan',
            'Konsumsi',
            'Langganan',
            'Tenant/Sharprof',
            'RnD',
        ];

        $allCategories = array_values(array_unique(array_merge($parentCategories, $defaultCategories)));
        if (! in_array('Lain-lain', $allCategories, true)) {
            $allCategories[] = 'Lain-lain';
        }

        $booths = self::DEFAULT_BOOTHS;

        // Inisialisasi matriks
        $matrix = [];
        foreach ($allCategories as $cat) {
            $matrix[$cat] = [];
            foreach ($booths as $b) {
                $matrix[$cat][$b] = 0;
            }
            $matrix[$cat]['total'] = 0;
        }

        $columnTotals = array_fill_keys($booths, 0);
        $columnTotals['total'] = 0;

        // 2. Query transaksi pengeluaran
        $expenses = FinancialTransaction::query()
            ->where('financial_transactions.transaction_type', FinancialTransaction::TYPE_EXPENSE)
            ->whereBetween('financial_transactions.transaction_date', [$start->toDateString(), $end->toDateString()])
            ->leftJoin('cabang', 'cabang.cabang_id', '=', 'financial_transactions.cabang_id')
            ->leftJoin('financial_accounts', 'financial_accounts.id', '=', 'financial_transactions.account_id')
            ->leftJoin('financial_parent_categories as direct_parent', 'direct_parent.id', '=', 'financial_transactions.parent_category_id')
            ->leftJoin('financial_categories', 'financial_categories.id', '=', 'financial_transactions.category_id')
            ->leftJoin('financial_parent_categories as cat_parent', 'cat_parent.id', '=', 'financial_categories.parent_category_id')
            ->selectRaw("
                financial_transactions.amount,
                COALESCE(direct_parent.name, cat_parent.name, 'Lain-lain') as parent_name,
                cabang.nama_cabang as branch_name,
                financial_accounts.name as account_name
            ")
            ->get();

        $totalGaji = 0;
        $hppByBooth = array_fill_keys($booths, 0);

        foreach ($expenses as $expense) {
            $cat = trim((string) $expense->parent_name);
            $booth = $this->resolveBoothName($expense->branch_name, $expense->account_name);
            $amt = (int) $expense->amount;

            if (! isset($matrix[$cat])) {
                $matrix[$cat] = array_fill_keys($booths, 0);
                $matrix[$cat]['total'] = 0;
                $allCategories[] = $cat;
            }

            if (! isset($matrix[$cat][$booth])) {
                $matrix[$cat][$booth] = 0;
            }

            $matrix[$cat][$booth] += $amt;
            $matrix[$cat]['total'] += $amt;

            $columnTotals[$booth] = ($columnTotals[$booth] ?? 0) + $amt;
            $columnTotals['total'] += $amt;

            if (strtolower($cat) === 'gaji') {
                $totalGaji += $amt;
            }

            // Identifikasi apakah kategori ini merupakan HPP booth
            if ($this->isHppCategory($cat)) {
                $hppByBooth[$booth] = ($hppByBooth[$booth] ?? 0) + $amt;
            }
        }

        // Jika belum ada pengeluaran HPP spesifik yang diinput per kategori HPP,
        // gunakan seluruh beban booth sebagai HPP booth agar perhitungan laba rugi tetap seimbang
        foreach ($booths as $b) {
            if ($hppByBooth[$b] === 0 && ($columnTotals[$b] ?? 0) > 0) {
                $hppByBooth[$b] = $columnTotals[$b];
            }
        }

        $grandTotal = $columnTotals['total'];
        $operationalExpenses = max(0, $grandTotal - $totalGaji);

        return [
            'categories' => $allCategories,
            'booths' => $booths,
            'matrix' => $matrix,
            'column_totals' => $columnTotals,
            'grand_total' => $grandTotal,
            'total_gaji' => $totalGaji,
            'operational_expenses' => $operationalExpenses,
            'hpp_by_booth' => $hppByBooth,
        ];
    }

    /**
     * Cek apakah nama kategori merupakan bagian dari HPP.
     */
    protected function isHppCategory(string $categoryName): bool
    {
        $normalized = strtolower(trim($categoryName));
        foreach (self::HPP_PARENT_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Susun baris tabel Income Per Booth beserta HPP, Nett Profit, Lembar, Sesi, dan Laba Ditahan.
     *
     * @param array<string, int> $incomeData
     * @param array<string, array{lembar: int, sesi: int}> $sessionStats
     * @param array<string, mixed> $expenseMatrix
     * @return array{rows: array<string, array<string, mixed>>, total: array<string, mixed>}
     */
    public function buildBoothTable(array $incomeData, array $sessionStats, array $expenseMatrix): array
    {
        $booths = self::DEFAULT_BOOTHS;
        $rows = [];

        $totalIncome = 0;
        $totalHpp = 0;
        $totalNettProfit = 0;
        $totalLembar = 0;
        $totalSesi = 0;
        $totalLabaDitahan = 0;

        foreach ($booths as $booth) {
            $income = (int) ($incomeData[$booth] ?? 0);
            $hpp = (int) ($expenseMatrix['hpp_by_booth'][$booth] ?? 0);

            $nettProfit = $income - $hpp;

            $hppPct = $income > 0 ? round(($hpp / $income) * 100, 2) : 0.0;
            $nettProfitPct = $income > 0 ? round(($nettProfit / $income) * 100, 2) : 0.0;

            $lembar = (int) ($sessionStats[$booth]['lembar'] ?? 0);
            $sesi = (int) ($sessionStats[$booth]['sesi'] ?? 0);

            // Rate Laba Ditahan: 25% untuk Janus & Golio, 30% untuk Express (Sharing Profit)
            $labaDitahanRate = ($booth === self::BOOTH_EXPRESS) ? 0.30 : 0.25;
            $labaDitahan = $nettProfit > 0 ? (int) round($nettProfit * $labaDitahanRate) : 0;

            $rows[$booth] = [
                'booth' => $booth,
                'income' => $income,
                'hpp' => $hpp,
                'hpp_percentage' => $hppPct,
                'nett_profit' => $nettProfit,
                'nett_profit_percentage' => $nettProfitPct,
                'total_lembar' => $lembar,
                'total_sesi' => $sesi,
                'laba_ditahan' => $labaDitahan,
            ];

            $totalIncome += $income;
            $totalHpp += $hpp;
            $totalNettProfit += $nettProfit;
            $totalLembar += $lembar;
            $totalSesi += $sesi;
            $totalLabaDitahan += $labaDitahan;
        }

        $totalHppPct = $totalIncome > 0 ? round(($totalHpp / $totalIncome) * 100, 2) : 0.0;
        $totalNettProfitPct = $totalIncome > 0 ? round(($totalNettProfit / $totalIncome) * 100, 2) : 0.0;

        $total = [
            'booth' => 'TOTAL',
            'income' => $totalIncome,
            'hpp' => $totalHpp,
            'hpp_percentage' => $totalHppPct,
            'nett_profit' => $totalNettProfit,
            'nett_profit_percentage' => $totalNettProfitPct,
            'total_lembar' => $totalLembar,
            'total_sesi' => $totalSesi,
            'laba_ditahan' => $totalLabaDitahan,
        ];

        return [
            'rows' => $rows,
            'total' => $total,
        ];
    }

    /**
     * Ambil data kewajiban utang dari pencatatan keuangan.
     *
     * @return array{rows: array<int, array<string, mixed>>, total_perbulan: int, total_utang: int}
     */
    public function getUtangTable(Carbon $start, Carbon $end): array
    {
        $transactions = FinancialTransaction::query()
            ->where('payment_status', FinancialTransaction::PAYMENT_STATUS_UTANG)
            ->where(function ($q) use ($start, $end) {
                $q->whereNull('due_date')
                    ->orWhere('due_date', '>=', $start->toDateString());
            })
            ->where('transaction_date', '<=', $end->toDateString())
            ->orderBy('due_date')
            ->get();

        $rows = [];
        $totalPerbulan = 0;
        $totalUtangSum = 0;

        foreach ($transactions as $tx) {
            $keterangan = $tx->description ?: ($tx->counterparty ?: ('Utang #' . $tx->id));

            // Hitung sisa waktu dalam bulan
            if ($tx->due_date) {
                $dueCarbon = Carbon::parse($tx->due_date);
                if ($dueCarbon->isPast() && ! $dueCarbon->isSameMonth($end)) {
                    $sisaWaktu = 1;
                } else {
                    $sisaWaktu = max(1, (int) ceil($end->floatDiffInMonths($dueCarbon, false)));
                }
            } else {
                $sisaWaktu = 1;
            }

            $totalUtang = (int) $tx->amount;
            $perbulan = (int) round($totalUtang / max(1, $sisaWaktu));

            $rows[] = [
                'id' => $tx->id,
                'keterangan' => $keterangan,
                'sisa_waktu' => $sisaWaktu,
                'total_utang' => $totalUtang,
                'perbulan' => $perbulan,
            ];

            $totalPerbulan += $perbulan;
            $totalUtangSum += $totalUtang;
        }

        return [
            'rows' => $rows,
            'total_perbulan' => $totalPerbulan,
            'total_utang' => $totalUtangSum,
        ];
    }

    /**
     * Bangun Summary Keuangan Bulanan dengan persentase relatif terhadap Total Income.
     *
     * @return array<int, array{key: string, keterangan: string, nominal: int, persentase: float, tone: string}>
     */
    public function buildSummary(
        int $income,
        int $nettProfit,
        int $gaji,
        int $labaDitahan,
        int $expenses,
        int $utang
    ): array {
        $calcPct = fn (int $val): float => $income > 0 ? round(($val / $income) * 100, 2) : 0.0;

        return [
            [
                'key' => 'income',
                'keterangan' => 'Income',
                'nominal' => $income,
                'persentase' => $income > 0 ? 100.0 : 0.0,
                'tone' => 'success', // hijau
            ],
            [
                'key' => 'nett_profit',
                'keterangan' => 'Nett Profit',
                'nominal' => $nettProfit,
                'persentase' => $calcPct($nettProfit),
                'tone' => 'success', // hijau
            ],
            [
                'key' => 'gaji',
                'keterangan' => 'Gaji',
                'nominal' => $gaji,
                'persentase' => $calcPct($gaji),
                'tone' => 'warning', // kuning
            ],
            [
                'key' => 'laba_ditahan',
                'keterangan' => 'Laba Ditahan',
                'nominal' => $labaDitahan,
                'persentase' => $calcPct($labaDitahan),
                'tone' => 'warning', // kuning
            ],
            [
                'key' => 'expenses',
                'keterangan' => 'Expenses',
                'nominal' => $expenses,
                'persentase' => $calcPct($expenses),
                'tone' => 'danger', // merah
            ],
            [
                'key' => 'utang',
                'keterangan' => 'Utang',
                'nominal' => $utang,
                'persentase' => $calcPct($utang),
                'tone' => 'warning', // kuning
            ],
        ];
    }

    /**
     * Siapkan data Donut Chart (Komposisi Pendapatan 3 Booth).
     *
     * @param array<string, array<string, mixed>> $boothRows
     * @return array{labels: array<string>, series: array<int>, percentages: array<float>, colors: array<string>, total: int}
     */
    public function buildDonutChartData(array $boothRows, int $totalIncome): array
    {
        $colors = [
            self::BOOTH_JANUS => '#3b82f6', // Biru
            self::BOOTH_GOLIO => '#f59e0b', // Oranye / Amber
            self::BOOTH_EXPRESS => '#8b5cf6', // Ungu / Violet
        ];

        $labels = [];
        $series = [];
        $percentages = [];
        $chartColors = [];

        foreach ($boothRows as $booth => $data) {
            $amt = (int) $data['income'];
            $pct = $totalIncome > 0 ? round(($amt / $totalIncome) * 100, 1) : 0.0;

            $labels[] = $booth;
            $series[] = $amt;
            $percentages[] = $pct;
            $chartColors[] = $colors[$booth] ?? '#64748b';
        }

        return [
            'labels' => $labels,
            'series' => $series,
            'percentages' => $percentages,
            'colors' => $chartColors,
            'total' => $totalIncome,
        ];
    }

    /**
     * Siapkan data Line Chart Perbandingan Pendapatan Booth (6 Bulan Terakhir).
     *
     * @return array{labels: array<string>, datasets: array<int, array<string, mixed>>}
     */
    public function buildTrendChartData(int $year, int $month): array
    {
        $months = collect(range(5, 0))
            ->map(fn (int $offset): Carbon => Carbon::createFromDate($year, $month, 1)->subMonthsNoOverflow($offset));

        $start = $months->first()->copy()->startOfMonth();
        $end = Carbon::createFromDate($year, $month, 1)->endOfMonth();

        $rows = FinancialTransaction::query()
            ->boothIncome()
            ->whereBetween('financial_transactions.transaction_date', [$start->toDateString(), $end->toDateString()])
            ->leftJoin('cabang', 'cabang.cabang_id', '=', 'financial_transactions.cabang_id')
            ->leftJoin('financial_accounts', 'financial_accounts.id', '=', 'financial_transactions.account_id')
            ->selectRaw('
                financial_transactions.amount,
                financial_transactions.transaction_date,
                cabang.nama_cabang as branch_name,
                financial_accounts.name as account_name
            ')
            ->get();

        $monthKeys = $months->map(fn (Carbon $m) => $m->format('Y-m'))->all();
        $monthLabels = $months->map(fn (Carbon $m) => MonthHelper::formatPeriod((int) $m->month, (int) $m->year))->all();

        $boothSeries = [
            self::BOOTH_JANUS => array_fill_keys($monthKeys, 0),
            self::BOOTH_GOLIO => array_fill_keys($monthKeys, 0),
            self::BOOTH_EXPRESS => array_fill_keys($monthKeys, 0),
        ];

        foreach ($rows as $row) {
            $key = $row->transaction_date ? Carbon::parse($row->transaction_date)->format('Y-m') : null;
            if ($key && array_key_exists($key, $boothSeries[self::BOOTH_JANUS])) {
                $booth = $this->resolveBoothName($row->branch_name, $row->account_name);
                if (isset($boothSeries[$booth][$key])) {
                    $boothSeries[$booth][$key] += (int) $row->amount;
                }
            }
        }

        $datasets = [
            [
                'label' => self::BOOTH_JANUS,
                'data' => array_values($boothSeries[self::BOOTH_JANUS]),
                'borderColor' => '#3b82f6',
                'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                'tension' => 0.3,
            ],
            [
                'label' => self::BOOTH_GOLIO,
                'data' => array_values($boothSeries[self::BOOTH_GOLIO]),
                'borderColor' => '#f59e0b',
                'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                'tension' => 0.3,
            ],
            [
                'label' => self::BOOTH_EXPRESS,
                'data' => array_values($boothSeries[self::BOOTH_EXPRESS]),
                'borderColor' => '#8b5cf6',
                'backgroundColor' => 'rgba(139, 92, 246, 0.1)',
                'tension' => 0.3,
            ],
        ];

        return [
            'labels' => $monthLabels,
            'datasets' => $datasets,
        ];
    }

    /**
     * Ambil daftar opsi tahun yang tersedia untuk filter.
     *
     * @return array<int, int>
     */
    public function getAvailableYears(): array
    {
        $currentYear = (int) now()->year;

        $earliestTx = FinancialTransaction::query()
            ->min('transaction_date');

        $startYear = $earliestTx ? (int) Carbon::parse($earliestTx)->year : ($currentYear - 2);
        $startYear = min($startYear, $currentYear - 1);
        $endYear = $currentYear + 1;

        $years = [];
        for ($y = $endYear; $y >= $startYear; $y--) {
            $years[$y] = $y;
        }

        return $years;
    }
}
