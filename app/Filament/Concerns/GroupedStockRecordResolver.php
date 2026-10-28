<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolusi record untuk tabel stok Inventory yang memakai query agregat.
 *
 * Kolom id pada derived table tidak memiliki affinity numerik, sehingga
 * pencarian dengan key string (dari Livewire) tidak menemukan baris.
 * Resolver ini meng-cast key numerik ke integer sebelum whereKey.
 */
class GroupedStockRecordResolver
{
    public static function resolve(object $component, ?string $key): ?Model
    {
        if ($key === null) {
            return null;
        }

        /** @var Builder $query */
        $query = $component->getFilteredTableQuery();

        return ctype_digit($key)
            ? $query->whereKey((int) $key)->first()
            : $query->whereKey($key)->first();
    }
}
