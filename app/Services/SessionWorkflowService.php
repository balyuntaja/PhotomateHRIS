<?php

namespace App\Services;

use App\Models\ApprovalLog;
use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Notifications\SessionApprovedNotification;
use App\Notifications\SessionRevisionNotification;
use App\Notifications\SessionSubmittedNotification;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
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
     * Check if the report belongs to the user (user is submitter or crew member on duty).
     */
    public function isOwnReport(?Karyawan $user, ?SessionReport $report): bool
    {
        if (!$user || !$report) {
            return false;
        }

        if (!empty($report->submitted_by) && (string) $report->submitted_by === (string) $user->karyawan_id) {
            return true;
        }

        if ($report->relationLoaded('crews')) {
            return $report->crews->contains(function ($crew) use ($user) {
                return (string) $crew->karyawan_id === (string) $user->karyawan_id;
            });
        }

        return $report->crews()->where('karyawan.karyawan_id', $user->karyawan_id)->exists();
    }

    /**
     * Determine whether the user can approve or request revision on the report.
     */
    public function canApprove(?Karyawan $user, ?SessionReport $report): bool
    {
        if (!$user || !$report) {
            return false;
        }

        if (!$this->isSupervisorOrAdmin($user)) {
            return false;
        }

        if ($this->isOwnReport($user, $report)) {
            return false;
        }

        return $report->isWaitingApproval();
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
     * Batas waktu untuk mengedit sebuah rekap. Rekap berstatus REVISION dikecualikan
     * agar crew dapat memperbaiki dan mengirim ulang tanpa batas H+2.
     */
    public function isInputClosedForReport(?Karyawan $user, ?SessionReport $report): bool
    {
        if (! $report) {
            return false;
        }

        if ($report->isRevision()) {
            return false;
        }

        return $this->isInputClosed($user, $report->report_date);
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

        // Submit rekap baru tetap dibatasi H+2; rekap REVISION boleh dikirim ulang.
        if ($this->isInputClosedForReport($user, $report)) {
            throw new RuntimeException($report->inputClosedMessage());
        }

        return DB::transaction(function () use ($report, $user, $note) {
            $this->pricingService->recalculateReport($report);

            if ($report->usesManualSessionInput()) {
                if ((int) $report->wedding_total_sessions < 1 || (int) $report->wedding_total_sheets < 1) {
                    throw new InvalidArgumentException('Jumlah Sesi dan Jumlah Lembar wajib diisi minimal 1 untuk cabang ' . ($report->cabang?->nama_cabang ?? 'ini') . '.');
                }
            } elseif ($report->transactions()->count() === 0) {
                throw new InvalidArgumentException('Minimal harus ada 1 transaksi untuk mengajukan rekap sesi.');
            }

            $isResubmission = $report->status === SessionReport::STATUS_REVISION;

            $report->status = SessionReport::STATUS_WAITING_APPROVAL;
            $report->submitted_by = $user->karyawan_id;
            $report->submitted_at = now();
            $report->saveQuietly();

            $action = $isResubmission ? 'RESUBMITTED' : 'SUBMITTED';
            $this->logAction($report, $user, $action, $note);

            if ($isResubmission) {
                app(RevisionNotificationService::class)->markAllRevisionNotificationsAsReadForReport($report);
            }

            // Notify supervisors
            $supervisors = Karyawan::whereIn('role_id', ['R01', 'R06', 'R08', 'R03'])
                ->orWhereHas('roles', function ($q) {
                    $q->whereIn('name', ['Supervisor', 'Admin', 'CEO', 'Manager HRD']);
                })->get();

            $reportDateFormatted = $report->report_date
                ? Carbon::parse($report->report_date)->format('d M Y')
                : '-';

            foreach ($supervisors as $supervisor) {
                Notification::make()
                    ->title($isResubmission ? 'Rekap Sesi Diajukan Ulang' : 'Rekap Sesi Baru Diajukan')
                    ->body("Rekap sesi #{$report->report_number} untuk tanggal {$reportDateFormatted} menunggu pemeriksaan Anda.")
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

        if ($this->isOwnReport($supervisor, $report)) {
            throw new RuntimeException('Anda tidak dapat meminta revisi pada rekap sesi Anda sendiri.');
        }

        if ($report->status === SessionReport::STATUS_APPROVED) {
            throw new RuntimeException('Rekap sesi yang sudah disetujui tidak dapat direvisi.');
        }

        if (blank($reason)) {
            throw new InvalidArgumentException('Alasan revisi wajib diisi oleh Supervisor.');
        }

        return DB::transaction(function () use ($report, $supervisor, $reason) {
            $revisedAt = now();

            $report->status = SessionReport::STATUS_REVISION;
            $report->revision_note = $reason;
            $report->saveQuietly();

            $report->load(['cabang', 'submitter', 'crews']);

            $this->logAction($report, $supervisor, 'REVISION_REQUESTED', $reason);

            $revisionNotificationService = app(RevisionNotificationService::class);
            $revisionNotificationService->sendRevisionNotifications($report, $reason, $supervisor, $revisedAt);

            // Send email notification to submitter and crew asynchronously via queue
            $validNotifiables = $revisionNotificationService
                ->resolveNotifiables($report)
                ->filter(fn ($c) => !empty($c->email));
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

        if ($this->isOwnReport($supervisor, $report)) {
            throw new RuntimeException('Anda tidak dapat menyetujui rekap sesi Anda sendiri.');
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

            // Bonus harian Janus dihitung dari seluruh rekap approved pada hari yang sama,
            // sehingga angka bonus semua rekap hari itu diselaraskan setelah status berubah.
            app(BranchBonusService::class)->syncDailyBonusAmounts($report);

            $this->logAction($report, $supervisor, 'APPROVED', $note ?: 'Validated.');

            // Catat transaksi keuangan otomatis (kategori Pendapatan Booth, ditentukan backend).
            // Bila kategori/mapping gagal, seluruh approval ikut dibatalkan dengan pesan jelas.
            app(SessionReportFinancialService::class)->record($report, $supervisor);

            // Catat pengurangan stok Kertas Foto otomatis khusus Express & Newspaper Janus mulai 7 November 2026.
            app(SessionReportInventoryService::class)->syncForReport($report, $supervisor);

            // Sinkronisasi otomatis ke Manajemen HPP Express apabila rekap Express sudah lengkap Nama & Tanggal Event.
            if ($report->supportsEventDetail() && $report->hasCompleteEventDetail()) {
                app(ExpressEventSyncService::class)->syncSingleReport($report, $supervisor);
            }

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

    /**
     * Super Admin updates or completes only Nama Event (event_note) and Tanggal Event (event_date)
     * without changing report_date, submitted_at, session/sheet totals, revenue, crews,
     * transactions, or approval status/workflow.
     */
    public function updateEventDetail(
        SessionReport $report,
        Karyawan $actor,
        ?string $eventNote,
        Carbon|string|null $eventDate
    ): SessionReport {
        if (! $actor->isSuperAdmin()) {
            throw new RuntimeException('Hanya Super Admin yang diizinkan mengedit detail event pada riwayat rekap sesi.');
        }

        if (! $report->supportsEventDetail()) {
            throw new RuntimeException('Detail event hanya tersedia dan dapat diperbarui untuk cabang Express.');
        }

        $normalizedEventNote = filled($eventNote) ? trim((string) $eventNote) : null;
        $normalizedEventDate = filled($eventDate) ? Carbon::parse($eventDate)->toDateString() : null;

        return DB::transaction(function () use ($report, $actor, $normalizedEventNote, $normalizedEventDate): SessionReport {
            $lockedReport = SessionReport::query()->lockForUpdate()->findOrFail($report->id);

            if (! $lockedReport->supportsEventDetail()) {
                throw new RuntimeException('Detail event hanya tersedia dan dapat diperbarui untuk cabang Express.');
            }

            $oldNote = filled($lockedReport->event_note) ? trim((string) $lockedReport->event_note) : null;
            $oldDate = $lockedReport->event_date?->toDateString();

            if ($oldNote === $normalizedEventNote && $oldDate === $normalizedEventDate) {
                return $lockedReport;
            }

            $lockedReport->forceFill([
                'event_note' => $normalizedEventNote,
                'event_date' => $normalizedEventDate,
            ]);

            $dirty = array_keys($lockedReport->getDirty());
            $disallowed = array_diff($dirty, ['event_note', 'event_date']);
            if ($disallowed !== []) {
                throw new RuntimeException('Pembaruan detail event hanya diizinkan untuk Nama Event dan Tanggal Event.');
            }

            $lockedReport->saveQuietly();

            $formattedDate = $normalizedEventDate
                ? Carbon::parse($normalizedEventDate)->translatedFormat('d F Y')
                : '-';

            $this->logAction(
                $lockedReport,
                $actor,
                'EVENT_DETAIL_UPDATED',
                sprintf(
                    'Detail event diperbarui (Nama Event: %s | Tanggal Event: %s).',
                    $normalizedEventNote ?? '-',
                    $formattedDate
                )
            );

            $freshReport = $lockedReport->fresh(['cabang', 'invoice', 'crews', 'transactions', 'submitter', 'approver', 'approvalLogs']);

            if ($freshReport && $freshReport->isApproved() && $freshReport->supportsEventDetail() && $freshReport->hasCompleteEventDetail()) {
                app(ExpressEventSyncService::class)->syncSingleReport($freshReport, $actor);
            }

            return $freshReport;
        });
    }

    /**
     * Koreksi resmi Total Lembar pada rekap sesi yang sudah Approved (oleh Supervisor / Admin / Super Admin).
     * Menyesuaikan stok Kertas Foto hanya sebesar selisih (delta) tanpa pengurangan penuh ulang.
     */
    public function correctApprovedSheets(
        SessionReport $report,
        Karyawan $actor,
        int $newTotalSheets,
        ?int $newTotalSessions = null,
        ?string $reason = null,
    ): SessionReport {
        if (! $this->isSupervisorOrAdmin($actor)) {
            throw new RuntimeException('Anda tidak memiliki hak akses untuk mengoreksi total lembar rekap sesi.');
        }

        if ($newTotalSheets < 1) {
            throw new InvalidArgumentException('Total Lembar koreksi minimal harus 1 lembar.');
        }

        return DB::transaction(function () use ($report, $actor, $newTotalSheets, $newTotalSessions, $reason): SessionReport {
            $lockedReport = SessionReport::query()->lockForUpdate()->findOrFail($report->id);

            if ($lockedReport->status !== SessionReport::STATUS_APPROVED) {
                throw new RuntimeException('Koreksi total lembar hanya berlaku untuk rekap sesi yang sudah berstatus Approved.');
            }

            $previousSheets = $lockedReport->totalSheetCount();

            if ($lockedReport->usesManualSessionInput()) {
                $sessions = $newTotalSessions !== null
                    ? max(1, $newTotalSessions)
                    : max(1, (int) ($lockedReport->wedding_total_sessions ?: $lockedReport->total_transactions ?: 1));

                $lockedReport->wedding_total_sessions = $sessions;
                $lockedReport->wedding_total_sheets = $newTotalSheets;
                $lockedReport->total_transactions = $sessions;
                $lockedReport->total_sessions = $newTotalSheets;
            } else {
                $lockedReport->total_sessions = $newTotalSheets;
                if ($newTotalSessions !== null) {
                    $lockedReport->total_transactions = max(1, $newTotalSessions);
                }
            }

            $lockedReport->saveQuietly();

            app(BranchBonusService::class)->syncDailyBonusAmounts($lockedReport);

            $noteText = sprintf(
                'Koreksi Total Lembar dari %d menjadi %d lembar.%s',
                $previousSheets,
                $newTotalSheets,
                filled($reason) ? ' Alasan: ' . trim($reason) : ''
            );

            $this->logAction($lockedReport, $actor, 'SHEETS_CORRECTED', $noteText);

            $reasonContext = filled($reason)
                ? trim($reason)
                : sprintf('perubahan %d ke %d lembar', $previousSheets, $newTotalSheets);

            app(SessionReportInventoryService::class)->syncForReport($lockedReport, $actor, $reasonContext);

            return $lockedReport->fresh(['cabang', 'crews', 'transactions', 'inventoryTransactions', 'approvalLogs']);
        });
    }

    /**
     * Batalkan / ubah status rekap sesi yang sudah Approved menjadi tidak valid (Rejected / Revision),
     * dan kembalikan stok Kertas Foto melalui mekanisme transaksi reversal tanpa menghapus histori.
     */
    public function cancelApproval(
        SessionReport $report,
        Karyawan $actor,
        string $reason,
        string $targetStatus = SessionReport::STATUS_REJECTED,
    ): SessionReport {
        if (! $this->isSupervisorOrAdmin($actor)) {
            throw new RuntimeException('Anda tidak memiliki hak akses untuk membatalkan approval rekap sesi.');
        }

        if (blank($reason)) {
            throw new InvalidArgumentException('Alasan pembatalan rekap sesi wajib diisi.');
        }

        $allowedTargetStatuses = [
            SessionReport::STATUS_REJECTED,
            SessionReport::STATUS_REVISION,
            SessionReport::STATUS_DRAFT,
        ];

        if (! in_array($targetStatus, $allowedTargetStatuses, true)) {
            throw new InvalidArgumentException('Status tujuan pembatalan tidak valid.');
        }

        return DB::transaction(function () use ($report, $actor, $reason, $targetStatus): SessionReport {
            $lockedReport = SessionReport::query()->lockForUpdate()->findOrFail($report->id);

            if ($lockedReport->status !== SessionReport::STATUS_APPROVED) {
                throw new RuntimeException('Hanya rekap sesi berstatus Approved yang dapat dibatalkan approval-nya.');
            }

            $trimmedReason = trim($reason);

            $lockedReport->status = $targetStatus;
            $lockedReport->revision_note = $trimmedReason;
            $lockedReport->approved_by = null;
            $lockedReport->approved_at = null;
            $lockedReport->saveQuietly();

            app(BranchBonusService::class)->syncDailyBonusAmounts($lockedReport);

            $this->logAction($lockedReport, $actor, 'APPROVAL_CANCELLED', $trimmedReason);

            app(SessionReportInventoryService::class)->reverseForReport($lockedReport, $actor, $trimmedReason);

            return $lockedReport->fresh(['cabang', 'crews', 'transactions', 'inventoryTransactions', 'approvalLogs']);
        });
    }
}
