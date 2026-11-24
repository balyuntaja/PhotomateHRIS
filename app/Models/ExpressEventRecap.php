<?php

namespace App\Models;

use App\Services\BranchInputModeService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExpressEventRecap extends Model
{
    use HasFactory;

    protected $table = 'express_event_recaps';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'express_event_id',
        'session_report_id',
        'status',
    ];

    public function expressEvent(): BelongsTo
    {
        return $this->belongsTo(ExpressEvent::class, 'express_event_id');
    }

    public function sessionReport(): BelongsTo
    {
        return $this->belongsTo(SessionReport::class, 'session_report_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ExpressEventRecapAllocation::class, 'express_event_recap_id');
    }

    public function latestAllocation(): HasOne
    {
        return $this->hasOne(ExpressEventRecapAllocation::class, 'express_event_recap_id')->latestOfMany();
    }

    /**
     * Total kapasitas sesi sumber dari rekap sesi.
     */
    public function getSourceSessionsAttribute(): int
    {
        $report = $this->sessionReport;
        if (! $report) {
            return 0;
        }

        $inputModeService = app(BranchInputModeService::class);
        if ($report->cabang && $inputModeService->usesManualSessionInput($report->cabang)) {
            return (int) ($report->wedding_total_sessions ?: $report->total_transactions ?: $report->total_sessions);
        }

        $sessions = (int) $report->total_transactions;
        if ($sessions === 0 && $report->relationLoaded('transactions') && $report->transactions->isNotEmpty()) {
            $sessions = $report->transactions->count();
        }

        return $sessions ?: (int) $report->total_sessions;
    }

    /**
     * Total kapasitas lembar sumber dari rekap sesi.
     */
    public function getSourceSheetsAttribute(): int
    {
        $report = $this->sessionReport;
        if (! $report) {
            return 0;
        }

        $inputModeService = app(BranchInputModeService::class);
        if ($report->cabang && $inputModeService->usesManualSessionInput($report->cabang)) {
            return (int) ($report->wedding_total_sheets ?: $report->total_sessions);
        }

        $sheets = (int) $report->total_sessions;
        if ($sheets === 0 && $report->relationLoaded('transactions') && $report->transactions->isNotEmpty()) {
            $sheets = (int) $report->transactions->sum('session_count');
        }

        return $sheets ?: (int) $report->wedding_total_sheets;
    }

    /**
     * Total omzet sumber dari rekap sesi (Grand Total).
     */
    public function getSourceRevenueAttribute(): int
    {
        $report = $this->sessionReport;
        if (! $report) {
            return 0;
        }

        return (int) $report->grand_total_amount;
    }

    /**
     * Total yang dialokasikan khusus pada event ini.
     */
    public function getEventAllocatedSessionsAttribute(): int
    {
        return (int) $this->allocations()->sum('allocated_sessions');
    }

    public function getEventAllocatedSheetsAttribute(): int
    {
        return (int) $this->allocations()->sum('allocated_sheets');
    }

    public function getEventAllocatedRevenueAttribute(): int
    {
        return (int) $this->allocations()->sum('allocated_revenue');
    }
}
