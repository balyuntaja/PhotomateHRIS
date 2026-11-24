<?php

namespace App\Services;

use App\Models\BoothSessionHpp;
use App\Models\ExpressEvent;
use App\Models\ExpressEventHpp;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HppDashboardService
{
    /**
     * Hitung ringkasan statistik HPP terintegrasi untuk seluruh cabang pada periode tertentu.
     *
     * @return array{
     *     period_label: string,
     *     total_hpp_all: int,
     *     total_hpp_express: int,
     *     total_hpp_janus: int,
     *     total_hpp_golio: int,
     *     confirmed_count: int,
     *     pending_count: int,
     *     unreconciled_count: int,
     *     branches: array<string, array<string, mixed>>
     * }
     */
    public function getSummary(Carbon $start, Carbon $end): array
    {
        // 1. Hitung Express
        $expressStats = $this->getExpressSummary($start, $end);

        // 2. Hitung Janus
        $janusStats = $this->getBoothSummary(BoothSessionHpp::BOOTH_JANUS, $start, $end);

        // 3. Hitung Golio
        $golioStats = $this->getBoothSummary(BoothSessionHpp::BOOTH_GOLIO, $start, $end);

        $totalHppAll = $expressStats['total_hpp'] + $janusStats['total_hpp'] + $golioStats['total_hpp'];
        $confirmedCount = $expressStats['confirmed_count'] + $janusStats['confirmed_count'] + $golioStats['confirmed_count'];
        $pendingCount = $expressStats['pending_count'] + $janusStats['pending_count'] + $golioStats['pending_count'];
        $unreconciledCount = $expressStats['unreconciled_count'] + $janusStats['unreconciled_count'] + $golioStats['unreconciled_count'];

        return [
            'period_label' => $start->format('d M Y') . ' — ' . $end->format('d M Y'),
            'total_hpp_all' => $totalHppAll,
            'total_hpp_express' => $expressStats['total_hpp'],
            'total_hpp_janus' => $janusStats['total_hpp'],
            'total_hpp_golio' => $golioStats['total_hpp'],
            'confirmed_count' => $confirmedCount,
            'pending_count' => $pendingCount,
            'unreconciled_count' => $unreconciledCount,
            'branches' => [
                'Express' => $expressStats,
                'Janus' => $janusStats,
                'Golio' => $golioStats,
            ],
        ];
    }

    /**
     * Hitung ringkasan HPP Express berdasarkan tanggal operasional rekap sesi.
     *
     * @return array{
     *     booth: string,
     *     total_hpp: int,
     *     confirmed_count: int,
     *     pending_count: int,
     *     unreconciled_count: int,
     *     total_sheets: int,
     *     total_sessions: int,
     *     total_revenue: int
     * }
     */
    public function getExpressSummary(Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('express_event_recap_allocations')) {
            return [
                'booth' => 'Express',
                'total_hpp' => 0,
                'confirmed_count' => 0,
                'pending_count' => 0,
                'unreconciled_count' => 0,
                'total_sheets' => 0,
                'total_sessions' => 0,
                'total_revenue' => 0,
            ];
        }

        // Ambil data alokasi rekap sesi yang jatuh pada periode ini
        $allocations = DB::table('express_event_recap_allocations')
            ->join('express_event_recaps', 'express_event_recaps.id', '=', 'express_event_recap_allocations.express_event_recap_id')
            ->join('express_events', 'express_events.id', '=', 'express_event_recaps.express_event_id')
            ->join('session_reports', 'session_reports.id', '=', 'express_event_recaps.session_report_id')
            ->whereNull('express_events.deleted_at')
            ->whereBetween('session_reports.report_date', [$start->toDateString(), $end->toDateString()])
            ->select(
                'express_events.id as event_id',
                'express_events.hpp_status',
                'express_events.reconciliation_status',
                'express_events.hpp_rate_per_sheet',
                'express_event_recap_allocations.allocated_sheets',
                'express_event_recap_allocations.allocated_sessions',
                'express_event_recap_allocations.allocated_revenue'
            )
            ->get();

        $eventsMap = [];
        $totalHpp = 0;
        $totalSheets = 0;
        $totalSessions = 0;
        $totalRevenue = 0;

        foreach ($allocations as $row) {
            $eventId = (int) $row->event_id;
            if (! isset($eventsMap[$eventId])) {
                $eventsMap[$eventId] = [
                    'hpp_status' => $row->hpp_status,
                    'reconciliation_status' => $row->reconciliation_status,
                    'rate' => $row->hpp_rate_per_sheet !== null ? (float) $row->hpp_rate_per_sheet : null,
                    'sheets' => 0,
                ];
            }
            $eventsMap[$eventId]['sheets'] += (int) $row->allocated_sheets;
            $totalSheets += (int) $row->allocated_sheets;
            $totalSessions += (int) $row->allocated_sessions;
            $totalRevenue += (int) $row->allocated_revenue;
        }

        $confirmedEvents = 0;
        $pendingEvents = 0;
        $unreconciledEvents = 0;

        foreach ($eventsMap as $eventId => $eData) {
            $isConfirmed = in_array($eData['hpp_status'], [ExpressEvent::HPP_CONFIRMED, ExpressEvent::HPP_POSTED], true);
            if ($isConfirmed && $eData['rate'] !== null) {
                $confirmedEvents++;
                $totalHpp += (int) round($eData['sheets'] * $eData['rate']);
            } else {
                $pendingEvents++;
            }

            if ($eData['reconciliation_status'] !== ExpressEvent::RECON_RECONCILED) {
                $unreconciledEvents++;
            }
        }

        // Tambahkan event dalam periode tanpa alokasi
        $unallocatedEvents = ExpressEvent::whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('id', array_keys($eventsMap))
            ->get();

        foreach ($unallocatedEvents as $ue) {
            $pendingEvents++;
            if ($ue->reconciliation_status !== ExpressEvent::RECON_RECONCILED) {
                $unreconciledEvents++;
            }
        }

        return [
            'booth' => 'Express',
            'total_hpp' => $totalHpp,
            'confirmed_count' => $confirmedEvents,
            'pending_count' => $pendingEvents,
            'unreconciled_count' => $unreconciledEvents,
            'total_sheets' => $totalSheets,
            'total_sessions' => $totalSessions,
            'total_revenue' => $totalRevenue,
        ];
    }

    /**
     * Hitung ringkasan HPP untuk booth per lembar (Janus / Golio).
     *
     * @return array{
     *     booth: string,
     *     total_hpp: int,
     *     confirmed_count: int,
     *     pending_count: int,
     *     unreconciled_count: int,
     *     total_sheets: int,
     *     total_sessions: int,
     *     total_revenue: int
     * }
     */
    public function getBoothSummary(string $boothType, Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('booth_session_hpp')) {
            return [
                'booth' => $boothType,
                'total_hpp' => 0,
                'confirmed_count' => 0,
                'pending_count' => 0,
                'unreconciled_count' => 0,
                'total_sheets' => 0,
                'total_sessions' => 0,
                'total_revenue' => 0,
            ];
        }

        $records = BoothSessionHpp::query()
            ->forBooth($boothType)
            ->inPeriod($start, $end)
            ->get();

        $totalHpp = 0;
        $confirmedCount = 0;
        $pendingCount = 0;
        $unreconciledCount = 0;
        $totalSheets = 0;
        $totalSessions = 0;
        $totalRevenue = 0;

        foreach ($records as $record) {
            $totalSheets += $record->total_sheets;
            $totalSessions += $record->total_sessions;
            $totalRevenue += (int) $record->revenue;

            if ($record->isConfirmed()) {
                $confirmedCount++;
                $totalHpp += (int) $record->total_hpp;
            } else {
                $pendingCount++;
            }

            if ($record->reconciliation_status !== BoothSessionHpp::RECON_BALANCED) {
                $unreconciledCount++;
            }
        }

        return [
            'booth' => $boothType,
            'total_hpp' => $totalHpp,
            'confirmed_count' => $confirmedCount,
            'pending_count' => $pendingCount,
            'unreconciled_count' => $unreconciledCount,
            'total_sheets' => $totalSheets,
            'total_sessions' => $totalSessions,
            'total_revenue' => $totalRevenue,
        ];
    }
}
