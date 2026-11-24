<?php

namespace App\Models;

use App\Utils\RupiahHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $session_report_id
 * @property string $booth_type
 * @property string|null $cabang_id
 * @property \Illuminate\Support\Carbon $report_date
 * @property int $total_sheets
 * @property int $total_sessions
 * @property float $revenue
 * @property float|null $hpp_rate_per_sheet
 * @property float $total_hpp
 * @property string $status
 * @property string $reconciliation_status
 * @property string|null $notes
 * @property string|null $confirmed_by
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property int|null $financial_transaction_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class BoothSessionHpp extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'booth_session_hpp';

    public const BOOTH_JANUS = 'Janus';
    public const BOOTH_GOLIO = 'Golio';

    public const STATUS_PENDING = 'pending';
    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_POSTED = 'posted';

    public const RECON_BALANCED = 'balanced';
    public const RECON_UNBALANCED = 'unbalanced';

    protected $fillable = [
        'session_report_id',
        'booth_type',
        'cabang_id',
        'report_date',
        'total_sheets',
        'total_sessions',
        'revenue',
        'hpp_rate_per_sheet',
        'total_hpp',
        'status',
        'reconciliation_status',
        'notes',
        'confirmed_by',
        'confirmed_at',
        'financial_transaction_id',
    ];

    protected $casts = [
        'report_date' => 'date',
        'confirmed_at' => 'datetime',
        'total_sheets' => 'integer',
        'total_sessions' => 'integer',
        'revenue' => 'decimal:2',
        'hpp_rate_per_sheet' => 'decimal:2',
        'total_hpp' => 'decimal:2',
    ];

    // ==========================================
    // Relationships
    // ==========================================

    public function sessionReport(): BelongsTo
    {
        return $this->belongsTo(SessionReport::class, 'session_report_id');
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class, 'cabang_id', 'cabang_id');
    }

    public function financialTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'financial_transaction_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'confirmed_by', 'karyawan_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(BoothHppAuditLog::class, 'booth_session_hpp_id')->orderByDesc('id');
    }

    // ==========================================
    // Scopes
    // ==========================================

    public function scopeForBooth(Builder $query, string $boothType): Builder
    {
        return $query->where('booth_type', $boothType);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_CONFIRMED, self::STATUS_POSTED]);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_CALCULATED]);
    }

    public function scopeInPeriod(Builder $query, \Carbon\CarbonInterface $start, \Carbon\CarbonInterface $end): Builder
    {
        return $query->whereBetween('report_date', [$start->toDateString(), $end->toDateString()]);
    }

    // ==========================================
    // Helpers
    // ==========================================

    public function isConfirmed(): bool
    {
        return in_array($this->status, [self::STATUS_CONFIRMED, self::STATUS_POSTED], true);
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function getFormattedTotalHppAttribute(): string
    {
        return RupiahHelper::format($this->total_hpp);
    }

    public function getFormattedRateAttribute(): string
    {
        return $this->hpp_rate_per_sheet !== null
            ? RupiahHelper::format($this->hpp_rate_per_sheet)
            : '-';
    }

    public function getStatusBadgeColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_POSTED => 'success',
            self::STATUS_CONFIRMED => 'info',
            self::STATUS_CALCULATED => 'warning',
            default => 'gray',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_POSTED => 'Posted ke Akuntansi',
            self::STATUS_CONFIRMED => 'Confirmed',
            self::STATUS_CALCULATED => 'Tarif Terisi (Draft)',
            default => 'Pending (Belum Dikonfirmasi)',
        };
    }
}
