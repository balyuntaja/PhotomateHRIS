<?php

namespace App\Models;

use App\Utils\RupiahHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpressEvent extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'express_events';

    public const RECON_UNALLOCATED = 'unallocated';
    public const RECON_PARTIAL = 'partial';
    public const RECON_FULLY_ALLOCATED = 'fully_allocated';
    public const RECON_NEEDS_REVIEW = 'needs_review';
    public const RECON_RECONCILED = 'reconciled';

    public const RECON_LABELS = [
        self::RECON_UNALLOCATED => 'Belum Dialokasikan',
        self::RECON_PARTIAL => 'Alokasi Sebagian',
        self::RECON_FULLY_ALLOCATED => 'Dialokasikan Lengkap',
        self::RECON_NEEDS_REVIEW => 'Perlu Ditinjau',
        self::RECON_RECONCILED => 'Terekonsiliasi',
    ];

    public const RECON_COLORS = [
        self::RECON_UNALLOCATED => 'gray',
        self::RECON_PARTIAL => 'warning',
        self::RECON_FULLY_ALLOCATED => 'info',
        self::RECON_NEEDS_REVIEW => 'danger',
        self::RECON_RECONCILED => 'success',
    ];

    public const HPP_PENDING = 'pending';
    public const HPP_CALCULATED = 'calculated';
    public const HPP_CONFIRMED = 'confirmed';
    public const HPP_POSTED = 'posted';
    public const HPP_REVERSED = 'reversed';

    public const HPP_LABELS = [
        self::HPP_PENDING => 'Menunggu HPP',
        self::HPP_CALCULATED => 'Dihitung (Preview)',
        self::HPP_CONFIRMED => 'Terkonfirmasi',
        self::HPP_POSTED => 'Diposting (Jurnal)',
        self::HPP_REVERSED => 'Dibalik (Reversed)',
    ];

    public const HPP_COLORS = [
        self::HPP_PENDING => 'gray',
        self::HPP_CALCULATED => 'warning',
        self::HPP_CONFIRMED => 'primary',
        self::HPP_POSTED => 'success',
        self::HPP_REVERSED => 'danger',
    ];

    protected $fillable = [
        'event_name',
        'start_date',
        'end_date',
        'hpp_rate_per_sheet',
        'reconciliation_status',
        'hpp_status',
        'notes',
        'created_by',
        'confirmed_by',
        'confirmed_at',
        'finalized_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'hpp_rate_per_sheet' => 'integer',
        'confirmed_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function recaps(): HasMany
    {
        return $this->hasMany(ExpressEventRecap::class, 'express_event_id');
    }

    public function sessionReports(): BelongsToMany
    {
        return $this->belongsToMany(
            SessionReport::class,
            'express_event_recaps',
            'express_event_id',
            'session_report_id'
        )->withPivot(['id', 'status'])->withTimestamps();
    }

    public function allocations(): HasManyThrough
    {
        return $this->hasManyThrough(
            ExpressEventRecapAllocation::class,
            ExpressEventRecap::class,
            'express_event_id',
            'express_event_recap_id'
        );
    }

    public function hppSnapshots(): HasMany
    {
        return $this->hasMany(ExpressEventHpp::class, 'express_event_id')->orderByDesc('version');
    }

    public function latestHpp(): HasOne
    {
        return $this->hasOne(ExpressEventHpp::class, 'express_event_id')->latestOfMany('version');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ExpressEventAuditLog::class, 'express_event_id')->orderByDesc('created_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'created_by', 'karyawan_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'confirmed_by', 'karyawan_id');
    }

    public function isConfirmed(): bool
    {
        return in_array($this->hpp_status, [self::HPP_CONFIRMED, self::HPP_POSTED], true);
    }

    public function isPosted(): bool
    {
        return $this->hpp_status === self::HPP_POSTED;
    }

    public function isFinalized(): bool
    {
        return !is_null($this->finalized_at);
    }

    public function hasExplicitRate(): bool
    {
        return !is_null($this->hpp_rate_per_sheet);
    }

    public function getTotalAllocatedSessionsAttribute(): int
    {
        return (int) $this->allocations()->sum('allocated_sessions');
    }

    public function getTotalAllocatedSheetsAttribute(): int
    {
        return (int) $this->allocations()->sum('allocated_sheets');
    }

    public function getTotalAllocatedRevenueAttribute(): int
    {
        return (int) $this->allocations()->sum('allocated_revenue');
    }

    public function getCalculatedHppAttribute(): int
    {
        if ($this->hpp_rate_per_sheet === null) {
            return 0;
        }

        return (int) ($this->total_allocated_sheets * $this->hpp_rate_per_sheet);
    }

    public function getGrossProfitAttribute(): int
    {
        return (int) ($this->total_allocated_revenue - $this->calculated_hpp);
    }

    public function getReconciliationLabelAttribute(): string
    {
        return self::RECON_LABELS[$this->reconciliation_status] ?? $this->reconciliation_status;
    }

    public function getReconciliationColorAttribute(): string
    {
        return self::RECON_COLORS[$this->reconciliation_status] ?? 'gray';
    }

    public function getHppStatusLabelAttribute(): string
    {
        return self::HPP_LABELS[$this->hpp_status] ?? $this->hpp_status;
    }

    public function getHppStatusColorAttribute(): string
    {
        return self::HPP_COLORS[$this->hpp_status] ?? 'gray';
    }

    public function getFormattedRateAttribute(): string
    {
        if ($this->hpp_rate_per_sheet === null) {
            return '-';
        }

        return RupiahHelper::format($this->hpp_rate_per_sheet);
    }

    public function getFormattedTotalHppAttribute(): string
    {
        if ($this->hpp_rate_per_sheet === null) {
            return '-';
        }

        return RupiahHelper::format($this->calculated_hpp);
    }

    public function getFormattedGrossProfitAttribute(): string
    {
        return RupiahHelper::format($this->gross_profit);
    }

    public function getFormattedRevenueAttribute(): string
    {
        return RupiahHelper::format($this->total_allocated_revenue);
    }
}
