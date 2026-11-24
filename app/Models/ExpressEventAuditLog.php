<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpressEventAuditLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'express_event_audit_logs';

    protected $fillable = [
        'express_event_id',
        'user_id',
        'action',
        'old_data',
        'new_data',
        'notes',
        'created_at',
    ];

    protected $casts = [
        'old_data' => 'array',
        'new_data' => 'array',
        'created_at' => 'datetime',
    ];

    public function expressEvent(): BelongsTo
    {
        return $this->belongsTo(ExpressEvent::class, 'express_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'user_id', 'karyawan_id');
    }

    public static function record(
        ExpressEvent $event,
        string $action,
        ?array $oldData = null,
        ?array $newData = null,
        ?string $notes = null
    ): self {
        return static::create([
            'express_event_id' => $event->id,
            'user_id' => auth()->check() ? auth()->id() : null,
            'action' => $action,
            'old_data' => $oldData,
            'new_data' => $newData,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }
}
