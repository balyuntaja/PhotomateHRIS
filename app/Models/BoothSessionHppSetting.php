<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $booth_type
 * @property float $default_rate_per_sheet
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class BoothSessionHppSetting extends Model
{
    use HasFactory;

    protected $table = 'booth_session_hpp_settings';

    protected $fillable = [
        'booth_type',
        'default_rate_per_sheet',
        'updated_by',
    ];

    protected $casts = [
        'default_rate_per_sheet' => 'decimal:2',
    ];

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'updated_by', 'karyawan_id');
    }
}
