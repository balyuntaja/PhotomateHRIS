<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Utils\InventoryAccess;
use App\Utils\InventoryBranches;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function ensureStocks(InventoryItem $item): int
    {
        InventoryAccess::authorize();

        return $this->ensureStocksForItem($item);
    }

    public function syncStocks(?InventoryItem $item = null): int
    {
        InventoryAccess::authorize();

        if ($item !== null) {
            return $this->ensureStocksForItem($item);
        }

        $branchIds = array_values(InventoryBranches::canonicalIds());
        $created = 0;

        InventoryItem::query()
            ->active()
            ->select(['id', 'min_stok_default'])
            ->chunkById(100, function ($items) use ($branchIds, &$created): void {
                foreach ($items as $item) {
                    foreach ($branchIds as $branchId) {
                        $created += $this->insertStockIfMissing(
                            (int) $item->id,
                            (string) $branchId,
                            (int) $item->min_stok_default,
                        );
                    }
                }
            });

        return $created;
    }

    public function updateMinStock(InventoryStock $stock, int $minStock): InventoryStock
    {
        InventoryAccess::authorize();

        $validated = Validator::make(
            ['min_stok' => $minStock],
            ['min_stok' => ['required', 'integer', 'min:0']],
            ['min_stok.min' => 'Batas minimum stok tidak boleh negatif.'],
        )->validate();

        $cabangId = InventoryBranches::normalizeCabangIdForWrite($stock->cabang_id)
            ?? (string) $stock->cabang_id;

        $this->insertStockIfMissing(
            (int) $stock->inventory_item_id,
            $cabangId,
            (int) $stock->min_stok,
        );

        $target = InventoryStock::query()
            ->where('inventory_item_id', $stock->inventory_item_id)
            ->where('cabang_id', $cabangId)
            ->firstOrFail();

        $target->update(['min_stok' => $validated['min_stok']]);

        return $target->refresh();
    }

    /**
     * Query stok Inventory yang sudah digabungkan per cabang utama.
     * Satu baris per barang per cabang (Express/Golio/Newspaper Janus).
     */
    public function stockQuery(?string $cabangId = null): Builder
    {
        InventoryAccess::authorize();

        return $this->buildStockQuery($cabangId);
    }

    public function record(array $data): InventoryTransaction
    {
        InventoryAccess::authorize();

        $data['cabang_id'] = InventoryBranches::normalizeCabangIdForWrite($data['cabang_id'] ?? null);

        $validated = $this->validateTransaction($data);
        $idempotencyKey = filled($validated['idempotency_key'] ?? null)
            ? (string) $validated['idempotency_key']
            : null;

        if ($idempotencyKey !== null) {
            $existing = InventoryTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $this->resolveIdempotentRetry($existing, $validated);
            }
        }

        try {
            return DB::transaction(fn (): InventoryTransaction => $this->persistTransaction($validated, $idempotencyKey));
        } catch (UniqueConstraintViolationException $exception) {
            if ($idempotencyKey !== null) {
                $existing = InventoryTransaction::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $this->resolveIdempotentRetry($existing, $validated);
                }
            }

            throw $exception;
        }
    }

    public function summary(?string $cabangId = null): array
    {
        InventoryAccess::authorize();

        $branchId = InventoryBranches::filterCanonicalId($cabangId);

        $stockQuery = $this->buildStockQuery($branchId)
            ->whereHas('item', fn (Builder $query): Builder => $query->active());

        $totalStock = (int) (clone $stockQuery)->sum('jumlah');

        $lowStockQuery = fn (): Builder => (clone $stockQuery)
            ->where('jumlah', '>', 0)
            ->whereColumn('jumlah', '<=', 'min_stok');

        if ($branchId !== null) {
            $totalItems = (clone $stockQuery)->distinct()->count('inventory_item_id');

            $lowStock = $lowStockQuery()->distinct()->count('inventory_item_id');

            $outOfStock = (clone $stockQuery)
                ->where('jumlah', 0)
                ->distinct()
                ->count('inventory_item_id');
        } else {
            $totalItems = InventoryItem::query()->active()->count();

            $lowStock = $lowStockQuery()->distinct()->count('inventory_item_id');

            $outOfStock = InventoryItem::query()
                ->active()
                ->whereNotIn(
                    'id',
                    (clone $stockQuery)->select('inventory_item_id')->where('jumlah', '>', 0),
                )
                ->count();
        }

        return [
            'total_items' => $totalItems,
            'total_stock' => $totalStock,
            'low_stock' => $lowStock,
            'out_of_stock' => $outOfStock,
            'cabang_id' => $branchId,
        ];
    }

    public function attentionQuery(?string $cabangId = null): Builder
    {
        InventoryAccess::authorize();

        return $this->buildStockQuery($cabangId)
            ->with(['item', 'cabang'])
            ->whereHas('item', fn (Builder $query): Builder => $query->active())
            ->where(function (Builder $query): void {
                $query->where('jumlah', 0)
                    ->orWhere(function (Builder $query): void {
                        $query->where('jumlah', '>', 0)
                            ->whereColumn('jumlah', '<=', 'min_stok');
                    });
            })
            ->orderByRaw('case when jumlah = 0 then 0 else 1 end')
            ->orderBy('jumlah')
            ->orderBy('cabang_id');
    }

    public function stockSnapshot(?int $itemId, ?string $cabangId): ?array
    {
        InventoryAccess::authorize();

        if (blank($itemId) || blank($cabangId)) {
            return null;
        }

        $stock = $this->buildStockQuery($cabangId)
            ->with('item')
            ->where('inventory_item_id', $itemId)
            ->first();

        if (! $stock) {
            return null;
        }

        return [
            'jumlah' => (int) $stock->jumlah,
            'min_stok' => (int) $stock->min_stok,
            'satuan' => $stock->item?->satuan ?? '',
            'status' => $stock->status_label,
            'status_color' => $stock->status_color,
        ];
    }

    private function buildStockQuery(?string $cabangId = null): Builder
    {
        $groups = InventoryBranches::readGroups();

        if ($groups === []) {
            return InventoryStock::query()->whereRaw('1 = 0');
        }

        $caseParts = [];
        $caseBindings = [];
        $minimumParts = [];
        $minimumBindings = [];
        $memberIds = [];

        foreach ($groups as $group) {
            $members = $group['members'];
            $canonicalId = $group['cabang_id'];

            $memberIds = array_merge($memberIds, $members);

            $placeholders = implode(', ', array_fill(0, count($members), '?'));

            $caseParts[] = "when cabang_id in ({$placeholders}) then ?";

            foreach ($members as $memberId) {
                $caseBindings[] = $memberId;
            }

            $caseBindings[] = $canonicalId;

            $minimumParts[] = 'when cabang_id = ? then min_stok';
            $minimumBindings[] = $canonicalId;
        }

        $caseSql = 'case '.implode(' ', $caseParts).' else cabang_id end';
        $minimumSql = 'coalesce(max(case '.implode(' ', $minimumParts).' end), max(min_stok), 0)';

        $memberIds = array_values(array_unique($memberIds));

        $subQuery = DB::table('inventory_stocks')
            ->selectRaw(
                "min(id) as id, inventory_item_id, ({$caseSql}) as cabang_id, sum(jumlah) as jumlah, {$minimumSql} as min_stok, max(last_transaction_at) as last_transaction_at, min(created_at) as created_at, max(updated_at) as updated_at",
                array_merge($caseBindings, $minimumBindings),
            )
            ->whereIn('cabang_id', $memberIds)
            ->groupByRaw("inventory_item_id, ({$caseSql})", $caseBindings);

        $query = InventoryStock::query()
            ->fromSub($subQuery, 'inventory_stocks')
            ->with(['item', 'cabang']);

        if (filled($cabangId)) {
            $query->where('cabang_id', InventoryBranches::filterCanonicalId($cabangId));
        }

        return $query;
    }

    private function ensureStocksForItem(InventoryItem $item): int
    {
        $created = 0;

        foreach (array_values(InventoryBranches::canonicalIds()) as $branchId) {
            $created += $this->insertStockIfMissing(
                (int) $item->id,
                (string) $branchId,
                (int) $item->min_stok_default,
            );
        }

        return $created;
    }

    private function insertStockIfMissing(int $itemId, string $branchId, int $defaultMinStock): int
    {
        return InventoryStock::query()->insertOrIgnore([
            'inventory_item_id' => $itemId,
            'cabang_id' => $branchId,
            'jumlah' => 0,
            'min_stok' => $defaultMinStock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validateTransaction(array $data): array
    {
        return Validator::make(
            $data,
            [
                'jenis_transaksi' => ['required', Rule::in(array_keys(InventoryTransaction::TYPE_LABELS))],
                'tanggal_transaksi' => ['required', 'date'],
                'inventory_item_id' => [
                    'required',
                    'integer',
                    Rule::exists('inventory_items', 'id'),
                ],
                'cabang_id' => [
                    'required',
                    'string',
                    'max:5',
                    Rule::exists('cabang', 'cabang_id')->whereNull('deleted_at'),
                    Rule::in(array_values(InventoryBranches::canonicalIds())),
                ],
                'jumlah' => ['nullable', 'integer', 'min:1', 'required_if:jenis_transaksi,masuk,keluar'],
                'stok_aktual' => ['nullable', 'integer', 'min:0', 'required_if:jenis_transaksi,penyesuaian'],
                'alasan' => ['nullable', 'string', 'max:500', 'required_if:jenis_transaksi,penyesuaian'],
                'idempotency_key' => ['nullable', 'string', 'max:64'],
            ],
            [
                'jenis_transaksi.required' => 'Jenis transaksi wajib dipilih.',
                'jenis_transaksi.in' => 'Jenis transaksi tidak valid.',
                'tanggal_transaksi.required' => 'Tanggal transaksi wajib diisi.',
                'tanggal_transaksi.date' => 'Tanggal transaksi tidak valid.',
                'inventory_item_id.required' => 'Barang wajib dipilih.',
                'inventory_item_id.exists' => 'Barang tidak ditemukan.',
                'cabang_id.required' => 'Cabang wajib dipilih.',
                'cabang_id.exists' => 'Cabang tidak ditemukan.',
                'cabang_id.in' => 'Cabang bukan bagian dari Inventory (Express, Golio, Newspaper Janus).',
                'jumlah.required_if' => 'Jumlah transaksi wajib diisi.',
                'jumlah.integer' => 'Jumlah transaksi harus berupa bilangan bulat.',
                'jumlah.min' => 'Jumlah transaksi harus lebih dari nol.',
                'stok_aktual.required_if' => 'Stok aktual wajib diisi untuk penyesuaian.',
                'stok_aktual.integer' => 'Stok aktual harus berupa bilangan bulat.',
                'stok_aktual.min' => 'Stok aktual tidak boleh negatif.',
                'alasan.required_if' => 'Alasan wajib diisi untuk penyesuaian stok.',
                'alasan.max' => 'Alasan maksimal 500 karakter.',
            ],
        )->validate();
    }

    private function persistTransaction(array $data, ?string $idempotencyKey): InventoryTransaction
    {
        $item = InventoryItem::query()
            ->whereKey($data['inventory_item_id'])
            ->lockForUpdate()
            ->first();

        if (! $item) {
            throw ValidationException::withMessages([
                'inventory_item_id' => 'Barang tidak ditemukan.',
            ]);
        }

        if (! $item->is_active) {
            throw ValidationException::withMessages([
                'inventory_item_id' => 'Barang sudah nonaktif dan tidak dapat digunakan untuk transaksi baru.',
            ]);
        }

        $cabangId = (string) $data['cabang_id'];

        $this->insertStockIfMissing(
            (int) $item->id,
            $cabangId,
            (int) $item->min_stok_default,
        );

        $stocks = $this->lockBranchStocks((int) $item->id, $cabangId);

        $canonicalStock = $stocks->get($cabangId);

        $before = (int) $stocks->sum(fn (InventoryStock $stock): int => (int) $stock->jumlah);
        $type = $data['jenis_transaksi'];

        $after = match ($type) {
            InventoryTransaction::TYPE_IN => $before + (int) $data['jumlah'],
            InventoryTransaction::TYPE_OUT => $before - (int) $data['jumlah'],
            InventoryTransaction::TYPE_ADJUSTMENT => (int) $data['stok_aktual'],
        };

        if ($type === InventoryTransaction::TYPE_OUT && $after < 0) {
            throw ValidationException::withMessages([
                'jumlah' => 'Stok tidak mencukupi. Stok tersedia di cabang ini: '.$before.' '.$item->satuan.'.',
            ]);
        }

        if ($after < 0) {
            throw ValidationException::withMessages([
                'stok_aktual' => 'Stok aktual tidak boleh negatif.',
            ]);
        }

        if ($type === InventoryTransaction::TYPE_ADJUSTMENT && $after === $before) {
            throw ValidationException::withMessages([
                'stok_aktual' => 'Stok aktual sama dengan stok sistem ('.$before.' '.$item->satuan.'). Tidak ada perubahan yang perlu dicatat.',
            ]);
        }

        $this->applyAggregateStockChange($stocks, $canonicalStock, $after - $before);

        return InventoryTransaction::create([
            'nomor_transaksi' => $this->generateTransactionNumber(),
            'idempotency_key' => $idempotencyKey,
            'tanggal_transaksi' => $data['tanggal_transaksi'],
            'jenis_transaksi' => $type,
            'inventory_item_id' => $item->id,
            'cabang_id' => $cabangId,
            'jumlah' => abs($after - $before),
            'stok_sebelum' => $before,
            'stok_sesudah' => $after,
            'alasan' => filled($data['alasan'] ?? null) ? trim((string) $data['alasan']) : null,
            'user_id' => auth()->id(),
            'user_name' => auth()->user()?->nama_lengkap,
        ]);
    }

    /**
     * Kunci seluruh baris stok anggota kelompok cabang (mis. semua cabang
     * Express) agar perubahan stok agregat tetap konsisten.
     *
     * @return Collection<string, InventoryStock>
     */
    private function lockBranchStocks(int $itemId, string $cabangId): Collection
    {
        $memberIds = InventoryBranches::expandCabangIds($cabangId) ?? [$cabangId];

        return InventoryStock::query()
            ->where('inventory_item_id', $itemId)
            ->whereIn('cabang_id', $memberIds)
            ->orderBy('cabang_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('cabang_id');
    }

    /**
     * Terapkan selisih stok agregat ke baris-baris anggota. Penambahan
     * masuk ke baris kanonis; pengurangan diambil dari baris kanonis lalu
     * baris anggota lain. Tidak ada baris yang dihapus.
     *
     * @param  Collection<string, InventoryStock>  $stocks
     */
    private function applyAggregateStockChange(Collection $stocks, ?InventoryStock $canonicalStock, int $delta): void
    {
        if ($delta === 0 || ! $canonicalStock) {
            return;
        }

        $now = now();

        if ($delta > 0) {
            $canonicalStock->update([
                'jumlah' => (int) $canonicalStock->jumlah + $delta,
                'last_transaction_at' => $now,
            ]);

            return;
        }

        $remaining = -$delta;

        $ordered = collect([$canonicalStock])
            ->merge($stocks->except($canonicalStock->cabang_id)->sortKeys());

        foreach ($ordered as $stock) {
            if ($remaining <= 0) {
                break;
            }

            $deduct = min((int) $stock->jumlah, $remaining);

            if ($deduct <= 0) {
                continue;
            }

            $stock->update([
                'jumlah' => (int) $stock->jumlah - $deduct,
                'last_transaction_at' => $now,
            ]);

            $remaining -= $deduct;
        }
    }

    private function resolveIdempotentRetry(InventoryTransaction $existing, array $data): InventoryTransaction
    {
        $sameRequest = (int) $existing->inventory_item_id === (int) $data['inventory_item_id']
            && InventoryBranches::normalizeCabangIdForWrite((string) $existing->cabang_id) === (string) $data['cabang_id']
            && $existing->jenis_transaksi === $data['jenis_transaksi']
            && $existing->tanggal_transaksi?->toDateString() === Carbon::parse($data['tanggal_transaksi'])->toDateString();

        if ($sameRequest && $data['jenis_transaksi'] === InventoryTransaction::TYPE_ADJUSTMENT) {
            $sameRequest = (int) $existing->stok_sesudah === (int) $data['stok_aktual'];
        }

        if ($sameRequest && in_array($data['jenis_transaksi'], [InventoryTransaction::TYPE_IN, InventoryTransaction::TYPE_OUT], true)) {
            $sameRequest = (int) $existing->jumlah === (int) $data['jumlah'];
        }

        if (! $sameRequest) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Kunci idempotensi sudah digunakan untuk transaksi lain. Muat ulang form sebelum menyimpan.',
            ]);
        }

        return $existing;
    }

    private function generateTransactionNumber(): string
    {
        do {
            $number = 'INV-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
        } while (InventoryTransaction::query()->where('nomor_transaksi', $number)->exists());

        return $number;
    }
}
