<?php

namespace App\Filament\Widgets;

use App\Services\RekapPerbulanService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class RekapPerbulanTrendChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $heading = null;

    protected static string $view = 'filament.widgets.rekap-perbulan-trend-chart';

    protected static ?string $maxHeight = '240px';

    protected static ?string $pollingInterval = null;

    public ?int $month = null;

    public ?int $year = null;

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $month = $this->month ?? (int) now()->month;
        $year = $this->year ?? (int) now()->year;

        $service = app(RekapPerbulanService::class);
        $data = $service->getReportData($year, $month);

        $trend = $data['trend_chart'];

        $formattedDatasets = [];
        foreach ($trend['datasets'] as $ds) {
            $formattedDatasets[] = [
                'label' => $ds['label'],
                'data' => $ds['data'],
                'borderColor' => $ds['borderColor'],
                'backgroundColor' => $ds['backgroundColor'],
                'pointBackgroundColor' => $ds['borderColor'],
                'pointBorderColor' => '#ffffff',
                'pointBorderWidth' => 2,
                'pointRadius' => 3.5,
                'pointHoverRadius' => 6,
                'borderWidth' => 2.5,
                'tension' => 0.35,
                'fill' => false,
            ];
        }

        return [
            'labels' => $trend['labels'],
            'datasets' => $formattedDatasets,
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 10,
                            padding: 10,
                            font: { size: 11, weight: '500' },
                        },
                    },
                    tooltip: {
                        backgroundColor: '#1E293B',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                const label = context.dataset.label || '';
                                const value = context.parsed.y || 0;
                                const formatted = typeof window.formatRupiah === 'function' 
                                    ? window.formatRupiah(value) 
                                    : 'Rp ' + Number(value).toLocaleString('id-ID');
                                return `${label}: ${formatted}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#64748B', font: { size: 11 } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(148, 163, 184, 0.15)' },
                        border: { display: false },
                        ticks: {
                            color: '#64748B',
                            font: { size: 11 },
                            callback: function(value) {
                                if (typeof window.formatRupiah === 'function') {
                                    return window.formatRupiah(value);
                                }
                                return 'Rp ' + Number(value).toLocaleString('id-ID');
                            }
                        }
                    }
                }
            }
        JS);
    }
}
