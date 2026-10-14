<?php

namespace App\Models;

use App\Utils\RupiahHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $batch_id
 * @property string $order_id
 * @property Carbon|null $transaction_at
 * @property string|null $booth_name
 * @property string|null $payment_method
 * @property int|null $amount
 * @property int|null $print_count
 * @property int|null $extra_prints
 * @property string|null $source_status
 * @property string|null $frame_name
 * @property string|null $voucher_code
 * @property string $result
 * @property string|null $reason
 * @property int|null $financial_transaction_id
 * @property array|null $raw_payload
 * @property Carbon|null $created_at
 */
class LuminashImportRow extends Model
{
    use HasFactory;

    public const RESULT_IMPORTED = 'imported';

    public const RESULT_EXCLUDED_INCOMPLETE = 'excluded_incomplete';

    public const RESULT_EXCLUDED_UNKNOWN_STATUS = 'excluded_unknown_status';

    public const RESULT_EXCLUDED_VOUCHER = 'excluded_voucher';

    public const RESULT_DUPLICATE = 'duplicate';

    public const RESULT_INVALID = 'invalid';

    public const RESULT_LABELS = [
        self::RESULT_IMPORTED => 'Lolos impor',
        self::RESULT_EXCLUDED_INCOMPLETE => 'Dikecualikan: Incomplete',
        self::RESULT_EXCLUDED_UNKNOWN_STATUS => 'Dikecualikan: status tidak dikenal',
        self::RESULT_EXCLUDED_VOUCHER => 'Dikecualikan: menggunakan voucher',
        self::RESULT_DUPLICATE => 'Dilewati: duplikat',
        self::RESULT_INVALID => 'Error: data tidak valid',
    ];

    public const RESULT_COLORS = [
        self::RESULT_IMPORTED => 'success',
        self::RESULT_EXCLUDED_INCOMPLETE => 'warning',
        self::RESULT_EXCLUDED_UNKNOWN_STATUS => 'warning',
        self::RESULT_EXCLUDED_VOUCHER => 'info',
        self::RESULT_DUPLICATE => 'gray',
        self::RESULT_INVALID => 'danger',
    ];

    protected $table = 'luminash_import_rows';

    protected $fillable = [
        'batch_id',
        'order_id',
        'transaction_at',
        'booth_name',
        'payment_method',
        'amount',
        'print_count',
        'extra_prints',
        'source_status',
        'frame_name',
        'voucher_code',
        'result',
        'reason',
        'financial_transaction_id',
        'session_report_id',
        'raw_payload',
    ];

    protected $casts = [
        'transaction_at' => 'datetime',
        'amount' => 'integer',
        'print_count' => 'integer',
        'extra_prints' => 'integer',
        'raw_payload' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LuminashImportBatch::class, 'batch_id');
    }

    public function financialTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'financial_transaction_id');
    }

    public function sessionReport(): BelongsTo
    {
        return $this->belongsTo(SessionReport::class, 'session_report_id');
    }

    public function getResultLabelAttribute(): string
    {
        return self::RESULT_LABELS[$this->result] ?? $this->result;
    }

    public function getResultColorAttribute(): string
    {
        return self::RESULT_COLORS[$this->result] ?? 'gray';
    }

    public function getFormattedAmountAttribute(): string
    {
        return $this->amount === null ? '-' : RupiahHelper::format($this->amount);
    }

    public function getVoucherLabelAttribute(): string
    {
        return filled($this->voucher_code) ? $this->voucher_code : '-';
    }
}
