<?php

namespace App\Models;

use App\Utils\InventoryBranches;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStock extends Model
{
    use HasFactory;

    public const STATUS_AMAN = 'aman';

    public const STATUS_MENIPIS = 'menipis';

    public const STATUS_HABIS = 'habis';

    public const STATUS_LABELS = [
        self::STATUS_AMAN => 'Aman',
        self::STATUS_MENIPIS => 'Menipis',
        self::STATUS_HABIS => 'Habis',
    ];

    public const STATUS_COLORS = [
        self::STATUS_AMAN => 'success',
        self::STATUS_MENIPIS => 'warning',
        self::STATUS_HABIS => 'danger',
    ];

    protected $table = 'inventory_stocks';

    protected $fillable = [
        'inventory_item_id',
        'cabang_id',
        'jumlah',
        'min_stok',
        'last_transaction_at',
    ];

    protected $casts = [
        'jumlah' => 'integer',
        'min_stok' => 'integer',
        'last_transaction_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class, 'cabang_id', 'cabang_id');
    }

    public function getStatusAttribute(): string
    {
        if ($this->jumlah <= 0) {
            return self::STATUS_HABIS;
        }

        if ($this->jumlah <= $this->min_stok) {
            return self::STATUS_MENIPIS;
        }

        return self::STATUS_AMAN;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'gray';
    }

    public function getInventoryBranchLabelAttribute(): string
    {
        return InventoryBranches::labelForCabangId($this->cabang_id, $this->cabang?->nama_cabang);
    }
}
