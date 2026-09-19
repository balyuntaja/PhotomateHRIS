<?php

namespace App\Filament\Resources\SessionHistoryResource\Pages;

use App\Filament\Resources\SessionHistoryResource;
use App\Models\SessionReport;
use App\Services\SessionWorkflowService;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListSessionHistories extends ListRecords
{
    protected static string $resource = SessionHistoryResource::class;

    protected static ?string $title = 'Riwayat Rekap Sesi';

    protected function getHeaderActions(): array
    {
        $actions = [];

        // Export is available for Supervisor / Admin (or all authorized staff)
        $user = Auth::user();
        $isSupervisorOrAdmin = $user && app(SessionWorkflowService::class)->isSupervisorOrAdmin($user);

        if ($isSupervisorOrAdmin) {
            $actions[] = Actions\Action::make('export')
                ->label('Export Excel / CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(function (): StreamedResponse {
                    // Get query conforming to current table filters and search
                    $records = $this->getFilteredTableQuery()->with(['crews', 'transactions', 'submitter', 'approver', 'cabang'])->get();

                    $filename = 'rekap-sesi-photomate-' . now()->format('Ymd-His') . '.csv';

                    return response()->streamDownload(function () use ($records) {
                        $handle = fopen('php://output', 'w');

                        // UTF-8 BOM for Microsoft Excel auto-detection
                        fputs($handle, "\xEF\xBB\xBF");

                        // CSV Headers as required by business spec
                        fputcsv($handle, [
                            'Tanggal',
                            'Nomor Rekap',
                            'Cabang',
                            'Crew',
                            'Payment Method',
                            'Jumlah Sesi',
                            'Nominal',
                            'Bonus Cabang (Rp)',
                            'Bonus per Crew (Rp)',
                            'Status',
                            'Submitted At',
                            'Approved At',
                            'Approved By',
                            'Catatan',
                        ]);

                        foreach ($records as $report) {
                            $cabangName = $report->cabang?->nama_cabang ?? '-';
                            $crewNames = $report->crews->pluck('nama_lengkap')->join(', ');
                            $submittedAt = $report->submitted_at ? $report->submitted_at->format('Y-m-d H:i:s') : '-';
                            $approvedAt = $report->approved_at ? $report->approved_at->format('Y-m-d H:i:s') : '-';
                            $approvedBy = $report->approver?->nama_lengkap ?? '-';

                            $bonusAmount = $report->isNewspaperJanus() ? (int) $report->bonus_amount : 0;
                            $crewCount = $report->crews->count();
                            $bonusPerCrew = ($bonusAmount > 0 && $crewCount > 0) ? (int) round($bonusAmount / $crewCount) : 0;

                            $transactions = $report->transactions;
                            if ($transactions->isNotEmpty()) {
                                foreach ($transactions as $trx) {
                                    fputcsv($handle, [
                                        $report->report_date ? $report->report_date->format('Y-m-d') : '-',
                                        $report->report_number,
                                        $cabangName,
                                        $crewNames,
                                        $trx->payment_method_label,
                                        $trx->session_count,
                                        $trx->amount,
                                        $bonusAmount,
                                        $bonusPerCrew,
                                        $report->status_label,
                                        $submittedAt,
                                        $approvedAt,
                                        $approvedBy,
                                        $trx->notes ?: '-',
                                    ]);
                                }
                            } else {
                                fputcsv($handle, [
                                    $report->report_date ? $report->report_date->format('Y-m-d') : '-',
                                    $report->report_number,
                                    $cabangName,
                                    $crewNames,
                                    '-',
                                    $report->total_sessions,
                                    $report->grand_total_amount,
                                    $bonusAmount,
                                    $bonusPerCrew,
                                    $report->status_label,
                                    $submittedAt,
                                    $approvedAt,
                                    $approvedBy,
                                    '-',
                                ]);
                            }
                        }

                        fclose($handle);
                    }, $filename, [
                        'Content-Type' => 'text/csv; charset=UTF-8',
                        'Content-Disposition' => "attachment; filename=\"$filename\"",
                    ]);
                });
        }

        return $actions;
    }
}
