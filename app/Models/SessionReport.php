<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class SessionReport extends Model
{
    use HasFactory;

    protected $table = 'session_reports';

    const STATUS_DRAFT = 'DRAFT';
    const STATUS_WAITING_APPROVAL = 'WAITING_APPROVAL';
    const STATUS_REVISION = 'REVISION';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';

    protected $fillable = [
        'report_number',
        'report_date',
        'status',
        'event_note',
        'cabang_id',
        'invoice_id',
        'event_id',
        'total_sessions',
        'total_transactions',
        'total_cash_amount',
        'total_cash_sessions',
        'total_qris_amount',
        'total_qris_sessions',
        'grand_total_amount',
        'bonus_amount',
        'validation_status',
        'validation_issues',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'revision_note',
    ];

    protected $casts = [
        'report_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'total_sessions' => 'integer',
        'total_transactions' => 'integer',
        'total_cash_amount' => 'integer',
        'total_cash_sessions' => 'integer',
        'total_qris_amount' => 'integer',
        'total_qris_sessions' => 'integer',
        'grand_total_amount' => 'integer',
        'bonus_amount' => 'integer',
        'validation_issues' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->report_number)) {
                $model->report_number = static::generateReportNumber($model->report_date);
            }
        });

        static::updating(function ($model) {
            // Immutability rule: If original was APPROVED, reject modifications to core data
            if ($model->getOriginal('status') === self::STATUS_APPROVED) {
                // Allow only if re-opening via authorized action or identical
                $dirty = array_keys($model->getDirty());
                $allowedDirty = ['updated_at'];
                $disallowed = array_diff($dirty, $allowedDirty);
                if (!empty($disallowed)) {
                    throw new \RuntimeException('Data rekap sesi yang telah disetujui (APPROVED) tidak dapat diubah (immutable).');
                }
            }
        });

        static::deleting(function ($model) {
            if ($model->status === self::STATUS_APPROVED) {
                throw new \RuntimeException('Data rekap sesi yang telah disetujui (APPROVED) tidak dapat dihapus.');
            }
        });
    }

    public static function generateReportNumber($date = null): string
    {
        $d = $date ? Carbon::parse($date) : now();
        $prefix = 'SR-' . $d->format('Ymd');
        $countToday = static::whereDate('created_at', now()->toDateString())->count() + 1;
        $candidate = $prefix . '-' . str_pad($countToday, 3, '0', STR_PAD_LEFT);

        while (static::where('report_number', $candidate)->exists()) {
            $countToday++;
            $candidate = $prefix . '-' . str_pad($countToday, 3, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    public function crews(): BelongsToMany
    {
        return $this->belongsToMany(
            Karyawan::class,
            'session_report_crews',
            'session_report_id',
            'karyawan_id'
        )->withTimestamps();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SessionTransaction::class, 'session_report_id');
    }

    public function approvalLogs(): HasMany
    {
        return $this->hasMany(ApprovalLog::class, 'session_report_id')->orderBy('created_at', 'desc');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'submitted_by', 'karyawan_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'approved_by', 'karyawan_id');
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class, 'cabang_id', 'cabang_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isWaitingApproval(): bool
    {
        return $this->status === self::STATUS_WAITING_APPROVAL;
    }

    public function isRevision(): bool
    {
        return $this->status === self::STATUS_REVISION;
    }

    public function getCrewNamesAttribute(): string
    {
        return $this->crews->pluck('nama_lengkap')->join(', ') ?: '-';
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_WAITING_APPROVAL => 'Waiting Approval',
            self::STATUS_REVISION => 'Revision',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            default => $this->status,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'gray',
            self::STATUS_WAITING_APPROVAL => 'warning',
            self::STATUS_REVISION => 'danger',
            self::STATUS_APPROVED => 'success',
            self::STATUS_REJECTED => 'danger',
            default => 'primary',
        };
    }

    public function isNewspaperJanus(): bool
    {
        return app(\App\Services\BranchBonusService::class)->isEligibleBranch($this->cabang);
    }

    public function getBonusDetails(): array
    {
        return app(\App\Services\BranchBonusService::class)->calculateBonusForReport($this);
    }

    public function getBonusPerCrewAttribute(): float
    {
        $details = $this->getBonusDetails();
        return (float) ($details['bonus_per_crew_exact'] ?? 0);
    }
}
