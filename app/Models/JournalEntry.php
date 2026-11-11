<?php

namespace App\Models;

use App\Utils\RupiahHelper;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_POSTED => 'Posted',
    ];

    protected $table = 'journal_entries';

    protected $fillable = [
        'journal_number',
        'journal_date',
        'description',
        'reference_type',
        'reference_id',
        'status',
        'fiscal_period_id',
        'total_debit',
        'total_credit',
        'posted_at',
        'created_by',
        'posted_by',
        'idempotency_key',
        'reversal_of_id',
    ];

    protected $casts = [
        'journal_date' => 'date',
        'fiscal_period_id' => 'integer',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'posted_at' => 'datetime',
        'reversal_of_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $entry): void {
            if (! in_array($entry->status, [self::STATUS_DRAFT, self::STATUS_POSTED], true)) {
                throw new DomainException('Status jurnal tidak valid.');
            }

            if ($entry->status === self::STATUS_POSTED && blank($entry->posted_at)) {
                $entry->posted_at = now();
            }
        });

        static::updating(function (self $entry): void {
            if ($entry->getOriginal('status') === self::STATUS_POSTED) {
                throw new DomainException(
                    'Jurnal yang sudah posted tidak dapat diubah. Gunakan reversal.'
                );
            }
        });

        static::deleting(function (self $entry): void {
            if ($entry->isPosted()) {
                throw new DomainException(
                    'Jurnal yang sudah posted tidak dapat dihapus. Gunakan reversal.'
                );
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_order')->orderBy('id');
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'created_by', 'karyawan_id');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'posted_by', 'karyawan_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getTotalDebitFormattedAttribute(): string
    {
        return RupiahHelper::format($this->total_debit);
    }

    public function getTotalCreditFormattedAttribute(): string
    {
        return RupiahHelper::format($this->total_credit);
    }

    public function getReferenceLabelAttribute(): string
    {
        if (blank($this->reference_type) && blank($this->reference_id)) {
            return '-';
        }

        return trim($this->reference_type.' #'.$this->reference_id, ' #');
    }

    public function getIsBalancedAttribute(): bool
    {
        return (int) round(((float) $this->total_debit) * 100)
            === (int) round(((float) $this->total_credit) * 100);
    }
}
