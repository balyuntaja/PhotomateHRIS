<?php

namespace App\Policies;

use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Services\SessionWorkflowService;
use Illuminate\Auth\Access\HandlesAuthorization;

class SessionReportPolicy
{
    use HandlesAuthorization;

    protected function isSupervisorOrAdmin(Karyawan $user): bool
    {
        return app(SessionWorkflowService::class)->isSupervisorOrAdmin($user);
    }

    protected function isCrewOnReport(Karyawan $user, SessionReport $report): bool
    {
        if ($report->submitted_by === $user->karyawan_id) {
            return true;
        }

        return $report->crews()->where('karyawan.karyawan_id', $user->karyawan_id)->exists();
    }

    /**
     * Batas waktu input/edit/delete (tanggal rekap + 2 hari 23:59:59) hanya untuk crew.
     */
    protected function isInputClosed(Karyawan $user, SessionReport $report): bool
    {
        return app(SessionWorkflowService::class)->isInputClosed($user, $report->report_date);
    }

    public function viewAny(Karyawan $user): bool
    {
        return true;
    }

    public function view(Karyawan $user, SessionReport $report): bool
    {
        if ($this->isSupervisorOrAdmin($user)) {
            return true;
        }

        return $this->isCrewOnReport($user, $report);
    }

    public function create(Karyawan $user): bool
    {
        return true;
    }

    public function update(Karyawan $user, SessionReport $report): bool
    {
        // Strictly immutable if APPROVED
        if ($report->isApproved()) {
            return false;
        }

        // Crew tidak boleh mengubah rekap yang sudah melewati batas waktu input
        if ($this->isInputClosed($user, $report)) {
            return false;
        }

        if ($this->isSupervisorOrAdmin($user)) {
            return true;
        }

        // Crew can only edit when DRAFT or REVISION
        if (in_array($report->status, [SessionReport::STATUS_DRAFT, SessionReport::STATUS_REVISION])) {
            return $this->isCrewOnReport($user, $report);
        }

        return false;
    }

    public function delete(Karyawan $user, SessionReport $report): bool
    {
        // Super Admin can delete any report regardless of status
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Cannot delete approved reports for non-superadmin
        if ($report->isApproved()) {
            return false;
        }

        // Crew tidak boleh menghapus rekap yang sudah melewati batas waktu input
        if ($this->isInputClosed($user, $report)) {
            return false;
        }

        if ($this->isSupervisorOrAdmin($user)) {
            return true;
        }

        // Crew can delete own draft
        if ($report->isDraft() && $this->isCrewOnReport($user, $report)) {
            return true;
        }

        return false;
    }

    public function deleteAny(Karyawan $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function approve(Karyawan $user, SessionReport $report): bool
    {
        if ($report->isApproved()) {
            return false;
        }

        return $this->isSupervisorOrAdmin($user);
    }

    public function requestRevision(Karyawan $user, SessionReport $report): bool
    {
        if ($report->isApproved()) {
            return false;
        }

        return $this->isSupervisorOrAdmin($user);
    }
}
