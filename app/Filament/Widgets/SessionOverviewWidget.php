<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SessionApprovalResource;
use App\Filament\Resources\SessionHistoryResource;
use App\Filament\Resources\SessionReportResource;
use App\Models\SessionReport;
use App\Utils\RupiahHelper;
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

        // Status counts for approval pipeline
        $waitingApprovalCount = (clone $query)->where('status', SessionReport::STATUS_WAITING_APPROVAL)->count();
        $revisionCount = (clone $query)->where('status', SessionReport::STATUS_REVISION)->count();
        $approvedCount = (clone $query)->where('status', SessionReport::STATUS_APPROVED)->count();
        $draftCount = (clone $query)->where('status', SessionReport::STATUS_DRAFT)->count();

        // 1. Total Sesi dari rekap sesi approved sesuai business logic masing-masing cabang
        $approvedReports = (clone $query)->where('status', SessionReport::STATUS_APPROVED)->with('cabang')->get();
        $inputModeService = app(\App\Services\BranchInputModeService::class);
        $totalSessions = 0;
        foreach ($approvedReports as $report) {
            if ($report->cabang && $inputModeService->usesManualSessionInput($report->cabang)) {
                $totalSessions += (int) ($report->wedding_total_sessions ?: $report->total_transactions ?: $report->total_sessions);
            } else {
                $detailSessionSum = (int) $report->transactions()->sum('session_count');
                $totalSessions += max((int) $report->total_sessions, $detailSessionSum);
            }
        }

        $qrisSessions = (int) $approvedReports->sum('total_qris_sessions');
        $cashSessions = (int) $approvedReports->sum('total_cash_sessions');
        $otherSessions = max(0, $totalSessions - ($qrisSessions + $cashSessions));

        // 2. Pendapatan & Ringkasan Pembayaran dari Transaksi Keuangan (Pendapatan Booth)
        $filterPeriod = match ($this->filter) {
            'today' => [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()],
            'this_week' => [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()],
            'this_month' => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
            default => null,
        };

        $boothQuery = \App\Models\FinancialTransaction::query()->boothIncome();
        if ($filterPeriod) {
            $boothQuery->periode($filterPeriod[0], $filterPeriod[1]);
        }
        $boothTrx = $boothQuery->with('paymentMethod')->get();

        if ($boothTrx->isNotEmpty()) {
            $grandTotalRevenue = (int) $boothTrx->sum('amount');
            $qrisRevenue = 0;
            $cashRevenue = 0;
            $otherRevenue = 0;

            $reportIds = $boothTrx->where('source_type', \App\Services\SessionReportFinancialService::SOURCE)->pluck('source_id')->filter()->unique();
            $reportsMap = $reportIds->isNotEmpty()
                ? SessionReport::query()->whereIn('id', $reportIds)->get()->keyBy(fn ($r) => (string) $r->id)
                : collect();

            foreach ($boothTrx as $trx) {
                $m = $trx->paymentMethod ? strtoupper(trim($trx->paymentMethod->name)) : null;
                if ($m === 'QRIS') {
                    $qrisRevenue += (int) $trx->amount;
                } elseif (in_array($m, ['CASH', 'TUNAI'], true)) {
                    $cashRevenue += (int) $trx->amount;
                } elseif ($m !== null) {
                    $otherRevenue += (int) $trx->amount;
                } else {
                    $rep = $reportsMap->get((string) $trx->source_id);
                    if ($rep) {
                        $q = (int) $rep->total_qris_amount;
                        $c = (int) $rep->total_cash_amount;
                        $qrisRevenue += $q;
                        $cashRevenue += $c;
                        $otherRevenue += max(0, (int) $trx->amount - ($q + $c));
                    } elseif ($trx->source_type === 'luminash') {
                        $qrisRevenue += (int) $trx->amount;
                    } else {
                        $otherRevenue += (int) $trx->amount;
                    }
                }
            }
            $effectiveTotalRevenue = $grandTotalRevenue;
        } else {
            $grandTotalRevenue = (int) $approvedReports->sum('grand_total_amount');
            $qrisRevenue = (int) $approvedReports->sum('total_qris_amount');
            $cashRevenue = (int) $approvedReports->sum('total_cash_amount');
            $otherRevenue = max(0, $grandTotalRevenue - ($qrisRevenue + $cashRevenue));
            $effectiveTotalRevenue = max($grandTotalRevenue, $qrisRevenue + $cashRevenue + $otherRevenue);
        }

        return [
            'totalSessions' => $totalSessions,
            'grandTotalRevenue' => $effectiveTotalRevenue,
            'qrisRevenue' => $qrisRevenue,
            'qrisSessions' => $qrisSessions,
            'cashRevenue' => $cashRevenue,
            'cashSessions' => $cashSessions,
            'otherRevenue' => $otherRevenue,
            'otherSessions' => $otherSessions,
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

            Stat::make('Total Revenue', RupiahHelper::format($data['grandTotalRevenue']))
                ->description('Pendapatan sesi approved')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success')
                ->url($historyUrl),

            Stat::make('QRIS', RupiahHelper::format($data['qrisRevenue']))
                ->description(number_format($data['qrisSessions']) . ' Sesi non-tunai')
                ->descriptionIcon('heroicon-m-qr-code')
                ->color('info')
                ->url($historyUrl),

            Stat::make('Tunai', RupiahHelper::format($data['cashRevenue']))
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
