<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $file_name
 * @property string|null $file_path
 * @property string|null $cabang_id
 * @property string|null $imported_by
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property int $total_rows
 * @property int $imported_count
 * @property int $excluded_incomplete_count
 * @property int $excluded_voucher_count
 * @property int $excluded_unknown_status_count
 * @property int $duplicate_count
 * @property int $invalid_count
 * @property int $total_imported_amount
 * @property Carbon|null $created_at
 */
class LuminashImportBatch extends Model
{
    use HasFactory;

    protected $table = 'luminash_import_batches';

    protected $fillable = [
        'file_name',
        'file_path',
        'cabang_id',
        'imported_by',
        'period_start',
        'period_end',
        'total_rows',
        'imported_count',
        'excluded_incomplete_count',
        'excluded_voucher_count',
        'excluded_unknown_status_count',
        'duplicate_count',
        'invalid_count',
        'session_report_count',
        'total_imported_amount',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_rows' => 'integer',
        'imported_count' => 'integer',
        'excluded_incomplete_count' => 'integer',
        'excluded_voucher_count' => 'integer',
        'excluded_unknown_status_count' => 'integer',
        'duplicate_count' => 'integer',
        'invalid_count' => 'integer',
        'session_report_count' => 'integer',
        'total_imported_amount' => 'integer',
    ];

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class, 'cabang_id', 'cabang_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'imported_by', 'karyawan_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(LuminashImportRow::class, 'batch_id');
    }

    public function getExcludedCountAttribute(): int
    {
        return $this->excluded_incomplete_count
            + $this->excluded_voucher_count
            + $this->excluded_unknown_status_count;
    }

    public function getPeriodLabelAttribute(): string
    {
        if (! $this->period_start || ! $this->period_end) {
            return '-';
        }

        return $this->period_start->translatedFormat('d M Y') . ' – ' . $this->period_end->translatedFormat('d M Y');
    }

    public function getImportedAtLabelAttribute(): string
    {
        return $this->created_at?->translatedFormat('d M Y H:i') ?? '-';
    }
}
