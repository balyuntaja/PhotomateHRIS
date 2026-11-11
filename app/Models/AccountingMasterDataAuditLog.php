<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingMasterDataAuditLog extends Model
{
    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public const ACTION_ACTIVATE = 'activate';

    public const ACTION_DEACTIVATE = 'deactivate';

    public const ACTION_DELETE = 'delete';

    protected $table = 'accounting_master_data_audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'table_name',
        'record_id',
        'action',
        'old_values',
        'new_values',
        'actor_id',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException('Audit log master data tidak dapat diubah.');
        });

        static::deleting(function (): void {
            throw new DomainException('Audit log master data tidak dapat dihapus.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'actor_id', 'karyawan_id')->withTrashed();
    }
}
