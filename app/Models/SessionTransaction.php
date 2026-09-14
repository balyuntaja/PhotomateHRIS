<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionTransaction extends Model
{
    use HasFactory;

    protected $table = 'session_transactions';

    protected $fillable = [
        'session_report_id',
        'payment_method',
        'session_count',
        'amount',
        'notes',
    ];

    protected $casts = [
        'session_count' => 'integer',
        'amount' => 'integer',
    ];

    public function sessionReport(): BelongsTo
    {
        return $this->belongsTo(SessionReport::class, 'session_report_id');
    }

    public function getPaymentMethodLabelAttribute(): string
    {
        return match ($this->payment_method) {
            'CASH' => 'Tunai',
            'QRIS' => 'QRIS',
            default => $this->payment_method,
        };
    }
}
