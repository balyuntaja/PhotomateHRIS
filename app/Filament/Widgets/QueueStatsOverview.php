<?php

namespace App\Filament\Widgets;

use App\Models\Event;
use App\Models\QueueEntry;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class QueueStatsOverview extends BaseWidget
{
    protected static ?string $pollingInterval = '5s';

    protected function getStats(): array
    {
        $activeEvents = Event::whereIn('status', ['OPEN', 'PAUSED'])->count();
        
        $totalWaiting = QueueEntry::where('status', 'WAITING')
            ->whereHas('event', function ($query) {
                $query->whereIn('status', ['OPEN', 'PAUSED']);
            })->count();

        $totalServing = QueueEntry::where('status', 'SERVING')
            ->whereHas('event', function ($query) {
                $query->whereIn('status', ['OPEN', 'PAUSED']);
            })->count();

        $completedToday = QueueEntry::where('status', 'COMPLETED')
            ->whereDate('completed_at', now()->toDateString())
            ->count();

        return [
            Stat::make('Event Aktif', $activeEvents)
                ->description('Event status OPEN atau PAUSED')
                ->descriptionIcon('heroicon-m-calendar')
                ->color('primary'),

            Stat::make('Total Menunggu', $totalWaiting . ' orang')
                ->description('Daftar tunggu antrean berjalan')
                ->descriptionIcon('heroicon-m-users')
                ->color('warning'),

            Stat::make('Sedang Foto', $totalServing . ' antrean')
                ->description('Antrean yang sedang berfoto')
                ->descriptionIcon('heroicon-m-camera')
                ->color('success'),

            Stat::make('Selesai Hari Ini', $completedToday . ' foto')
                ->description('Total sesi foto diselesaikan hari ini')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('gray'),
        ];
    }
}
