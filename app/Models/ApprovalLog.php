<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalLog extends Model
{
    use HasFactory;

    protected $table = 'approval_logs';

    public $timestamps = false;

    protected $fillable = [
        'session_report_id',
        'user_id',
        'action',
        'note',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function sessionReport(): BelongsTo
    {
        return $this->belongsTo(SessionReport::class, 'session_report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'user_id', 'karyawan_id');
    }

    public function getActionLabelAttribute(): string
    {
        return match ($this->action) {
            'DRAFT_CREATED' => 'Draft Dibuat',
            'DRAFT_UPDATED' => 'Draft Diperbarui',
            'SUBMITTED' => 'Diajukan (Submitted)',
            'REVISION_REQUESTED' => 'Revisi Diminta',
            'RESUBMITTED' => 'Diajukan Ulang (Resubmitted)',
            'APPROVED' => 'Disetujui (Approved)',
            'REJECTED' => 'Ditolak (Rejected)',
            default => $this->action,
        };
    }

    public function getActionColorAttribute(): string
    {
        return match ($this->action) {
            'DRAFT_CREATED', 'DRAFT_UPDATED' => 'gray',
            'SUBMITTED', 'RESUBMITTED' => 'warning',
            'REVISION_REQUESTED' => 'danger',
            'APPROVED' => 'success',
            'REJECTED' => 'danger',
            default => 'primary',
        };
    }
}
