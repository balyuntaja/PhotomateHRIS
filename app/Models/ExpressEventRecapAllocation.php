<?php

namespace App\Models;

use App\Utils\RupiahHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpressEventRecapAllocation extends Model
{
    use HasFactory;

    protected $table = 'express_event_recap_allocations';

    protected $fillable = [
        'express_event_recap_id',
        'allocated_sessions',
        'allocated_sheets',
        'allocated_revenue',
        'notes',
    ];

    protected $casts = [
        'allocated_sessions' => 'integer',
        'allocated_sheets' => 'integer',
        'allocated_revenue' => 'integer',
    ];

    public function recap(): BelongsTo
    {
        return $this->belongsTo(ExpressEventRecap::class, 'express_event_recap_id');
    }

    public function expressEvent(): BelongsTo
    {
        return $this->recap->expressEvent();
    }

    public function sessionReport(): BelongsTo
    {
        return $this->recap->sessionReport();
    }

    public function getFormattedRevenueAttribute(): string
    {
        return RupiahHelper::format($this->allocated_revenue);
    }
}
