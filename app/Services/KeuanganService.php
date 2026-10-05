<?php

namespace App\Services;

use App\Models\FinancialTransaction;
use App\Utils\MonthHelper;
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

    public const PRESET_CUSTOM = 'custom';

    public const DEFAULT_PRESET = self::PRESET_THIS_MONTH;

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
            self::PRESET_CUSTOM => 'Rentang Custom',
        ];
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
            return $start->day . ' ' . MonthHelper::formatPeriod((int) $start->month, (int) $start->year);
        }

        if ($start->isSameMonth($end) && $start->isSameYear($end)) {
            return MonthHelper::formatPeriod((int) $start->month, (int) $start->year);
        }

        return $start->format('d/m/Y') . ' - ' . $end->format('d/m/Y');
    }

    public function baseQuery(CarbonInterface $start, CarbonInterface $end, ?string $cabangId = null): Builder
    {
        return FinancialTransaction::query()
            ->periode($start, $end)
            ->forCabang($cabangId);
    }

    /**
     * @return array{income: int, expense: int, balance: int}
     */
    public function summary(CarbonInterface $start, CarbonInterface $end, ?string $cabangId = null): array
    {
        $totals = $this->baseQuery($start, $end, $cabangId)
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
     * Breakdown per grup lalu per kategori.
     *
     * @return array<string, array{total: int, groups: array<int, array{label: string|null, total: int, percentage: float, items: array<int, array{name: string, total: int, percentage: float}>}>}>
     */
    public function categoryBreakdown(CarbonInterface $start, CarbonInterface $end, ?string $cabangId = null): array
    {
        $rows = $this->baseQuery($start, $end, $cabangId)
            ->join('financial_categories', 'financial_categories.id', '=', 'financial_transactions.category_id')
            ->selectRaw('financial_categories.name as category_name')
            ->selectRaw('financial_categories.group_name as group_name')
            ->selectRaw('financial_transactions.transaction_type as transaction_type')
            ->selectRaw('SUM(financial_transactions.amount) as total')
            ->groupBy('financial_categories.name', 'financial_categories.group_name', 'financial_transactions.transaction_type')
            ->get();

        $totals = [
            FinancialTransaction::TYPE_INCOME => 0,
            FinancialTransaction::TYPE_EXPENSE => 0,
        ];

        $groups = [
            FinancialTransaction::TYPE_INCOME => [],
            FinancialTransaction::TYPE_EXPENSE => [],
        ];

        foreach ($rows as $row) {
            $type = $row->transaction_type === FinancialTransaction::TYPE_INCOME
                ? FinancialTransaction::TYPE_INCOME
                : FinancialTransaction::TYPE_EXPENSE;

            $total = (int) $row->total;
            $totals[$type] += $total;

            $groupKey = $row->group_name ?: '__none__';

            if (! isset($groups[$type][$groupKey])) {
                $groups[$type][$groupKey] = [
                    'label' => $row->group_name,
                    'total' => 0,
                    'items' => [],
                ];
            }

            $groups[$type][$groupKey]['total'] += $total;
            $groups[$type][$groupKey]['items'][] = [
                'name' => $row->category_name,
                'total' => $total,
            ];
        }

        $result = [];

        foreach ($totals as $type => $typeTotal) {
            $typeGroups = array_values($groups[$type]);

            foreach ($typeGroups as $index => $group) {
                $typeGroups[$index]['percentage'] = $typeTotal > 0 ? round($group['total'] / $typeTotal * 100, 1) : 0.0;
                $typeGroups[$index]['items'] = collect($group['items'])
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
                'groups' => collect($typeGroups)->sortByDesc('total')->values()->all(),
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
            ->with(['category', 'cabang', 'paymentMethod'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function transactionsForExport(
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $cabangId = null
    ): Builder {
        return $this->baseQuery($start, $end, $cabangId)
            ->with(['category', 'cabang', 'paymentMethod', 'creator'])
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
        ?string $cabangId = null
    ): Builder {
        return $this->baseQuery($start, $end, $cabangId)
            ->with(['category', 'cabang', 'paymentMethod'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
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
