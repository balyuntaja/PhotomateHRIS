<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'event_date' => 'date',
        'event_end_date' => 'date',
        'invoice_date' => 'date',
        'due_date' => 'date',
    ];

    public function invoiceItems()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($invoice) {
            if (!$invoice->invoice_number) {
                $lastInvoice = static::orderBy('id', 'desc')->first();
                $year = date('Y');
                $number = $lastInvoice ? (int) substr($lastInvoice->invoice_number, -3) + 1 : 1;
                $invoice->invoice_number = 'INV-PMT-' . $year . '-' . str_pad($number, 3, '0', STR_PAD_LEFT);
            }
        });
    }
}
