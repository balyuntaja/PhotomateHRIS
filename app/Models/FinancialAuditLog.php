<?php

namespace App\Models;

use App\Utils\MonthHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialAuditLog extends Model
{
    use HasFactory;

    protected $table = 'financial_audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'financial_transaction_id',
        'user_id',
        'action',
        'old_data',
        'new_data',
        'created_at',
    ];

    protected $casts = [
        'old_data' => 'array',
        'new_data' => 'array',
        'created_at' => 'datetime',
    ];

    protected const FIELD_LABELS = [
        'transaction_type' => 'Jenis Transaksi',
        'amount' => 'Nominal',
        'category_id' => 'Kategori',
        'account_id' => 'Akun',
        'party_id' => 'Dibayarkan / Diterima Oleh',
        'cabang_id' => 'Cabang',
        'payment_method_id' => 'Metode Pembayaran',
        'payment_status' => 'Status Pembayaran',
        'transaction_date' => 'Tanggal',
        'period_month' => 'Bulan Periode',
        'period_year' => 'Tahun Periode',
        'due_date' => 'Jatuh Tempo',
        'counterparty' => 'Pihak / Kepada',
        'description' => 'Deskripsi',
        'evidence_path' => 'Bukti Transaksi',
    ];

    /** @var array<string, string> */
    protected static array $lookupCache = [];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'financial_transaction_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'user_id', 'karyawan_id');
    }

    public function getActionLabelAttribute(): string
    {
        return match ($this->action) {
            FinancialTransaction::ACTION_CREATED => 'Transaksi dibuat',
            FinancialTransaction::ACTION_UPDATED => 'Transaksi diubah',
            FinancialTransaction::ACTION_DELETED => 'Transaksi dihapus',
            FinancialTransaction::ACTION_RESTORED => 'Transaksi dipulihkan',
            default => $this->action,
        };
    }

    public function getActionColorAttribute(): string
    {
        return match ($this->action) {
            FinancialTransaction::ACTION_CREATED => 'success',
            FinancialTransaction::ACTION_UPDATED => 'warning',
            FinancialTransaction::ACTION_DELETED => 'danger',
            FinancialTransaction::ACTION_RESTORED => 'info',
            default => 'gray',
        };
    }

    /**
     * Daftar perubahan yang bisa langsung ditampilkan di UI.
     *
     * @return array<int, array{label: string, old: string|null, new: string|null}>
     */
    public function getChangesAttribute(): array
    {
        $keys = array_keys(array_merge($this->old_data ?? [], $this->new_data ?? []));
        $changes = [];

        foreach ($keys as $key) {
            $old = $this->old_data[$key] ?? null;
            $new = $this->new_data[$key] ?? null;

            if ($old === $new) {
                continue;
            }

            $changes[] = [
                'label' => self::FIELD_LABELS[$key] ?? $key,
                'old' => $old === null ? null : $this->formatValue($key, $old),
                'new' => $new === null ? null : $this->formatValue($key, $new),
            ];
        }

        return $changes;
    }

    protected function formatValue(string $field, mixed $value): string
    {
        return match ($field) {
            'amount' => 'Rp ' . number_format((int) $value, 0, ',', '.'),
            'transaction_type' => FinancialTransaction::TYPE_LABELS[$value] ?? (string) $value,
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LABELS[$value] ?? (string) $value,
            'category_id' => $this->lookup('category', $value),
            'account_id' => $this->lookup('account', $value),
            'party_id' => $this->lookup('party', $value),
            'cabang_id' => $this->lookup('cabang', $value),
            'payment_method_id' => $this->lookup('payment_method', $value),
            'transaction_date' => $this->formatDate($value),
            'due_date' => $this->formatDate($value),
            'period_month' => MonthHelper::getMonthName((int) $value),
            default => (string) $value,
        };
    }

    protected function formatDate(mixed $value): string
    {
        try {
            $date = \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return (string) $value;
        }

        return $date->day . ' ' . MonthHelper::formatPeriod((int) $date->month, (int) $date->year);
    }

    protected function lookup(string $type, mixed $id): string
    {
        $key = $type . ':' . $id;

        if (array_key_exists($key, static::$lookupCache)) {
            return static::$lookupCache[$key];
        }

        $name = match ($type) {
            'category' => FinancialCategory::find($id)?->name,
            'account' => FinancialAccount::find($id)?->name,
            'party' => FinancialParty::find($id)?->name,
            'cabang' => Cabang::find($id)?->nama_cabang,
            'payment_method' => FinancialPaymentMethod::find($id)?->name,
            default => null,
        };

        return static::$lookupCache[$key] = $name ?? '-';
    }
}
