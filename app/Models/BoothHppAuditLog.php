<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $booth_session_hpp_id
 * @property string|null $user_id
 * @property string $action
 * @property string|null $reason
 * @property array|null $old_values
 * @property array|null $new_values
 * @property string|null $ip_address
 * @property \Illuminate\Support\Carbon $created_at
 */
class BoothHppAuditLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'booth_hpp_audit_logs';

    protected $fillable = [
        'booth_session_hpp_id',
        'user_id',
        'action',
        'reason',
        'old_values',
        'new_values',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function boothHpp(): BelongsTo
    {
        return $this->belongsTo(BoothSessionHpp::class, 'booth_session_hpp_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'user_id', 'karyawan_id');
    }
}
