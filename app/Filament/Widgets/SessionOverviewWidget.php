<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SessionApprovalResource;
use App\Filament\Resources\SessionHistoryResource;
use App\Filament\Resources\SessionReportResource;
use App\Models\SessionReport;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SessionOverviewWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '15s';

    protected static ?int $sort = 0; // Display prominently at the top

    protected int | string | array $columnSpan = 'full';

    public string $filter = 'all'; // 'today', 'this_week', 'this_month', 'all'

    protected function getColumns(): int
    {
        return 4;
    }

    public function getFilteredReportsQuery()
    {
        $query = SessionReport::query();

        return match ($this->filter) {
            'today' => $query->whereDate('report_date', Carbon::today()),
            'this_week' => $query->whereBetween('report_date', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]),
            'this_month' => $query->whereBetween('report_date', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()]),
            default => $query,
        };
    }

    public function getViewData(): array
    {
        $query = $this->getFilteredReportsQuery();

        // Revenue & sessions are counted primarily from APPROVED reports
        $approvedReports = (clone $query)->where('status', SessionReport::STATUS_APPROVED);

        $totalSessions = (int) $approvedReports->sum('total_sessions');
        $grandTotalRevenue = (int) $approvedReports->sum('grand_total_amount');
        $qrisRevenue = (int) $approvedReports->sum('total_qris_amount');
        $qrisSessions = (int) $approvedReports->sum('total_qris_sessions');
        $cashRevenue = (int) $approvedReports->sum('total_cash_amount');
        $cashSessions = (int) $approvedReports->sum('total_cash_sessions');

        // Status counts for approval pipeline
        $waitingApprovalCount = (clone $query)->where('status', SessionReport::STATUS_WAITING_APPROVAL)->count();
        $revisionCount = (clone $query)->where('status', SessionReport::STATUS_REVISION)->count();
        $approvedCount = (clone $query)->where('status', SessionReport::STATUS_APPROVED)->count();
        $draftCount = (clone $query)->where('status', SessionReport::STATUS_DRAFT)->count();

        return [
            'totalSessions' => $totalSessions,
            'grandTotalRevenue' => $grandTotalRevenue,
            'qrisRevenue' => $qrisRevenue,
            'qrisSessions' => $qrisSessions,
            'cashRevenue' => $cashRevenue,
            'cashSessions' => $cashSessions,
            'waitingApprovalCount' => $waitingApprovalCount,
            'revisionCount' => $revisionCount,
            'approvedCount' => $approvedCount,
            'draftCount' => $draftCount,
        ];
    }

    protected function getStats(): array
    {
        $data = $this->getViewData();

        $historyUrl = null;
        $approvalUrl = null;
        $rekapUrl = null;

        try {
            $historyUrl = SessionHistoryResource::getUrl('index');
            $approvalUrl = SessionApprovalResource::getUrl('index');
            $rekapUrl = SessionReportResource::getUrl('index');
        } catch (\Throwable $e) {
            // In testing or without session guard fallback
        }

        return [
            Stat::make('Total Sesi', number_format($data['totalSessions']) . ' Sesi')
                ->description('Total sesi foto disetujui')
                ->descriptionIcon('heroicon-m-camera')
                ->color('primary')
                ->url($historyUrl),

            Stat::make('Total Revenue', 'Rp ' . number_format($data['grandTotalRevenue'], 0, ',', '.'))
                ->description('Pendapatan sesi approved')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success')
                ->url($historyUrl),

            Stat::make('QRIS', 'Rp ' . number_format($data['qrisRevenue'], 0, ',', '.'))
                ->description(number_format($data['qrisSessions']) . ' Sesi non-tunai')
                ->descriptionIcon('heroicon-m-qr-code')
                ->color('info')
                ->url($historyUrl),

            Stat::make('Tunai', 'Rp ' . number_format($data['cashRevenue'], 0, ',', '.'))
                ->description(number_format($data['cashSessions']) . ' Sesi tunai/fisik')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('warning')
                ->url($historyUrl),

            Stat::make('Waiting Approval', (string) $data['waitingApprovalCount'])
                ->description('Menunggu pemeriksaan supervisor')
                ->descriptionIcon('heroicon-m-clock')
                ->color($data['waitingApprovalCount'] > 0 ? 'warning' : 'gray')
                ->url($approvalUrl),

            Stat::make('Perlu Revisi', (string) $data['revisionCount'])
                ->description('Menunggu perbaikan crew')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color($data['revisionCount'] > 0 ? 'danger' : 'gray')
                ->url($rekapUrl),

            Stat::make('Approved', (string) $data['approvedCount'])
                ->description('Rekap valid & disetujui')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success')
                ->url($rekapUrl),

            Stat::make('Draft', (string) $data['draftCount'])
                ->description('Draft belum diajukan')
                ->descriptionIcon('heroicon-m-pencil-square')
                ->color('gray')
                ->url($rekapUrl),
        ];
    }
}
