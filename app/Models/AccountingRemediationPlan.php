<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingRemediationPlan extends Model
{
    public const STATUS_NEED_CONFIRMATION = 'NEED_BUSINESS_CONFIRMATION';

    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_APPLIED = 'APPLIED';

    public const STATUS_LABELS = [
        self::STATUS_NEED_CONFIRMATION => 'Need Business Confirmation',
        self::STATUS_PENDING_APPROVAL => 'Pending Approval',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
        self::STATUS_APPLIED => 'Applied',
    ];

    protected $table = 'accounting_remediation_plans';

    protected $fillable = [
        'financial_transaction_id',
        'version',
        'original_category_id',
        'proposed_category_id',
        'proposed_category_name',
        'original_account_id',
        'proposed_account_id',
        'proposed_coa_code',
        'accounting_treatment',
        'reason',
        'evidence',
        'requires_mapping_change',
        'status',
        'approved_by',
        'approved_at',
        'approval_note',
        'approved_plan_snapshot',
        'approved_transaction_snapshot',
        'applied_by',
        'applied_at',
        'apply_result',
        'created_by',
    ];

    protected $casts = [
        'financial_transaction_id' => 'integer',
        'version' => 'integer',
        'original_category_id' => 'integer',
        'proposed_category_id' => 'integer',
        'original_account_id' => 'integer',
        'proposed_account_id' => 'integer',
        'requires_mapping_change' => 'boolean',
        'approved_at' => 'datetime',
        'approved_plan_snapshot' => 'array',
        'approved_transaction_snapshot' => 'array',
        'applied_at' => 'datetime',
        'apply_result' => 'array',
    ];

    public function financialTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class);
    }

    public function originalCategory(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'original_category_id');
    }

    public function proposedCategory(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'proposed_category_id');
    }

    public function originalAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'original_account_id');
    }

    public function proposedAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'proposed_account_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'approved_by', 'karyawan_id');
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'applied_by', 'karyawan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'created_by', 'karyawan_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
