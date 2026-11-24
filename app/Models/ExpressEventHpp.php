<?php

namespace App\Models;

use App\Utils\RupiahHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpressEventHpp extends Model
{
    use HasFactory;

    protected $table = 'express_event_hpp';

    protected $fillable = [
        'express_event_id',
        'version',
        'hpp_rate_per_sheet',
        'total_sessions',
        'total_sheets',
        'total_revenue',
        'total_hpp',
        'gross_profit',
        'calculation_status',
        'calculated_at',
        'confirmed_at',
        'confirmed_by',
        'posted_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'hpp_rate_per_sheet' => 'integer',
        'total_sessions' => 'integer',
        'total_sheets' => 'integer',
        'total_revenue' => 'integer',
        'total_hpp' => 'integer',
        'gross_profit' => 'integer',
        'calculated_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function expressEvent(): BelongsTo
    {
        return $this->belongsTo(ExpressEvent::class, 'express_event_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'confirmed_by', 'karyawan_id');
    }

    public function getFormattedRateAttribute(): string
    {
        return RupiahHelper::format($this->hpp_rate_per_sheet);
    }

    public function getFormattedHppAttribute(): string
    {
        return RupiahHelper::format($this->total_hpp);
    }

    public function getFormattedRevenueAttribute(): string
    {
        return RupiahHelper::format($this->total_revenue);
    }

    public function getFormattedGrossProfitAttribute(): string
    {
        return RupiahHelper::format($this->gross_profit);
    }
}
