<?php

namespace App\Filament\Widgets;

use App\Services\RekapPerbulanService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class RekapPerbulanDonutChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $heading = null;

    protected static string $view = 'filament.widgets.rekap-perbulan-donut-chart';

    protected static ?string $maxHeight = '190px';

    protected static ?string $pollingInterval = null;

    public ?int $month = null;

    public ?int $year = null;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $month = $this->month ?? (int) now()->month;
        $year = $this->year ?? (int) now()->year;

        $service = app(RekapPerbulanService::class);
        $data = $service->getReportData($year, $month);

        $donut = $data['donut_chart'];

        return [
            'labels' => $donut['labels'],
            'datasets' => [
                [
                    'label' => 'Pendapatan',
                    'data' => $donut['series'],
                    'backgroundColor' => $donut['colors'],
                    'borderWidth' => 2,
                    'borderColor' => '#ffffff',
                    'hoverOffset' => 5,
                ],
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#0F172A',
                        titleColor: '#F8FAFC',
                        bodyColor: '#F8FAFC',
                        padding: 10,
                        cornerRadius: 8,
                        boxPadding: 4,
                        usePointStyle: true,
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                const dataset = context.dataset.data || [];
                                const total = dataset.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                const formatted = typeof window.formatRupiah === 'function' 
                                    ? window.formatRupiah(value) 
                                    : 'Rp ' + Number(value).toLocaleString('id-ID');
                                return ` ${label}: ${formatted} (${percentage}%)`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        display: false
                    },
                    y: {
                        display: false
                    }
                },
                cutout: '70%',
                layout: {
                    padding: 4
                }
            }
        JS);
    }
}
