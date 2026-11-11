<?php

namespace App\Models;

use App\Utils\MonthHelper;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class FiscalPeriod extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_CLOSED => 'Closed',
    ];

    protected $table = 'fiscal_periods';

    protected $fillable = [
        'year',
        'month',
        'start_date',
        'end_date',
        'status',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $period): void {
            if ((int) $period->month < 1 || (int) $period->month > 12) {
                throw new DomainException('Bulan periode harus antara 1 dan 12.');
            }

            if (blank($period->start_date) || blank($period->end_date)) {
                $start = Carbon::create((int) $period->year, (int) $period->month, 1)->startOfMonth();

                $period->start_date = $start->toDateString();
                $period->end_date = $start->copy()->endOfMonth()->toDateString();
            }

            if ($period->start_date->greaterThan($period->end_date)) {
                throw new DomainException('Tanggal mulai periode tidak boleh setelah tanggal akhir.');
            }

            if (! in_array($period->status, [self::STATUS_OPEN, self::STATUS_CLOSED], true)) {
                throw new DomainException('Status periode tidak valid.');
            }

            if ($period->status === self::STATUS_CLOSED && blank($period->closed_at)) {
                $period->closed_at = now();
            }
        });
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'closed_by', 'karyawan_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CLOSED);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function close(?Karyawan $user = null): self
    {
        if ($this->isClosed()) {
            return $this;
        }

        $this->forceFill([
            'status' => self::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => $user?->karyawan_id,
        ])->save();

        return $this;
    }

    public static function forDate(CarbonInterface|string $date): ?self
    {
        $date = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return static::query()
            ->where('year', (int) $date->year)
            ->where('month', (int) $date->month)
            ->first();
    }

    public function containsDate(CarbonInterface|string $date): bool
    {
        $d = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return $d->startOfDay()->greaterThanOrEqualTo($this->start_date->startOfDay())
            && $d->startOfDay()->lessThanOrEqualTo($this->end_date->startOfDay());
    }

    public static function ensureOpenForDate(CarbonInterface|string $date): self
    {
        $date = $date instanceof CarbonInterface ? $date : Carbon::parse($date);
        $start = $date->copy()->startOfMonth();

        $period = static::query()->firstOrCreate(
            [
                'year' => (int) $date->year,
                'month' => (int) $date->month,
            ],
            [
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->endOfMonth()->toDateString(),
                'status' => self::STATUS_OPEN,
            ]
        );

        if ($period->isClosed()) {
            throw new DomainException('Periode akuntansi '.$period->label.' sudah ditutup.');
        }

        if (! $period->containsDate($date)) {
            throw new DomainException('Tanggal jurnal '.$date->toDateString().' berada di luar rentang tanggal periode '.$period->label.'.');
        }

        return $period;
    }

    public function getLabelAttribute(): string
    {
        return MonthHelper::formatPeriod((int) $this->month, (int) $this->year);
    }
}
