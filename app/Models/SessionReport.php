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

    const INPUT_WINDOW_DAYS = 2;

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

            static::assertInputWindowOpen($model);
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

            static::assertInputWindowOpen($model);
        });

        static::deleting(function ($model) {
            /** @var \App\Models\Karyawan|null $user */
            $user = \Illuminate\Support\Facades\Auth::user();
            $isSuperAdmin = $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

            if ($model->status === self::STATUS_APPROVED && !$isSuperAdmin) {
                throw new \RuntimeException('Data rekap sesi yang telah disetujui (APPROVED) tidak dapat dihapus.');
            }

            static::assertInputWindowOpen($model);
        });
    }

    /**
     * Batas waktu input/edit/delete rekap sesi: tanggal rekap + 2 hari pukul 23:59:59 (WIB).
     */
    public static function inputDeadlineFor($date): ?Carbon
    {
        if (blank($date)) {
            return null;
        }

        return Carbon::parse($date)
            ->copy()
            ->setTimezone(config('session_reports.timezone'))
            ->startOfDay()
            ->addDays(self::INPUT_WINDOW_DAYS)
            ->setTime(23, 59, 59);
    }

    public static function inputWindowClosedFor($date): bool
    {
        return (bool) static::inputDeadlineFor($date)?->isPast();
    }

    /**
     * Tanggal rekap paling lama yang masih boleh diinput crew pada hari ini.
     */
    public static function earliestInputDate(): Carbon
    {
        return Carbon::now(config('session_reports.timezone'))
            ->startOfDay()
            ->subDays(self::INPUT_WINDOW_DAYS);
    }

    public static function inputClosedMessageFor($date): string
    {
        $deadline = static::inputDeadlineFor($date);

        return sprintf(
            'Rekap sudah ditutup. Batas input dan perubahan rekap untuk tanggal %s adalah %s.',
            Carbon::parse($date)->translatedFormat('d F Y'),
            $deadline ? $deadline->translatedFormat('d F Y') . ' pukul ' . $deadline->format('H:i') : '-'
        );
    }

    public function inputDeadline(): ?Carbon
    {
        return static::inputDeadlineFor($this->report_date);
    }

    public function inputDeadlineDescription(): ?string
    {
        $deadline = $this->inputDeadline();

        return $deadline ? $deadline->translatedFormat('d F Y') . ' pukul ' . $deadline->format('H:i') : null;
    }

    public function isPastInputDeadline(): bool
    {
        return static::inputWindowClosedFor($this->report_date);
    }

    public function inputClosedMessage(): string
    {
        return static::inputClosedMessageFor($this->report_date);
    }

    protected static function assertInputWindowOpen($model): void
    {
        /** @var \App\Models\Karyawan|null $user */
        $user = \Illuminate\Support\Facades\Auth::user();

        if (!$user || !app(\App\Services\SessionWorkflowService::class)->isInputClosed($user, $model->report_date)) {
            return;
        }

        throw new \RuntimeException($model->inputClosedMessage());
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
