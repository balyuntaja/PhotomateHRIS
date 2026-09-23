<?php

namespace App\Services;

use App\Models\ApprovalLog;
use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Notifications\SessionApprovedNotification;
use App\Notifications\SessionRevisionNotification;
use App\Notifications\SessionSubmittedNotification;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as EmailNotification;
use InvalidArgumentException;
use RuntimeException;

class SessionWorkflowService
{
    protected PricingService $pricingService;

    public function __construct(PricingService $pricingService)
    {
        $this->pricingService = $pricingService;
    }

    /**
     * Check if a user has Supervisor or Admin / Owner privilege.
     */
    public function isSupervisorOrAdmin(Karyawan $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Check role ID: R01 (Admin), R06 (CEO), R08 (Supervisor), R03 (Manager HRD), R04 (Manager Finance)
        if (in_array($user->role_id, ['R01', 'R06', 'R08', 'R03', 'R04'])) {
            return true;
        }

        // Check role name
        return $user->hasRole(['Supervisor', 'supervisor', 'Admin', 'admin', 'CEO', 'ceo', 'Manager HRD', 'Manager Finance']);
    }

    /**
     * Batas waktu input/edit/delete rekap sesi (tanggal rekap + 2 hari pukul 23:59:59)
     * hanya berlaku untuk crew (Karyawan). Supervisor/Admin tidak dibatasi.
     */
    public function isInputClosed(?Karyawan $user, $reportDate): bool
    {
        if (!$user || blank($reportDate)) {
            return false;
        }

        if ($this->isSupervisorOrAdmin($user)) {
            return false;
        }

        return SessionReport::inputWindowClosedFor($reportDate);
    }

    /**
     * Log an action to approval_logs table.
     */
    public function logAction(SessionReport $report, Karyawan $user, string $action, ?string $note = null): ApprovalLog
    {
        return ApprovalLog::create([
            'session_report_id' => $report->id,
            'user_id' => $user->karyawan_id,
            'action' => $action,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    /**
     * Crew submits a report for approval.
     */
    public function submit(SessionReport $report, Karyawan $user, ?string $note = null): SessionReport
    {
        if ($report->status === SessionReport::STATUS_APPROVED) {
            throw new RuntimeException('Rekap sesi yang sudah disetujui tidak dapat disubmit kembali.');
        }

        return DB::transaction(function () use ($report, $user, $note) {
            $this->pricingService->recalculateReport($report);

            if ($report->transactions()->count() === 0) {
                throw new InvalidArgumentException('Minimal harus ada 1 transaksi untuk mengajukan rekap sesi.');
            }

            $isResubmission = $report->status === SessionReport::STATUS_REVISION;

            $report->status = SessionReport::STATUS_WAITING_APPROVAL;
            $report->submitted_by = $user->karyawan_id;
            $report->submitted_at = now();
            $report->saveQuietly();

            $action = $isResubmission ? 'RESUBMITTED' : 'SUBMITTED';
            $this->logAction($report, $user, $action, $note);

            // Notify supervisors
            $supervisors = Karyawan::whereIn('role_id', ['R01', 'R06', 'R08', 'R03'])
                ->orWhereHas('roles', function ($q) {
                    $q->whereIn('name', ['Supervisor', 'Admin', 'CEO', 'Manager HRD']);
                })->get();

            foreach ($supervisors as $supervisor) {
                Notification::make()
                    ->title($isResubmission ? 'Rekap Sesi Diajukan Ulang' : 'Rekap Sesi Baru Diajukan')
                    ->body("Rekap sesi #{$report->report_number} untuk tanggal {$report->report_date->format('d M Y')} menunggu pemeriksaan Anda.")
                    ->warning()
                    ->sendToDatabase($supervisor);
            }

            // Send email notification to supervisors asynchronously via queue
            $validSupervisors = $supervisors->filter(fn ($s) => !empty($s->email));
            if ($validSupervisors->isNotEmpty()) {
                EmailNotification::send($validSupervisors, new SessionSubmittedNotification($report, $isResubmission));
            }

            return $report;
        });
    }

    /**
     * Supervisor requests revision from crew with mandatory reason note.
     */
    public function requestRevision(SessionReport $report, Karyawan $supervisor, string $reason): SessionReport
    {
        if (!$this->isSupervisorOrAdmin($supervisor)) {
            throw new RuntimeException('Anda tidak memiliki hak akses sebagai Supervisor untuk meminta revisi.');
        }

        if ($report->status === SessionReport::STATUS_APPROVED) {
            throw new RuntimeException('Rekap sesi yang sudah disetujui tidak dapat direvisi.');
        }

        if (blank($reason)) {
            throw new InvalidArgumentException('Alasan revisi wajib diisi oleh Supervisor.');
        }

        return DB::transaction(function () use ($report, $supervisor, $reason) {
            $report->status = SessionReport::STATUS_REVISION;
            $report->revision_note = $reason;
            $report->saveQuietly();

            $this->logAction($report, $supervisor, 'REVISION_REQUESTED', $reason);

            // Notify submitter and crew
            $notifiables = collect([$report->submitter])
                ->merge($report->crews)
                ->filter()
                ->unique('karyawan_id');

            foreach ($notifiables as $crew) {
                Notification::make()
                    ->title('Revisi Diperlukan pada Rekap Sesi')
                    ->body("Supervisor meminta revisi untuk rekap #{$report->report_number}: \"$reason\"")
                    ->danger()
                    ->sendToDatabase($crew);
            }

            // Send email notification to submitter and crew asynchronously via queue
            $validNotifiables = $notifiables->filter(fn ($c) => !empty($c->email));
            if ($validNotifiables->isNotEmpty()) {
                EmailNotification::send($validNotifiables, new SessionRevisionNotification($report, $reason, $supervisor->nama_lengkap));
            }

            return $report;
        });
    }

    /**
     * Supervisor approves the report.
     */
    public function approve(SessionReport $report, Karyawan $supervisor, ?string $note = null): SessionReport
    {
        if (!$this->isSupervisorOrAdmin($supervisor)) {
            throw new RuntimeException('Anda tidak memiliki hak akses sebagai Supervisor untuk menyetujui rekap.');
        }

        if ($report->status === SessionReport::STATUS_APPROVED) {
            throw new RuntimeException('Rekap sesi ini sudah berstatus disetujui.');
        }

        return DB::transaction(function () use ($report, $supervisor, $note) {
            $this->pricingService->recalculateReport($report);

            $report->status = SessionReport::STATUS_APPROVED;
            $report->approved_by = $supervisor->karyawan_id;
            $report->approved_at = now();
            $report->saveQuietly();

            $this->logAction($report, $supervisor, 'APPROVED', $note ?: 'Validated.');

            // Notify submitter and crew
            $notifiables = collect([$report->submitter])
                ->merge($report->crews)
                ->filter()
                ->unique('karyawan_id');

            foreach ($notifiables as $crew) {
                Notification::make()
                    ->title('Rekap Sesi Disetujui')
                    ->body("Rekap sesi #{$report->report_number} telah disetujui oleh {$supervisor->nama_lengkap}.")
                    ->success()
                    ->sendToDatabase($crew);
            }

            // Send email notification to submitter and crew asynchronously via queue
            $validNotifiables = $notifiables->filter(fn ($c) => !empty($c->email));
            if ($validNotifiables->isNotEmpty()) {
                EmailNotification::send($validNotifiables, new SessionApprovedNotification($report));
            }

            return $report;
        });
    }
}
