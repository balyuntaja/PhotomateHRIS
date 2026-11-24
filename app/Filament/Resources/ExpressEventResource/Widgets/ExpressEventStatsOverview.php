<?php

namespace App\Filament\Resources\ExpressEventResource\Widgets;

use App\Services\HppDashboardService;
use App\Utils\MonthHelper;
use App\Utils\RupiahHelper;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ExpressEventStatsOverview extends BaseWidget
{
    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $now = now();
        $start = (clone $now)->startOfMonth();
        $end = (clone $now)->endOfMonth();

        $service = app(HppDashboardService::class);
        $summary = $service->getSummary($start, $end);

        $periodLabel = MonthHelper::formatPeriod($now->month, $now->year);

        return [
            Stat::make('Total HPP Semua Cabang', RupiahHelper::format($summary['total_hpp_all']))
                ->description("Periode {$periodLabel} (Express, Janus, Golio)")
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),

            Stat::make('HPP Express', RupiahHelper::format($summary['total_hpp_express']))
                ->description('Metode: Berbasis Event')
                ->descriptionIcon('heroicon-m-sparkles')
                ->color('info'),

            Stat::make('HPP Janus', RupiahHelper::format($summary['total_hpp_janus']))
                ->description('Metode: Per Lembar Sesi')
                ->descriptionIcon('heroicon-m-newspaper')
                ->color('success'),

            Stat::make('HPP Golio', RupiahHelper::format($summary['total_hpp_golio']))
                ->description('Metode: Per Lembar Sesi')
                ->descriptionIcon('heroicon-m-camera')
                ->color('warning'),

            Stat::make('Sudah Dikonfirmasi', "{$summary['confirmed_count']} Data")
                ->description('HPP telah disetujui')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),

            Stat::make('Menunggu Konfirmasi', "{$summary['pending_count']} Data")
                ->description('Tarif / HPP belum final')
                ->descriptionIcon('heroicon-m-clock')
                ->color($summary['pending_count'] > 0 ? 'warning' : 'gray'),

            Stat::make('Belum Rekonsiliasi', "{$summary['unreconciled_count']} Data")
                ->description('Perlu review / selisih')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($summary['unreconciled_count'] > 0 ? 'danger' : 'gray'),
        ];
    }
}
