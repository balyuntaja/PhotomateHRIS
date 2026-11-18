<?php

namespace App\Services;

use App\Models\Cabang;
use App\Models\FinancialCategory;
use App\Models\FinancialParentCategory;
use App\Models\FinancialTransaction;
use App\Utils\MonthHelper;
use App\Utils\RupiahHelper;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class KeuanganService
{
    public const PRESET_TODAY = 'today';

    public const PRESET_THIS_WEEK = 'this_week';

    public const PRESET_THIS_MONTH = 'this_month';

    public const PRESET_LAST_MONTH = 'last_month';

    public const PRESET_THIS_YEAR = 'this_year';

    public const PRESET_CUSTOM = 'custom';

    public const DEFAULT_PRESET = self::PRESET_THIS_MONTH;

    public const CABANG_FILTER_EXPRESS = 'express';

    /**
     * Cabang yang digabung menjadi satu pilihan "Express" pada Dashboard Keuangan.
     */
    public const EXPRESS_BRANCH_NAMES = [
        'Express Self Run',
        'Express Sewa',
        'Express Sharing Profit',
    ];

    public const EXPORT_HEADERS = [
        'ID',
        'Tanggal',
        'Jenis',
        'Akun',
        'Kategori',
        'Deskripsi',
        'Metode Pembayaran',
        'Nominal',
        'Dibayarkan / Disubmit Oleh',
        'Dibuat Oleh',
        'Created At',
        'Updated At',
    ];

    /**
     * Opsi preset periode untuk filter.
     *
     * @return array<string, string>
     */
    public function periodOptions(): array
    {
        return [
            self::PRESET_TODAY => 'Hari Ini',
            self::PRESET_THIS_WEEK => 'Minggu Ini',
            self::PRESET_THIS_MONTH => 'Bulan Ini',
            self::PRESET_LAST_MONTH => 'Bulan Lalu',
            self::PRESET_THIS_YEAR => 'Tahun Ini',
            self::PRESET_CUSTOM => 'Rentang Custom',
        ];
    }

    /**
     * Opsi filter cabang Dashboard Keuangan: Express digabung menjadi satu.
     *
     * @return array<string, string>
     */
    public function cabangFilterOptions(): array
    {
        $options = [];

        if ($this->expressCabangIds() !== []) {
            $options[self::CABANG_FILTER_EXPRESS] = 'Express';
        }

        foreach (['Golio', 'Newspaper Janus'] as $branchName) {
            $cabangId = Cabang::query()->where('nama_cabang', $branchName)->value('cabang_id');

            if (filled($cabangId)) {
                $options[(string) $cabangId] = $branchName;
            }
        }

        return $options;
    }

    /**
     * Terjemahkan nilai filter cabang menjadi daftar cabang_id.
     * Null berarti tanpa filter (seluruh cabang).
     *
     * @return array<int, string>|null
     */
    public function resolveCabangFilterIds(?string $cabangFilter): ?array
    {
        if (blank($cabangFilter)) {
            return null;
        }

        if ($cabangFilter === self::CABANG_FILTER_EXPRESS) {
            return $this->expressCabangIds();
        }

        return [(string) $cabangFilter];
    }

    /**
     * @return array{preset: string, start: Carbon, end: Carbon, label: string}
     */
    public function resolvePeriod(?string $preset, ?string $dari = null, ?string $sampai = null): array
    {
        $preset = filled($preset) ? $preset : self::DEFAULT_PRESET;
        $today = Carbon::today();

        [$start, $end] = match ($preset) {
            self::PRESET_TODAY => [$today->copy(), $today->copy()],
            self::PRESET_THIS_WEEK => [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()],
            self::PRESET_LAST_MONTH => [
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            self::PRESET_THIS_YEAR => [
                $today->copy()->startOfYear(),
                $today->copy()->endOfYear(),
            ],
            self::PRESET_CUSTOM => [
                $this->parseDate($dari, $today->copy()->startOfMonth()),
                $this->parseDate($sampai, $today->copy()),
            ],
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
        };

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        return [
            'preset' => $preset,
            'start' => $start,
            'end' => $end,
            'label' => $this->periodLabel($start, $end),
        ];
    }

    public function periodLabel(CarbonInterface $start, CarbonInterface $end): string
    {
        if ($start->isSameDay($end)) {
            return $start->day.' '.MonthHelper::formatPeriod((int) $start->month, (int) $start->year);
        }

        if ($start->isSameMonth($end) && $start->isSameYear($end)) {
            return MonthHelper::formatPeriod((int) $start->month, (int) $start->year);
        }

        if ($start->copy()->startOfYear()->isSameDay($start) && $end->copy()->endOfYear()->isSameDay($end) && $start->year === $end->year) {
            return 'Tahun '.$start->year;
        }

        return $start->format('d/m/Y').' - '.$end->format('d/m/Y');
    }

    public function baseQuery(
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $cabangId = null,
        ?int $accountId = null
    ): Builder {
        $cabangIds = $this->resolveCabangFilterIds($cabangId);

        return FinancialTransaction::query()
            ->periode($start, $end)
            ->forAccount($accountId)
            ->when(
                $cabangIds !== null,
                fn (Builder $query): Builder => $query->whereIn('cabang_id', $cabangIds)
            );
    }

    /**
     * @return array{income: int, expense: int, balance: int}
     */
    public function summary(
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $cabangId = null,
        ?int $accountId = null
    ): array {
        $totals = $this->baseQuery($start, $end, $cabangId, $accountId)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN transaction_type = ? THEN amount ELSE 0 END), 0) as total_income',
                [FinancialTransaction::TYPE_INCOME]
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN transaction_type = ? THEN amount ELSE 0 END), 0) as total_expense',
                [FinancialTransaction::TYPE_EXPENSE]
            )
            ->first();

        $income = (int) ($totals->total_income ?? 0);
        $expense = (int) ($totals->total_expense ?? 0);

        return [
            'income' => $income,
            'expense' => $expense,
            'balance' => $income - $expense,
        ];
    }

    /**
     * Seri harian untuk rentang pendek, bulanan untuk rentang panjang.
     *
     * @return array{labels: array<int, string>, income: array<int, int>, expense: array<int, int>}
     */
    public function chartSeries(CarbonInterface $start, CarbonInterface $end, ?string $cabangId = null): array
    {
        $useMonthly = (int) $start->diffInDays($end) > 62;

        $buckets = $this->emptyBuckets($start, $end, $useMonthly);

        $rows = $this->baseQuery($start, $end, $cabangId)->get(['transaction_date', 'transaction_type', 'amount']);

        foreach ($rows as $row) {
            $key = $useMonthly
                ? $row->transaction_date?->format('Y-m')
                : $row->transaction_date?->toDateString();

            if ($key === null || ! isset($buckets[$key])) {
                continue;
            }

            $column = $row->transaction_type === FinancialTransaction::TYPE_INCOME ? 'income' : 'expense';

            $buckets[$key][$column] += (int) $row->amount;
        }

        $values = array_values($buckets);

        return [
            'labels' => array_map(fn (array $bucket): string => $bucket['label'], $values),
            'income' => array_map(fn (array $bucket): int => $bucket['income'], $values),
            'expense' => array_map(fn (array $bucket): int => $bucket['expense'], $values),
        ];
    }

    /**
     * Breakdown per kategori utama lalu sub kategori.
     *
     * @return array<string, array{total: int, parents: array<int, array{label: string|null, total: int, percentage: float, items: array<int, array{name: string, total: int, percentage: float}>}>}>
     */
    public function categoryBreakdown(CarbonInterface $start, CarbonInterface $end, ?string $cabangId = null): array
    {
        $rows = $this->baseQuery($start, $end, $cabangId)
            ->leftJoin('financial_categories', 'financial_categories.id', '=', 'financial_transactions.category_id')
            ->leftJoin('financial_parent_categories', 'financial_parent_categories.id', '=', 'financial_transactions.parent_category_id')
            ->selectRaw('financial_categories.name as category_name')
            ->selectRaw('financial_parent_categories.name as parent_name')
            ->selectRaw('financial_transactions.transaction_type as transaction_type')
            ->selectRaw('SUM(financial_transactions.amount) as total')
            ->groupBy('financial_categories.name', 'financial_parent_categories.name', 'financial_transactions.transaction_type')
            ->get();

        $totals = [
            FinancialTransaction::TYPE_INCOME => 0,
            FinancialTransaction::TYPE_EXPENSE => 0,
        ];

        $parents = [
            FinancialTransaction::TYPE_INCOME => [],
            FinancialTransaction::TYPE_EXPENSE => [],
        ];

        foreach ($rows as $row) {
            $type = $row->transaction_type === FinancialTransaction::TYPE_INCOME
                ? FinancialTransaction::TYPE_INCOME
                : FinancialTransaction::TYPE_EXPENSE;

            $total = (int) $row->total;
            $totals[$type] += $total;

            $parentKey = $row->parent_name ?: '__none__';

            if (! isset($parents[$type][$parentKey])) {
                $parents[$type][$parentKey] = [
                    'label' => $row->parent_name,
                    'total' => 0,
                    'items' => [],
                ];
            }

            $parents[$type][$parentKey]['total'] += $total;
            $parents[$type][$parentKey]['items'][] = [
                'name' => $row->category_name ?? 'Tanpa Sub Kategori',
                'total' => $total,
            ];
        }

        $result = [];

        foreach ($totals as $type => $typeTotal) {
            $typeParents = array_values($parents[$type]);

            foreach ($typeParents as $index => $parent) {
                $typeParents[$index]['percentage'] = $typeTotal > 0 ? round($parent['total'] / $typeTotal * 100, 1) : 0.0;
                $typeParents[$index]['items'] = collect($parent['items'])
                    ->map(fn (array $item): array => [
                        'name' => $item['name'],
                        'total' => $item['total'],
                        'percentage' => $typeTotal > 0 ? round($item['total'] / $typeTotal * 100, 1) : 0.0,
                    ])
                    ->sortByDesc('total')
                    ->values()
                    ->all();
            }

            $result[$type] = [
                'total' => $typeTotal,
                'parents' => collect($typeParents)->sortByDesc('total')->values()->all(),
            ];
        }

        return $result;
    }

    /**
     * Selisih kas per cabang (bukan laba akuntansi).
     *
     * @return array<int, array{branch: string, income: int, expense: int, net: int}>
     */
    public function branchBreakdown(CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->baseQuery($start, $end)
            ->leftJoin('cabang', 'cabang.cabang_id', '=', 'financial_transactions.cabang_id')
            ->selectRaw("COALESCE(cabang.nama_cabang, 'Tanpa Cabang') as branch_name")
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN financial_transactions.transaction_type = ? THEN financial_transactions.amount ELSE 0 END), 0) as total_income',
                [FinancialTransaction::TYPE_INCOME]
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN financial_transactions.transaction_type = ? THEN financial_transactions.amount ELSE 0 END), 0) as total_expense',
                [FinancialTransaction::TYPE_EXPENSE]
            )
            ->groupBy('cabang.nama_cabang')
            ->get()
            ->map(fn ($row): array => [
                'branch' => $row->branch_name,
                'income' => (int) $row->total_income,
                'expense' => (int) $row->total_expense,
                'net' => (int) $row->total_income - (int) $row->total_expense,
            ])
            ->sortByDesc('net')
            ->values()
            ->all();
    }

    public function latestTransactions(
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $cabangId = null,
        int $limit = 8
    ): Collection {
        return $this->baseQuery($start, $end, $cabangId)
            ->with(['category', 'parentCategory', 'cabang', 'paymentMethod'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function transactionsForExport(
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $cabangId = null,
        ?int $accountId = null
    ): Builder {
        return $this->baseQuery($start, $end, $cabangId, $accountId)
            ->with(['category', 'parentCategory', 'account', 'party', 'paymentMethod', 'submitter', 'creator', 'cabang'])
            ->orderBy('transaction_date')
            ->orderBy('id');
    }

    /**
     * Transaksi rincian laporan dengan urutan yang sama seperti tabel
     * "Rincian Transaksi" pada halaman Laporan Keuangan (terbaru di atas).
     */
    public function transactionsForReport(
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $cabangId = null,
        ?int $accountId = null
    ): Builder {
        return $this->baseQuery($start, $end, $cabangId, $accountId)
            ->with(['category', 'parentCategory', 'account', 'party', 'paymentMethod', 'submitter', 'creator', 'cabang'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Baris CSV yang dipakai bersama oleh halaman Transaksi dan Laporan.
     *
     * @return array<int, string|int>
     */
    public function exportRow(FinancialTransaction $transaction): array
    {
        return [
            $transaction->id,
            $transaction->transaction_date?->format('Y-m-d') ?? '-',
            $transaction->type_label,
            $transaction->account?->name ?? 'Tanpa Akun',
            $transaction->category_label,
            $transaction->description ?: '-',
            $transaction->payment_method_label,
            RupiahHelper::format($transaction->amount),
            $transaction->recording_info_name,
            $transaction->creator?->nama_lengkap ?? '-',
            $transaction->created_at?->format('Y-m-d H:i:s') ?? '-',
            $transaction->updated_at?->format('Y-m-d H:i:s') ?? '-',
        ];
    }

    /**
     * Seluruh cabang Express, termasuk yang sudah soft-deleted agar data historis tetap terhitung.
     *
     * @return array<int, string>
     */
    protected function expressCabangIds(): array
    {
        return Cabang::withTrashed()
            ->whereIn('nama_cabang', self::EXPRESS_BRANCH_NAMES)
            ->orderBy('cabang_id')
            ->pluck('cabang_id')
            ->map(fn ($cabangId): string => (string) $cabangId)
            ->values()
            ->all();
    }

    /**
     * Mengambil daftar nama seluruh kategori utama Beban Usaha dari master data.
     * Mengikuti struktur hierarki parent-child kategori keuangan yang terdaftar di database.
     *
     * @return array<string>
     */
    public function getExpenseParentCategoryNames(): array
    {
        // 1. Kategori utama aktif dari financial_parent_categories (kecuali kategori pemasukan)
        $parentCategories = FinancialParentCategory::query()
            ->where('is_active', true)
            ->where('name', '!=', FinancialParentCategory::NAME_INCOME)
            ->orderBy('id')
            ->pluck('name')
            ->all();

        // 2. Pastikan 'Lain-lain' selalu tersedia sebagai penampung fallback jika belum terdaftar
        if (! in_array('Lain-lain', $parentCategories, true)) {
            $parentCategories[] = 'Lain-lain';
        }

        return array_values(array_unique($parentCategories));
    }

    /**
     * Laporan Laba Rugi Photomate:
     * - Pendapatan Usaha dikelompokkan per cabang/akun (Express, Janus, Golio, dan lainnya)
     * - Beban Usaha dikelompokkan per Kategori Utama (seluruh kategori utama Beban Usaha selalu ditampilkan)
     * - Ringkasan Laba / Rugi = Total Pendapatan - Total Beban Usaha
     *
     * @return array{
     *     pendapatan: array{rows: array<string, int>, total: int},
     *     beban: array{rows: array<string, int>, total: int},
     *     ringkasan: array{total_pendapatan: int, total_beban: int, laba_rugi: int, status: string, status_label: string}
     * }
     */
    public function labaRugiReport(
        CarbonInterface $start,
        CarbonInterface $end,
        ?int $accountId = null,
        bool $includeZeroParentCategories = true
    ): array {
        // 1. Agregasi Pendapatan Usaha per cabang/akun
        $incomeRows = $this->baseQuery($start, $end, null, $accountId)
            ->where('financial_transactions.transaction_type', FinancialTransaction::TYPE_INCOME)
            ->leftJoin('financial_accounts', 'financial_accounts.id', '=', 'financial_transactions.account_id')
            ->leftJoin('cabang', 'cabang.cabang_id', '=', 'financial_transactions.cabang_id')
            ->selectRaw("
                CASE 
                    WHEN LOWER(financial_accounts.name) LIKE '%express%' OR LOWER(cabang.nama_cabang) LIKE '%express%' THEN 'Express'
                    WHEN LOWER(financial_accounts.name) LIKE '%janus%' OR LOWER(cabang.nama_cabang) LIKE '%janus%' THEN 'Janus'
                    WHEN LOWER(financial_accounts.name) LIKE '%golio%' OR LOWER(cabang.nama_cabang) LIKE '%golio%' THEN 'Golio'
                    ELSE COALESCE(financial_accounts.name, cabang.nama_cabang, 'Lain-lain')
                END as branch_group,
                SUM(financial_transactions.amount) as total
            ")
            ->groupBy('branch_group')
            ->get();

        $pendapatanRows = [
            'Express' => 0,
            'Janus' => 0,
            'Golio' => 0,
        ];
        $pendapatanLain = [];

        foreach ($incomeRows as $row) {
            $group = (string) $row->branch_group;
            $amt = (int) $row->total;

            if (array_key_exists($group, $pendapatanRows)) {
                $pendapatanRows[$group] = $amt;
            } else {
                $pendapatanLain[$group] = $amt;
            }
        }

        $allPendapatanRows = array_merge($pendapatanRows, $pendapatanLain);
        $totalPendapatan = (int) array_sum($allPendapatanRows);

        // 2. Agregasi Beban Usaha per Kategori Utama
        $expenseRows = $this->baseQuery($start, $end, null, $accountId)
            ->where('financial_transactions.transaction_type', FinancialTransaction::TYPE_EXPENSE)
            ->leftJoin('financial_parent_categories as direct_parent', 'direct_parent.id', '=', 'financial_transactions.parent_category_id')
            ->leftJoin('financial_categories', 'financial_categories.id', '=', 'financial_transactions.category_id')
            ->leftJoin('financial_parent_categories as cat_parent', 'cat_parent.id', '=', 'financial_categories.parent_category_id')
            ->selectRaw("
                COALESCE(direct_parent.name, cat_parent.name, 'Lain-lain') as parent_name,
                SUM(financial_transactions.amount) as total
            ")
            ->groupBy('parent_name')
            ->get();

        $masterBebanCategories = $this->getExpenseParentCategoryNames();

        $bebanRows = $includeZeroParentCategories
            ? array_fill_keys($masterBebanCategories, 0)
            : [];
        $totalBeban = 0;

        foreach ($expenseRows as $row) {
            $name = trim((string) $row->parent_name);
            $amt = (int) $row->total;

            if ($amt > 0 || $includeZeroParentCategories) {
                $bebanRows[$name] = ($bebanRows[$name] ?? 0) + $amt;
            }
            $totalBeban += $amt;
        }

        // 3. Ringkasan Laba / Rugi
        $labaRugi = $totalPendapatan - $totalBeban;
        if ($labaRugi > 0) {
            $status = 'laba';
            $statusLabel = 'Laba';
        } elseif ($labaRugi < 0) {
            $status = 'rugi';
            $statusLabel = 'Rugi';
        } else {
            $status = 'impas';
            $statusLabel = 'Impas';
        }

        return [
            'pendapatan' => [
                'rows' => $allPendapatanRows,
                'total' => $totalPendapatan,
            ],
            'beban' => [
                'rows' => $bebanRows,
                'total' => $totalBeban,
            ],
            'ringkasan' => [
                'total_pendapatan' => $totalPendapatan,
                'total_beban' => $totalBeban,
                'laba_rugi' => $labaRugi,
                'status' => $status,
                'status_label' => $statusLabel,
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, income: int, expense: int}>
     */
    protected function emptyBuckets(CarbonInterface $start, CarbonInterface $end, bool $useMonthly): array
    {
        $buckets = [];
        $cursor = $useMonthly ? $start->copy()->startOfMonth() : $start->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $key = $useMonthly ? $cursor->format('Y-m') : $cursor->toDateString();

            $buckets[$key] = [
                'label' => $useMonthly
                    ? MonthHelper::formatPeriod((int) $cursor->month, (int) $cursor->year)
                    : $cursor->format('d/m'),
                'income' => 0,
                'expense' => 0,
            ];

            $useMonthly ? $cursor->addMonthNoOverflow() : $cursor->addDay();
        }

        return $buckets;
    }

    protected function parseDate(?string $value, Carbon $fallback): Carbon
    {
        if (blank($value)) {
            return $fallback;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
