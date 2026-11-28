<?php

namespace App\Models;

use App\Utils\InventoryBranches;
use App\Utils\RupiahHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

class ExpressEvent extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'express_events';

    public const RECON_UNALLOCATED = 'unallocated';
    public const RECON_PARTIAL = 'partial';
    public const RECON_FULLY_ALLOCATED = 'fully_allocated';
    public const RECON_NEEDS_REVIEW = 'needs_review';
    public const RECON_RECONCILED = 'reconciled';

    public const RECON_LABELS = [
        self::RECON_UNALLOCATED => 'Belum Dialokasikan',
        self::RECON_PARTIAL => 'Alokasi Sebagian',
        self::RECON_FULLY_ALLOCATED => 'Dialokasikan Lengkap',
        self::RECON_NEEDS_REVIEW => 'Perlu Ditinjau',
        self::RECON_RECONCILED => 'Terekonsiliasi',
    ];

    public const RECON_COLORS = [
        self::RECON_UNALLOCATED => 'gray',
        self::RECON_PARTIAL => 'warning',
        self::RECON_FULLY_ALLOCATED => 'info',
        self::RECON_NEEDS_REVIEW => 'danger',
        self::RECON_RECONCILED => 'success',
    ];

    public const HPP_PENDING = 'pending';
    public const HPP_CALCULATED = 'calculated';
    public const HPP_CONFIRMED = 'confirmed';
    public const HPP_POSTED = 'posted';
    public const HPP_REVERSED = 'reversed';

    public const HPP_LABELS = [
        self::HPP_PENDING => 'Menunggu HPP',
        self::HPP_CALCULATED => 'Dihitung (Preview)',
        self::HPP_CONFIRMED => 'Terkonfirmasi',
        self::HPP_POSTED => 'Diposting (Jurnal)',
        self::HPP_REVERSED => 'Dibalik (Reversed)',
    ];

    public const HPP_COLORS = [
        self::HPP_PENDING => 'gray',
        self::HPP_CALCULATED => 'warning',
        self::HPP_CONFIRMED => 'primary',
        self::HPP_POSTED => 'success',
        self::HPP_REVERSED => 'danger',
    ];

    public const TYPE_SHARING_PROFIT = 'sharing_profit';
    public const TYPE_SELF_RUN = 'self_run';
    public const TYPE_SEWA = 'sewa';
    public const TYPE_EXPRESS_GENERAL = 'express';

    public const TYPE_LABELS = [
        self::TYPE_SHARING_PROFIT => 'Express Sharing Profit',
        self::TYPE_SELF_RUN => 'Express Self Run',
        self::TYPE_SEWA => 'Express Sewa',
        self::TYPE_EXPRESS_GENERAL => 'Photomate Express',
    ];

    public const COMPLETENESS_INCOMPLETE_EVENT = 'incomplete_event';
    public const COMPLETENESS_INVOICE_UNLINKED = 'invoice_unlinked';
    public const COMPLETENESS_WAITING_HPP = 'waiting_hpp';
    public const COMPLETENESS_WAITING_CONFIRMATION = 'waiting_confirmation';
    public const COMPLETENESS_CONFIRMED = 'confirmed';

    public const COMPLETENESS_LABELS = [
        self::COMPLETENESS_INCOMPLETE_EVENT => 'Data event belum lengkap',
        self::COMPLETENESS_INVOICE_UNLINKED => 'Invoice Belum Terhubung',
        self::COMPLETENESS_WAITING_HPP => 'Menunggu pengelolaan HPP',
        self::COMPLETENESS_WAITING_CONFIRMATION => 'Menunggu konfirmasi',
        self::COMPLETENESS_CONFIRMED => 'Sudah dikonfirmasi',
    ];

    public const COMPLETENESS_COLORS = [
        self::COMPLETENESS_INCOMPLETE_EVENT => 'danger',
        self::COMPLETENESS_INVOICE_UNLINKED => 'warning',
        self::COMPLETENESS_WAITING_HPP => 'info',
        self::COMPLETENESS_WAITING_CONFIRMATION => 'warning',
        self::COMPLETENESS_CONFIRMED => 'success',
    ];

    protected $fillable = [
        'event_name',
        'operational_type',
        'start_date',
        'end_date',
        'hpp_rate_per_sheet',
        'reconciliation_status',
        'hpp_status',
        'notes',
        'created_by',
        'confirmed_by',
        'confirmed_at',
        'finalized_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'hpp_rate_per_sheet' => 'integer',
        'confirmed_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::deleting(function (self $event): void {
            if (Schema::hasTable('express_event_invoices')) {
                $event->eventInvoices()->delete();
            }
        });
    }

    public function recaps(): HasMany
    {
        return $this->hasMany(ExpressEventRecap::class, 'express_event_id');
    }

    public function eventInvoices(): HasMany
    {
        return $this->hasMany(ExpressEventInvoice::class, 'express_event_id');
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(
            Invoice::class,
            'express_event_invoices',
            'express_event_id',
            'invoice_id'
        )->withPivot(['id', 'linked_by', 'notes'])->withTimestamps();
    }

    public function sessionReports(): BelongsToMany
    {
        return $this->belongsToMany(
            SessionReport::class,
            'express_event_recaps',
            'express_event_id',
            'session_report_id'
        )->withPivot(['id', 'status'])->withTimestamps();
    }

    public function allocations(): HasManyThrough
    {
        return $this->hasManyThrough(
            ExpressEventRecapAllocation::class,
            ExpressEventRecap::class,
            'express_event_id',
            'express_event_recap_id'
        );
    }

    public function hppSnapshots(): HasMany
    {
        return $this->hasMany(ExpressEventHpp::class, 'express_event_id')->orderByDesc('version');
    }

    public function latestHpp(): HasOne
    {
        return $this->hasOne(ExpressEventHpp::class, 'express_event_id')->latestOfMany('version');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ExpressEventAuditLog::class, 'express_event_id')->orderByDesc('created_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'created_by', 'karyawan_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'confirmed_by', 'karyawan_id');
    }

    public function isConfirmed(): bool
    {
        return in_array($this->hpp_status, [self::HPP_CONFIRMED, self::HPP_POSTED], true);
    }

    public function isPosted(): bool
    {
        return $this->hpp_status === self::HPP_POSTED;
    }

    public function isFinalized(): bool
    {
        return !is_null($this->finalized_at);
    }

    public function hasExplicitRate(): bool
    {
        return !is_null($this->hpp_rate_per_sheet);
    }

    public function getTotalAllocatedSessionsAttribute(): int
    {
        return (int) $this->allocations()->sum('allocated_sessions');
    }

    public function getTotalAllocatedSheetsAttribute(): int
    {
        return (int) $this->allocations()->sum('allocated_sheets');
    }

    public function getTotalAllocatedRevenueAttribute(): int
    {
        return (int) $this->allocations()->sum('allocated_revenue');
    }

    public function getCalculatedHppAttribute(): int
    {
        if ($this->hpp_rate_per_sheet === null) {
            return 0;
        }

        return (int) ($this->total_allocated_sheets * $this->hpp_rate_per_sheet);
    }

    public function getGrossProfitAttribute(): int
    {
        return (int) ($this->effective_revenue - $this->calculated_hpp);
    }

    public static function classifyBranchOperationalType(?string $branchName): ?string
    {
        $normalized = InventoryBranches::normalizeName($branchName);

        if ($normalized === '' || str_contains($normalized, 'janus') || str_contains($normalized, 'golio')) {
            return null;
        }

        if (str_contains($normalized, 'self run') || str_contains($normalized, 'selfrun')) {
            return self::TYPE_SELF_RUN;
        }

        if (str_contains($normalized, 'sewa')) {
            return self::TYPE_SEWA;
        }

        if (str_contains($normalized, 'sharing profit')) {
            return self::TYPE_SHARING_PROFIT;
        }

        if (str_contains($normalized, 'express')) {
            return self::TYPE_EXPRESS_GENERAL;
        }

        return null;
    }

    public function getResolvedOperationalTypeAttribute(): ?string
    {
        $recaps = $this->relationLoaded('recaps')
            ? $this->recaps
            : $this->recaps()->with('sessionReport.cabang')->get();

        $detectedTypes = [];
        foreach ($recaps as $recap) {
            $report = $recap->relationLoaded('sessionReport')
                ? $recap->sessionReport
                : $recap->sessionReport()->first();
            $cabangName = $report?->resolveCabangWithTrashed()?->nama_cabang;
            $type = static::classifyBranchOperationalType($cabangName);
            if ($type !== null) {
                $detectedTypes[] = $type;
            }
        }

        foreach ([self::TYPE_SELF_RUN, self::TYPE_SEWA, self::TYPE_SHARING_PROFIT] as $specificType) {
            if (in_array($specificType, $detectedTypes, true)) {
                return $specificType;
            }
        }

        if (filled($this->operational_type)) {
            return $this->operational_type;
        }

        return $detectedTypes[0] ?? null;
    }

    public function getOperationalTypeLabelAttribute(): string
    {
        $type = $this->resolved_operational_type;

        return $type !== null ? (self::TYPE_LABELS[$type] ?? 'Express') : 'Express';
    }

    public function isSharingProfit(): bool
    {
        return $this->resolved_operational_type === self::TYPE_SHARING_PROFIT;
    }

    public function isSelfRun(): bool
    {
        return $this->resolved_operational_type === self::TYPE_SELF_RUN;
    }

    public function isSewa(): bool
    {
        return $this->resolved_operational_type === self::TYPE_SEWA;
    }

    public function requiresInvoice(): bool
    {
        return in_array($this->resolved_operational_type, [self::TYPE_SELF_RUN, self::TYPE_SEWA], true);
    }

    public function hasLinkedInvoices(): bool
    {
        if (! Schema::hasTable('express_event_invoices')) {
            return false;
        }

        return $this->relationLoaded('invoices')
            ? $this->invoices->isNotEmpty()
            : $this->invoices()->exists();
    }

    public function getTotalInvoiceAmountAttribute(): int
    {
        if (! Schema::hasTable('express_event_invoices')) {
            return 0;
        }

        $invoices = $this->relationLoaded('invoices')
            ? $this->invoices
            : $this->invoices()->get(['invoices.id', 'invoices.total']);

        return (int) $invoices
            ->unique('id')
            ->sum(fn (Invoice $invoice): int => $invoice->contract_amount);
    }

    public function getTotalRecapQrisAmountAttribute(): int
    {
        $reports = $this->uniqueLinkedSessionReports();

        return (int) $reports->sum(fn (SessionReport $r): int => (int) $r->total_qris_amount);
    }

    public function getTotalRecapCashAmountAttribute(): int
    {
        $reports = $this->uniqueLinkedSessionReports();

        return (int) $reports->sum(fn (SessionReport $r): int => (int) $r->total_cash_amount);
    }

    public function getTotalRecapGrandTotalAmountAttribute(): int
    {
        $reports = $this->uniqueLinkedSessionReports();

        return (int) $reports->sum(fn (SessionReport $r): int => (int) $r->grand_total_amount);
    }

    public function getEffectiveRevenueAttribute(): int
    {
        if ($this->isSharingProfit()) {
            return $this->total_allocated_revenue;
        }

        if ($this->requiresInvoice() && $this->hasLinkedInvoices()) {
            return $this->total_invoice_amount;
        }

        return $this->total_allocated_revenue;
    }

    public function hasCompleteEventData(): bool
    {
        $hasRecap = $this->relationLoaded('recaps')
            ? $this->recaps->where('status', ExpressEventRecap::STATUS_ACTIVE)->isNotEmpty()
            : $this->recaps()->where('status', ExpressEventRecap::STATUS_ACTIVE)->exists();

        return filled($this->event_name)
            && $this->start_date !== null
            && $this->end_date !== null
            && $hasRecap;
    }

    public function isReadyForHppCalculation(): bool
    {
        if (! $this->hasCompleteEventData()) {
            return false;
        }

        if ($this->requiresInvoice() && ! $this->hasLinkedInvoices()) {
            return false;
        }

        return true;
    }

    public function getInvoiceConnectionStatusLabelAttribute(): string
    {
        if (! $this->requiresInvoice()) {
            return 'Tidak Memerlukan Invoice';
        }

        return $this->hasLinkedInvoices() ? 'Invoice Terhubung' : 'Invoice Belum Terhubung';
    }

    public function getInvoiceConnectionStatusColorAttribute(): string
    {
        if (! $this->requiresInvoice()) {
            return 'gray';
        }

        return $this->hasLinkedInvoices() ? 'success' : 'warning';
    }

    public function getCompletenessStatusKeyAttribute(): string
    {
        if (! $this->hasCompleteEventData()) {
            return self::COMPLETENESS_INCOMPLETE_EVENT;
        }

        if ($this->requiresInvoice() && ! $this->hasLinkedInvoices()) {
            return self::COMPLETENESS_INVOICE_UNLINKED;
        }

        if ($this->isConfirmed()) {
            return self::COMPLETENESS_CONFIRMED;
        }

        if ($this->hpp_rate_per_sheet === null) {
            return self::COMPLETENESS_WAITING_HPP;
        }

        return self::COMPLETENESS_WAITING_CONFIRMATION;
    }

    public function getCompletenessStatusLabelAttribute(): string
    {
        return self::COMPLETENESS_LABELS[$this->completeness_status_key] ?? 'Menunggu pengelolaan HPP';
    }

    public function getCompletenessStatusColorAttribute(): string
    {
        return self::COMPLETENESS_COLORS[$this->completeness_status_key] ?? 'gray';
    }

    /**
     * @return \Illuminate\Support\Collection<int, SessionReport>
     */
    protected function uniqueLinkedSessionReports(): \Illuminate\Support\Collection
    {
        $recaps = $this->relationLoaded('recaps')
            ? $this->recaps
            : $this->recaps()->with('sessionReport')->get();

        return $recaps
            ->where('status', ExpressEventRecap::STATUS_ACTIVE)
            ->map(fn (ExpressEventRecap $recap): ?SessionReport => $recap->sessionReport)
            ->filter()
            ->unique('id')
            ->values();
    }

    public function getReconciliationLabelAttribute(): string
    {
        return self::RECON_LABELS[$this->reconciliation_status] ?? $this->reconciliation_status;
    }

    public function getReconciliationColorAttribute(): string
    {
        return self::RECON_COLORS[$this->reconciliation_status] ?? 'gray';
    }

    public function getHppStatusLabelAttribute(): string
    {
        return self::HPP_LABELS[$this->hpp_status] ?? $this->hpp_status;
    }

    public function getHppStatusColorAttribute(): string
    {
        return self::HPP_COLORS[$this->hpp_status] ?? 'gray';
    }

    public function getFormattedRateAttribute(): string
    {
        if ($this->hpp_rate_per_sheet === null) {
            return '-';
        }

        return RupiahHelper::format($this->hpp_rate_per_sheet);
    }

    public function getFormattedTotalHppAttribute(): string
    {
        if ($this->hpp_rate_per_sheet === null) {
            return '-';
        }

        return RupiahHelper::format($this->calculated_hpp);
    }

    public function getFormattedGrossProfitAttribute(): string
    {
        return RupiahHelper::format($this->gross_profit);
    }

    public function getFormattedRevenueAttribute(): string
    {
        return RupiahHelper::format($this->total_allocated_revenue);
    }
}
