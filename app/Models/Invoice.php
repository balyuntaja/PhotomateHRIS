<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property \Illuminate\Support\Carbon|null $event_date
 * @property \Illuminate\Support\Carbon|null $event_end_date
 * @property \Illuminate\Support\Carbon|null $invoice_date
 * @property \Illuminate\Support\Carbon|null $max_dp_date
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property float|null $down_payment
 * @property \Illuminate\Support\Carbon|null $dp_date
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Invoice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'event_date' => 'date',
        'event_end_date' => 'date',
        'invoice_date' => 'date',
        'max_dp_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'float',
        'discount' => 'float',
        'total' => 'float',
        'down_payment' => 'float',
        'dp_date' => 'date',
    ];

    public function invoiceItems()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Simpan invoice beserta sinkronisasi transaksi keuangan dan jurnalnya
     * dalam satu transaksi database. Kegagalan jurnal membatalkan invoice.
     */
    public function save(array $options = [])
    {
        return DB::transaction(fn () => parent::save($options), 3);
    }

    /**
     * Hapus invoice beserta pembatalan transaksi keuangan dan reversal jurnal.
     */
    public function delete()
    {
        return DB::transaction(fn () => parent::delete(), 3);
    }

    public function journalEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(JournalEntry::class, 'reference_id')
            ->where('reference_type', \App\Services\TransactionAccountingService::REF_INVOICE);
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($invoice) {
            $total = (float) ($invoice->total ?? 0);
            $downPayment = (float) ($invoice->down_payment ?? 0);

            if ($downPayment < 0) {
                throw new DomainException('Total pembayaran DP tidak boleh negatif.');
            }

            if ($downPayment > $total) {
                throw new DomainException('Total pembayaran DP tidak boleh melebihi total tagihan invoice.');
            }
        });

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
