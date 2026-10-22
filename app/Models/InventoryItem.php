<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    use HasFactory;

    public const SATUAN_OPTIONS = [
        'pcs' => 'pcs',
        'pack' => 'pack',
        'rim' => 'rim',
        'roll' => 'roll',
        'botol' => 'botol',
        'unit' => 'unit',
        'box' => 'box',
        'lusin' => 'lusin',
        'set' => 'set',
    ];

    protected $table = 'inventory_items';

    protected $fillable = [
        'kode_barang',
        'nama_barang',
        'kategori',
        'satuan',
        'min_stok_default',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'min_stok_default' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            $item->created_by ??= auth()->id();
        });

        static::updating(function (self $item): void {
            $item->updated_by = auth()->id();
        });
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'inventory_item_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'inventory_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'created_by', 'karyawan_id')->withTrashed();
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'updated_by', 'karyawan_id')->withTrashed();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getTotalStockAttribute(): int
    {
        if (array_key_exists('total_stock_sum', $this->attributes)) {
            return (int) $this->attributes['total_stock_sum'];
        }

        return (int) $this->stocks()->sum('jumlah');
    }

    public function getBranchStockCountAttribute(): int
    {
        if (array_key_exists('branch_stock_count', $this->attributes)) {
            return (int) $this->attributes['branch_stock_count'];
        }

        return $this->stocks()->where('jumlah', '>', 0)->count();
    }

    public function getLowStockBranchCountAttribute(): int
    {
        if (array_key_exists('low_stock_branch_count', $this->attributes)) {
            return (int) $this->attributes['low_stock_branch_count'];
        }

        return $this->stocks()
            ->where('jumlah', '>', 0)
            ->whereColumn('jumlah', '<=', 'min_stok')
            ->count();
    }

    public function getStockStatusAttribute(): string
    {
        if ($this->total_stock === 0) {
            return InventoryStock::STATUS_HABIS;
        }

        if ($this->low_stock_branch_count > 0) {
            return InventoryStock::STATUS_MENIPIS;
        }

        return InventoryStock::STATUS_AMAN;
    }

    public function getStockStatusLabelAttribute(): string
    {
        return InventoryStock::STATUS_LABELS[$this->stock_status] ?? $this->stock_status;
    }

    public function getStockStatusColorAttribute(): string
    {
        return InventoryStock::STATUS_COLORS[$this->stock_status] ?? 'gray';
    }
}
