<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpressEventInvoice extends Model
{
    use HasFactory;

    protected $table = 'express_event_invoices';

    protected $fillable = [
        'express_event_id',
        'invoice_id',
        'linked_by',
        'notes',
    ];

    public function expressEvent(): BelongsTo
    {
        return $this->belongsTo(ExpressEvent::class, 'express_event_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function linker(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'linked_by', 'karyawan_id');
    }
}
