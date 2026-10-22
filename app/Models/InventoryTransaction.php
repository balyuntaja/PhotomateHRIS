<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransaction extends Model
{
    use HasFactory;

    public const TYPE_IN = 'masuk';

    public const TYPE_OUT = 'keluar';

    public const TYPE_ADJUSTMENT = 'penyesuaian';

    public const TYPE_LABELS = [
        self::TYPE_IN => 'Barang Masuk',
        self::TYPE_OUT => 'Barang Keluar',
        self::TYPE_ADJUSTMENT => 'Penyesuaian Stok',
    ];

    public const TYPE_COLORS = [
        self::TYPE_IN => 'success',
        self::TYPE_OUT => 'danger',
        self::TYPE_ADJUSTMENT => 'warning',
    ];

    public const TYPE_ICONS = [
        self::TYPE_IN => 'heroicon-m-arrow-down-tray',
        self::TYPE_OUT => 'heroicon-m-arrow-up-tray',
        self::TYPE_ADJUSTMENT => 'heroicon-m-adjustments-horizontal',
    ];

    protected $table = 'inventory_transactions';

    protected $fillable = [
        'nomor_transaksi',
        'idempotency_key',
        'tanggal_transaksi',
        'jenis_transaksi',
        'inventory_item_id',
        'cabang_id',
        'jumlah',
        'stok_sebelum',
        'stok_sesudah',
        'alasan',
        'user_id',
        'user_name',
    ];

    protected $casts = [
        'tanggal_transaksi' => 'date',
        'jumlah' => 'integer',
        'stok_sebelum' => 'integer',
        'stok_sesudah' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            $transaction->user_id ??= auth()->id();

            if (filled($transaction->user_id) && blank($transaction->user_name)) {
                $transaction->user_name = Karyawan::withTrashed()
                    ->whereKey($transaction->user_id)
                    ->value('nama_lengkap');
            }
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class, 'cabang_id', 'cabang_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'user_id', 'karyawan_id')->withTrashed();
    }

    public function getJumlahMasukAttribute(): int
    {
        return max(0, $this->stok_sesudah - $this->stok_sebelum);
    }

    public function getJumlahKeluarAttribute(): int
    {
        return max(0, $this->stok_sebelum - $this->stok_sesudah);
    }

    public function getJenisLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->jenis_transaksi] ?? $this->jenis_transaksi;
    }

    public function getJenisColorAttribute(): string
    {
        return self::TYPE_COLORS[$this->jenis_transaksi] ?? 'gray';
    }
}
