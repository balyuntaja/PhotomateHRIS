<?php

namespace App\Models;

use App\Utils\InventoryBranches;
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

    protected ?array $inventoryBranchSummaryCache = null;

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

        return (int) collect($this->inventoryBranchStockSummaries())->sum('stock');
    }

    public function getBranchStockCountAttribute(): int
    {
        return collect($this->inventoryBranchStockSummaries())
            ->filter(fn (array $summary): bool => $summary['stock'] > 0)
            ->count();
    }

    public function getLowStockBranchCountAttribute(): int
    {
        return collect($this->inventoryBranchStockSummaries())
            ->filter(fn (array $summary): bool => $summary['stock'] > 0 && $summary['stock'] <= $summary['min'])
            ->count();
    }

    /**
     * Ringkasan stok per cabang Inventory utama (Express, Golio,
     * Newspaper Janus). Stok anggota Express dijumlahkan menjadi satu.
     *
     * @return array<string, array{stock: int, min: int}>
     */
    public function inventoryBranchStockSummaries(): array
    {
        if ($this->inventoryBranchSummaryCache !== null) {
            return $this->inventoryBranchSummaryCache;
        }

        $keys = InventoryBranches::ORDER;
        $aggregatesLoaded = true;

        foreach ($keys as $key) {
            if (! array_key_exists('stock_sum_'.$key, $this->attributes)) {
                $aggregatesLoaded = false;
                break;
            }
        }

        if ($aggregatesLoaded) {
            $summaries = [];

            foreach ($keys as $key) {
                $summaries[$key] = [
                    'stock' => (int) ($this->attributes['stock_sum_'.$key] ?? 0),
                    'min' => (int) ($this->attributes['stock_min_'.$key] ?? 0),
                ];
            }

            return $this->inventoryBranchSummaryCache = $summaries;
        }

        $groups = InventoryBranches::readGroups();
        $summaries = [];
        $memberMap = [];

        foreach ($groups as $key => $group) {
            $summaries[$key] = ['stock' => 0, 'min' => 0];

            foreach ($group['members'] as $memberId) {
                $memberMap[$memberId] = $key;
            }
        }

        $canonicalFound = [];

        foreach ($this->stocks()->get(['cabang_id', 'jumlah', 'min_stok']) as $stock) {
            $cabangId = (string) $stock->cabang_id;
            $key = $memberMap[$cabangId] ?? null;

            if ($key === null) {
                continue;
            }

            $summaries[$key]['stock'] += (int) $stock->jumlah;

            if ($cabangId === $groups[$key]['cabang_id']) {
                $summaries[$key]['min'] = (int) $stock->min_stok;
                $canonicalFound[$key] = true;
            } elseif (! ($canonicalFound[$key] ?? false)) {
                $summaries[$key]['min'] = max($summaries[$key]['min'], (int) $stock->min_stok);
            }
        }

        return $this->inventoryBranchSummaryCache = $summaries;
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
