<?php

namespace App\Observers;

use App\Models\SessionReport;
use App\Services\TransactionAccountingService;
use Illuminate\Support\Facades\Auth;

class SessionReportObserver
{
    public function __construct(
        protected TransactionAccountingService $accountingService
    ) {}

    public function updated(SessionReport $report): void
    {
        if ($report->wasChanged('status')) {
            if ($report->getOriginal('status') === SessionReport::STATUS_APPROVED && $report->status !== SessionReport::STATUS_APPROVED) {
                $this->accountingService->reverseSessionReport(
                    $report,
                    "Status rekap sesi #{$report->report_number} diubah dari approved menjadi {$report->status}",
                    Auth::user()
                );
            } elseif ($report->status === SessionReport::STATUS_APPROVED) {
                $this->accountingService->syncSessionReport($report, Auth::user());
            }
        }
    }

    public function deleted(SessionReport $report): void
    {
        $this->accountingService->reverseSessionReport(
            $report,
            "Rekap sesi #{$report->report_number} dihapus",
            Auth::user()
        );
    }
}
