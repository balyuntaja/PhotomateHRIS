<?php

namespace App\Filament\Widgets\Keuangan;

use App\Services\KeuanganService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class IncomeExpenseChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?string $heading = 'Pemasukan vs Pengeluaran';

    protected static ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $service = app(KeuanganService::class);

        $period = $service->resolvePeriod(
            $this->filters['periode'] ?? null,
            $this->filters['dari'] ?? null,
            $this->filters['sampai'] ?? null,
        );

        $series = $service->chartSeries($period['start'], $period['end'], $this->filters['cabang_id'] ?? null);

        return [
            'labels' => $series['labels'],
            'datasets' => [
                [
                    'label' => 'Pemasukan',
                    'data' => $series['income'],
                    'backgroundColor' => 'rgb(16, 185, 129)',
                    'borderColor' => 'rgb(5, 150, 105)',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Pengeluaran',
                    'data' => $series['expense'],
                    'backgroundColor' => 'rgb(244, 63, 94)',
                    'borderColor' => 'rgb(225, 29, 72)',
                    'borderWidth' => 1,
                ],
            ],
        ];
    }

    public function getDescription(): ?string
    {
        $service = app(KeuanganService::class);

        $period = $service->resolvePeriod(
            $this->filters['periode'] ?? null,
            $this->filters['dari'] ?? null,
            $this->filters['sampai'] ?? null,
        );

        return 'Periode: ' . $period['label'];
    }
}
