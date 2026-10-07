<?php

namespace App\Models;

use App\Utils\MonthHelper;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;

class FinancialTransaction extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const TYPE_INCOME = 'income';

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_LABELS = [
        self::TYPE_INCOME => 'Pemasukan',
        self::TYPE_EXPENSE => 'Pengeluaran',
    ];

    public const PAYMENT_STATUS_LUNAS = 'lunas';

    public const PAYMENT_STATUS_UTANG = 'utang';

    public const PAYMENT_STATUS_LABELS = [
        self::PAYMENT_STATUS_LUNAS => 'Lunas',
        self::PAYMENT_STATUS_UTANG => 'Utang',
    ];

    public const ACTION_CREATED = 'CREATED';

    public const ACTION_UPDATED = 'UPDATED';

    public const ACTION_DELETED = 'DELETED';

    public const ACTION_RESTORED = 'RESTORED';

    protected $table = 'financial_transactions';

    protected $fillable = [
        'transaction_type',
        'amount',
        'category_id',
        'account_id',
        'party_id',
        'cabang_id',
        'payment_method_id',
        'payment_status',
        'transaction_date',
        'period_month',
        'period_year',
        'due_date',
        'counterparty',
        'description',
        'evidence_path',
        'source_type',
        'source_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'integer',
        'transaction_date' => 'date',
        'period_month' => 'integer',
        'period_year' => 'integer',
        'due_date' => 'date',
    ];

    /**
     * Perubahan yang belum tercatat saat event updating berjalan.
     *
     * @var array{old: array<string, mixed>, new: array<string, mixed>}|null
     */
    protected ?array $pendingAuditChanges = null;

    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            $transaction->created_by ??= auth()->id();
        });

        static::created(function (self $transaction): void {
            $transaction->logAudit(self::ACTION_CREATED, null, $transaction->auditSnapshot());
        });

        static::updating(function (self $transaction): void {
            $transaction->updated_by = auth()->id();

            $dirty = Arr::except($transaction->getDirty(), ['updated_by', 'updated_at']);

            $transaction->pendingAuditChanges = [
                'old' => $transaction->normalizeAuditData(Arr::only($transaction->getOriginal(), array_keys($dirty))),
                'new' => $transaction->normalizeAuditData($dirty),
            ];
        });

        static::updated(function (self $transaction): void {
            $changes = $transaction->pendingAuditChanges ?? ['old' => [], 'new' => []];

            if (blank($changes['new'])) {
                return;
            }

            $transaction->logAudit(self::ACTION_UPDATED, $changes['old'], $changes['new']);
        });

        static::deleted(function (self $transaction): void {
            $transaction->logAudit(self::ACTION_DELETED, $transaction->auditSnapshot(), null);
        });

        static::restored(function (self $transaction): void {
            $transaction->logAudit(self::ACTION_RESTORED, null, $transaction->auditSnapshot());
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'category_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'account_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinancialParty::class, 'party_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(FinancialPaymentMethod::class, 'payment_method_id');
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class, 'cabang_id', 'cabang_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'created_by', 'karyawan_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'updated_by', 'karyawan_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(FinancialAuditLog::class, 'financial_transaction_id');
    }

    public function scopePeriode(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()]);
    }

    public function scopeForCabang(Builder $query, ?string $cabangId): Builder
    {
        return $query->when(filled($cabangId), fn (Builder $q): Builder => $q->where('cabang_id', $cabangId));
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return $query->when(filled($type), fn (Builder $q): Builder => $q->where('transaction_type', $type));
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->transaction_type] ?? $this->transaction_type;
    }

    public function getIsIncomeAttribute(): bool
    {
        return $this->transaction_type === self::TYPE_INCOME;
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return self::PAYMENT_STATUS_LABELS[$this->payment_status] ?? (string) $this->payment_status;
    }

    public function getIsUtangAttribute(): bool
    {
        return $this->payment_status === self::PAYMENT_STATUS_UTANG;
    }

    /**
     * Label pihak pembayar/penerima sesuai jenis transaksi.
     */
    public function getPartyLabelAttribute(): string
    {
        return $this->is_income ? 'Diterima Oleh' : 'Dibayarkan Oleh';
    }

    /**
     * Label periode laporan: dari kolom periode bila diisi, jika tidak
     * memakai bulan/tahun tanggal transaksi (perilaku lama).
     */
    public function getPeriodLabelAttribute(): string
    {
        $month = (int) ($this->period_month ?: $this->transaction_date?->month);
        $year = (int) ($this->period_year ?: $this->transaction_date?->year);

        if (! $month || ! $year) {
            return '-';
        }

        return MonthHelper::formatPeriod($month, $year);
    }

    /**
     * Nominal bertanda untuk tampilan: pemasukan positif, pengeluaran negatif.
     */
    public function getSignedAmountAttribute(): int
    {
        return $this->is_income ? (int) $this->amount : -1 * (int) $this->amount;
    }

    public function getFormattedAmountAttribute(): string
    {
        return 'Rp ' . number_format((int) $this->amount, 0, ',', '.');
    }

    public function getSignedFormattedAmountAttribute(): string
    {
        return ($this->is_income ? '+ ' : '- ') . $this->formatted_amount;
    }

    public function getTransactionDateLabelAttribute(): string
    {
        if (! $this->transaction_date) {
            return '-';
        }

        return $this->transaction_date->day . ' ' . MonthHelper::formatPeriod(
            (int) $this->transaction_date->month,
            (int) $this->transaction_date->year
        );
    }

    public function getEvidenceUrlAttribute(): ?string
    {
        if (blank($this->evidence_path)) {
            return null;
        }

        return asset('storage/' . $this->evidence_path);
    }

    public function getEvidenceIsImageAttribute(): bool
    {
        if (blank($this->evidence_path)) {
            return false;
        }

        return in_array(
            strtolower(pathinfo($this->evidence_path, PATHINFO_EXTENSION)),
            ['jpg', 'jpeg', 'png', 'webp'],
            true
        );
    }

    public function getCreatedAtLabelAttribute(): ?string
    {
        return $this->formatDateTimeLabel($this->created_at);
    }

    public function getUpdatedAtLabelAttribute(): ?string
    {
        return $this->formatDateTimeLabel($this->updated_at);
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditSnapshot(): array
    {
        $snapshot = Arr::only($this->getAttributes(), [
            'transaction_type',
            'amount',
            'category_id',
            'account_id',
            'party_id',
            'cabang_id',
            'payment_method_id',
            'payment_status',
            'transaction_date',
            'period_month',
            'period_year',
            'due_date',
            'counterparty',
            'description',
            'evidence_path',
        ]);

        foreach (['transaction_date', 'due_date'] as $column) {
            if ($this->{$column}) {
                $snapshot[$column] = $this->{$column}->toDateString();
            }
        }

        return $this->normalizeAuditData($snapshot);
    }

    /**
     * Menjaga tipe data pada audit log tetap konsisten dengan kolom database.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeAuditData(array $data): array
    {
        foreach ([
            'amount',
            'category_id',
            'account_id',
            'party_id',
            'payment_method_id',
            'period_month',
            'period_year',
        ] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $data[$key] = (int) $data[$key];
            }
        }

        return $data;
    }

    protected function formatDateTimeLabel(?CarbonInterface $dateTime): ?string
    {
        if (! $dateTime) {
            return null;
        }

        return $dateTime->day
            . ' ' . MonthHelper::formatPeriod((int) $dateTime->month, (int) $dateTime->year)
            . ' ' . $dateTime->format('H:i');
    }

    /**
     * @param  array<string, mixed>|null  $oldData
     * @param  array<string, mixed>|null  $newData
     */
    protected function logAudit(string $action, ?array $oldData, ?array $newData): void
    {
        if (blank($oldData) && blank($newData)) {
            return;
        }

        $this->auditLogs()->create([
            'user_id' => auth()->check() ? auth()->id() : null,
            'action' => $action,
            'old_data' => $oldData,
            'new_data' => $newData,
            'created_at' => now(),
        ]);
    }
}
