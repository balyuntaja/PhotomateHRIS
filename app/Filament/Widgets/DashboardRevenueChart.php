<?php

namespace App\Filament\Widgets;

use App\Models\SessionReport;
use App\Utils\MonthHelper;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class DashboardRevenueChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $heading = null;

    protected static ?string $maxHeight = '280px';

    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $months = collect(range(5, 0))
            ->map(fn (int $offset): Carbon => now()->startOfMonth()->subMonthsNoOverflow($offset));

        $start = $months->first()->copy()->startOfMonth();
        $end = now()->endOfMonth();

        $rows = SessionReport::query()
            ->where('status', SessionReport::STATUS_APPROVED)
            ->whereBetween('report_date', [$start->toDateString(), $end->toDateString()])
            ->get(['report_date', 'grand_total_amount']);

        $totals = $months
            ->mapWithKeys(fn (Carbon $month): array => [$month->format('Y-m') => 0])
            ->all();

        foreach ($rows as $row) {
            $key = $row->report_date?->format('Y-m');

            if ($key !== null && array_key_exists($key, $totals)) {
                $totals[$key] += (int) $row->grand_total_amount;
            }
        }

        return [
            'labels' => $months
                ->map(fn (Carbon $month): string => MonthHelper::formatPeriod((int) $month->month, (int) $month->year))
                ->all(),
            'datasets' => [
                [
                    'label' => 'Pendapatan',
                    'data' => array_values($totals),
                    'borderColor' => 'rgb(53, 75, 109)',
                    'backgroundColor' => 'rgba(53, 75, 109, 0.08)',
                    'pointBackgroundColor' => 'rgb(53, 75, 109)',
                    'pointBorderColor' => '#ffffff',
                    'pointBorderWidth' => 2,
                    'pointRadius' => 3,
                    'pointHoverRadius' => 5,
                    'borderWidth' => 2,
                    'tension' => 0.35,
                    'fill' => true,
                ],
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        backgroundColor: '#1B2637',
                        padding: 12,
                        cornerRadius: 10,
                        displayColors: false,
                        callbacks: {
                            label: (context) => `Pendapatan: ${window.formatRupiah(context.parsed.y)}`,
                        },
                    },
                },
                scales: {
                    x: {
                        grid: {
                            display: false,
                        },
                        ticks: {
                            color: '#94A3B8',
                            font: { size: 11 },
                        },
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(148, 163, 184, 0.18)',
                        },
                        border: {
                            display: false,
                        },
                        ticks: {
                            color: '#94A3B8',
                            font: { size: 11 },
                            callback: (value) => window.formatRupiah(value),
                        },
                    },
                },
            }
            JS);
    }
}
