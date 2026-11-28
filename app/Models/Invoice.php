<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;

/**
 * @property int $id
 * @property \Illuminate\Support\Carbon|null $event_date
 * @property \Illuminate\Support\Carbon|null $event_end_date
 * @property \Illuminate\Support\Carbon|null $invoice_date
 * @property \Illuminate\Support\Carbon|null $max_dp_date
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property float|null $down_payment
 * @property \Illuminate\Support\Carbon|null $dp_date
 * @property string|null $cabang_id
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

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class, 'cabang_id', 'cabang_id');
    }

    public function sessionReports(): HasMany
    {
        return $this->hasMany(SessionReport::class, 'invoice_id');
    }

    public function expressEventLink(): HasOne
    {
        return $this->hasOne(ExpressEventInvoice::class, 'invoice_id');
    }

    public function expressEvents(): BelongsToMany
    {
        return $this->belongsToMany(
            ExpressEvent::class,
            'express_event_invoices',
            'invoice_id',
            'express_event_id'
        )->withPivot(['id', 'linked_by', 'notes'])->withTimestamps();
    }

    /**
     * Nilai kontrak penuh invoice (bukan nominal DP atau pembayaran parsial).
     */
    public function getContractAmountAttribute(): int
    {
        return max(0, (int) round((float) $this->total));
    }

    /**
     * Cek apakah invoice cocok untuk cabang Express Self Run.
     */
    public function isExpressSelfRun(): bool
    {
        $branchName = strtolower($this->cabang?->nama_cabang ?? '');
        if (str_contains($branchName, 'express self run') || str_contains($branchName, 'self run')) {
            return true;
        }
        if (str_contains($branchName, 'express sewa')) {
            return false;
        }

        // Cek relasi session report existing
        $hasSelfRunReport = $this->sessionReports()
            ->whereHas('cabang', fn ($q) => $q->where('nama_cabang', 'like', '%self run%'))
            ->exists();
        if ($hasSelfRunReport) {
            return true;
        }
        $hasSewaReport = $this->sessionReports()
            ->whereHas('cabang', fn ($q) => $q->where('nama_cabang', 'like', '%express sewa%'))
            ->exists();
        if ($hasSewaReport) {
            return false;
        }

        // Cek isi event name, notes, item description
        $content = strtolower($this->event_name . ' ' . $this->notes);
        if (str_contains($content, 'self run') || str_contains($content, 'selfrun') || str_contains($content, 'self-run')) {
            return true;
        }

        $items = $this->relationLoaded('invoiceItems') ? $this->invoiceItems : $this->invoiceItems()->get();
        foreach ($items as $item) {
            $desc = strtolower($item->service_description ?? '');
            if (str_contains($desc, 'self run') || str_contains($desc, 'selfrun') || str_contains($desc, 'self-run')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cek apakah invoice cocok untuk cabang Express Sewa.
     */
    public function isExpressSewa(): bool
    {
        $branchName = strtolower($this->cabang?->nama_cabang ?? '');
        if (str_contains($branchName, 'express sewa')) {
            return true;
        }
        if (str_contains($branchName, 'express self run') || str_contains($branchName, 'self run')) {
            return false;
        }

        // Cek relasi session report existing
        $hasSelfRunReport = $this->sessionReports()
            ->whereHas('cabang', fn ($q) => $q->where('nama_cabang', 'like', '%self run%'))
            ->exists();
        if ($hasSelfRunReport) {
            return false;
        }
        $hasSewaReport = $this->sessionReports()
            ->whereHas('cabang', fn ($q) => $q->where('nama_cabang', 'like', '%express sewa%'))
            ->exists();
        if ($hasSewaReport) {
            return true;
        }

        // Cek content tidak mengandung self run
        $content = strtolower($this->event_name . ' ' . $this->notes);
        if (str_contains($content, 'self run') || str_contains($content, 'selfrun') || str_contains($content, 'self-run')) {
            return false;
        }

        $items = $this->relationLoaded('invoiceItems') ? $this->invoiceItems : $this->invoiceItems()->get();
        foreach ($items as $item) {
            $desc = strtolower($item->service_description ?? '');
            if (str_contains($desc, 'self run') || str_contains($desc, 'selfrun') || str_contains($desc, 'self-run')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scope query invoice berdasarkan jenis cabang.
     */
    public function scopeForCabang(Builder $query, Cabang|string|null $cabang): Builder
    {
        if (! $cabang) {
            return $query;
        }

        $cabangModel = $cabang instanceof Cabang ? $cabang : Cabang::find($cabang);
        $namaCabang = strtolower($cabangModel?->nama_cabang ?? (is_string($cabang) ? $cabang : ''));
        $cabangId = $cabangModel?->cabang_id ?? (is_string($cabang) ? $cabang : null);
        $hasCabangColumn = Schema::hasColumn('invoices', 'cabang_id');

        if (str_contains($namaCabang, 'express self run') || str_contains($namaCabang, 'self run')) {
            return $query->where(function (Builder $q) use ($cabangId, $hasCabangColumn) {
                if ($hasCabangColumn && $cabangId) {
                    $q->where('cabang_id', $cabangId);
                }
                $q->orWhere(function (Builder $sub) use ($hasCabangColumn) {
                    if ($hasCabangColumn) {
                        $sub->whereNull('cabang_id');
                    }
                    $sub->where(function (Builder $c) {
                        $c->whereRaw('LOWER(event_name) LIKE ?', ['%self run%'])
                            ->orWhereRaw('LOWER(notes) LIKE ?', ['%self run%'])
                            ->orWhereHas('invoiceItems', fn ($i) => $i->whereRaw('LOWER(service_description) LIKE ?', ['%self run%']))
                            ->orWhereHas('sessionReports.cabang', fn ($sc) => $sc->whereRaw('LOWER(nama_cabang) LIKE ?', ['%self run%']));
                    })
                    ->whereDoesntHave('sessionReports.cabang', fn ($sc) => $sc->whereRaw('LOWER(nama_cabang) LIKE ?', ['%express sewa%']));
                });
            });
        }

        if (str_contains($namaCabang, 'express sewa')) {
            return $query->where(function (Builder $q) use ($cabangId, $hasCabangColumn) {
                if ($hasCabangColumn && $cabangId) {
                    $q->where('cabang_id', $cabangId);
                }
                $q->orWhere(function (Builder $sub) use ($hasCabangColumn) {
                    if ($hasCabangColumn) {
                        $sub->whereNull('cabang_id');
                    }
                    $sub->where(function (Builder $c) {
                        $c->whereRaw('LOWER(event_name) LIKE ?', ['%sewa%'])
                            ->orWhereRaw('LOWER(notes) LIKE ?', ['%sewa%'])
                            ->orWhereHas('invoiceItems', fn ($i) => $i->whereRaw('LOWER(service_description) LIKE ?', ['%sewa%']))
                            ->orWhereHas('sessionReports.cabang', fn ($sc) => $sc->whereRaw('LOWER(nama_cabang) LIKE ?', ['%express sewa%']))
                            ->orWhere(function (Builder $fallback) {
                                $fallback->whereRaw('LOWER(event_name) NOT LIKE ?', ['%self run%'])
                                    ->whereRaw('LOWER(COALESCE(notes, "")) NOT LIKE ?', ['%self run%'])
                                    ->whereDoesntHave('invoiceItems', fn ($i) => $i->whereRaw('LOWER(service_description) LIKE ?', ['%self run%']))
                                    ->whereDoesntHave('sessionReports.cabang', fn ($sc) => $sc->whereRaw('LOWER(nama_cabang) LIKE ?', ['%self run%']));
                            });
                    })
                    ->whereDoesntHave('sessionReports.cabang', fn ($sc) => $sc->whereRaw('LOWER(nama_cabang) LIKE ?', ['%self run%']));
                });
            });
        }

        return $query;
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
