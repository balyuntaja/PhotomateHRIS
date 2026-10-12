<?php

namespace App\Filament\Widgets\Keuangan;

use App\Services\KeuanganService;
use App\Utils\RupiahHelper;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class KeuanganStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $service = app(KeuanganService::class);

        $period = $service->resolvePeriod(
            $this->filters['periode'] ?? null,
            $this->filters['dari'] ?? null,
            $this->filters['sampai'] ?? null,
        );

        $summary = $service->summary($period['start'], $period['end'], $this->filters['cabang_id'] ?? null);

        return [
            Stat::make('Total Pemasukan', RupiahHelper::format($summary['income']))
                ->description('Periode: ' . $period['label'])
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('Total Pengeluaran', RupiahHelper::format($summary['expense']))
                ->description('Periode: ' . $period['label'])
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->color('danger'),

            Stat::make('Saldo', RupiahHelper::format($summary['balance']))
                ->description('Pemasukan dikurangi pengeluaran')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($summary['balance'] < 0 ? 'danger' : 'primary'),
        ];
    }
}
