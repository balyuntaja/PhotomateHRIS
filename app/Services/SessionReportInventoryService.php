<?php

namespace App\Services;

use App\Models\Cabang;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Utils\InventoryBranches;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SessionReportInventoryService
{
    /**
     * Periode awal pencatatan stok otomatis dari Rekap Sesi (inklusif).
     * Acuan yang digunakan selalu report_date (Tanggal Sesi).
     */
    public const CUTOFF_DATE = '2026-11-07';

    public const REFERENCE_TYPE = InventoryTransaction::REFERENCE_SESSION_REPORT;

    public const PHOTO_PAPER_NAME = 'Kertas Foto';

    public const DEFAULT_DEDUCTION_NOTE = 'Penggunaan kertas foto otomatis dari rekap sesi';

    public const SYSTEM_ACTOR_NAME = 'Sistem Otomatis';

    /**
     * Cabang Inventory yang berhak menggunakan pengurangan stok otomatis dari Rekap Sesi.
     * Golio secara eksplisit dikecualikan agar tetap 100% manual.
     *
     * @var array<int, string>
     */
    public const ELIGIBLE_BRANCH_KEYS = [
        InventoryBranches::EXPRESS,
        InventoryBranches::JANUS,
    ];

    /**
     * Tentukan kunci cabang kanonis Inventory jika cabang rekap termasuk
     * Express (Sharing Profit, Self Run, Sewa, Express) atau Newspaper Janus.
     * Mengembalikan null untuk Golio maupun cabang non-Inventory.
     */
    public function resolveEligibleBranchKey(SessionReport $report): ?string
    {
        $key = InventoryBranches::canonicalKeyForCabangId($report->cabang_id);

        if ($key === null) {
            $cabang = $report->resolveCabangWithTrashed();
            $key = InventoryBranches::canonicalKeyForName($cabang?->nama_cabang);
        }

        if ($key === null || ! in_array($key, self::ELIGIBLE_BRANCH_KEYS, true)) {
            return null;
        }

        return $key;
    }

    /**
     * Resolusi cabang_id kanonis Inventory untuk pencatatan stok & riwayat transaksi.
     */
    public function resolveTargetCabangId(SessionReport $report): ?string
    {
        $branchKey = $this->resolveEligibleBranchKey($report);
        if ($branchKey === null) {
            return null;
        }

        $canonicalIds = InventoryBranches::canonicalIds();
        $candidateId = $canonicalIds[$branchKey]
            ?? InventoryBranches::normalizeCabangIdForWrite($report->cabang_id);

        if (blank($candidateId)) {
            return null;
        }

        $exists = Cabang::query()
            ->whereNull('deleted_at')
            ->where('cabang_id', $candidateId)
            ->exists();

        return $exists ? (string) $candidateId : null;
    }

    /**
     * Periksa apakah Tanggal Sesi (report_date) berada pada atau setelah 7 November 2026.
     */
    public function isWithinInventoryPeriod(SessionReport $report): bool
    {
        if (! $report->report_date) {
            return false;
        }

        return $report->report_date->toDateString() >= self::CUTOFF_DATE;
    }

    /**
     * Periksa apakah Rekap Sesi memenuhi seluruh syarat pengurangan stok otomatis.
     */
    public function isEligibleForAutoDeduction(SessionReport $report): bool
    {
        return $report->status === SessionReport::STATUS_APPROVED
            && $this->resolveTargetCabangId($report) !== null
            && $this->isWithinInventoryPeriod($report)
            && $report->totalSheetCount() > 0;
    }

    /**
     * Cari master barang Kertas Foto yang aktif pada tabel inventory_items.
     * Jika rekap sesi sudah pernah memiliki transaksi stok sebelumnya, prioritaskan
     * item yang sama agar koreksi/pembalikan tetap konsisten terhadap barang tersebut.
     */
    public function resolvePhotoPaperItem(?int $sessionReportId = null, bool $includeInactiveForExisting = false): ?InventoryItem
    {
        if ($sessionReportId !== null && $this->hasReferenceColumns()) {
            $existingItemId = InventoryTransaction::query()
                ->where('session_report_id', $sessionReportId)
                ->where('reference_type', self::REFERENCE_TYPE)
                ->orderByDesc('id')
                ->value('inventory_item_id');

            if ($existingItemId) {
                $existingQuery = InventoryItem::query()->whereKey($existingItemId);
                if (! $includeInactiveForExisting) {
                    $existingQuery->where('is_active', true);
                }

                $existingItem = $existingQuery->first();
                if ($existingItem) {
                    return $existingItem;
                }
            }
        }

        $activeItems = InventoryItem::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($activeItems->isEmpty()) {
            return null;
        }

        // 1. Prioritas kecocokan nama eksak "Kertas Foto"
        $exactMatch = $activeItems->first(
            fn (InventoryItem $item): bool => $this->normalizeLabel($item->nama_barang) === 'kertas foto'
        );
        if ($exactMatch) {
            return $exactMatch;
        }

        // 2. Nama diawali "Kertas Foto" (mis. Kertas Foto 4R / Kertas Foto DNP)
        $prefixMatch = $activeItems->first(
            fn (InventoryItem $item): bool => str_starts_with($this->normalizeLabel($item->nama_barang), 'kertas foto')
        );
        if ($prefixMatch) {
            return $prefixMatch;
        }

        // 3. Nama mengandung "Kertas Foto"
        $containsMatch = $activeItems->first(
            fn (InventoryItem $item): bool => str_contains($this->normalizeLabel($item->nama_barang), 'kertas foto')
        );
        if ($containsMatch) {
            return $containsMatch;
        }

        // 4. Kategori adalah "Kertas Foto"
        return $activeItems->first(
            fn (InventoryItem $item): bool => $this->normalizeLabel($item->kategori) === 'kertas foto'
        );
    }

    /**
     * Sinkronkan penggunaan stok Kertas Foto untuk satu Rekap Sesi secara idempoten:
     * - Jika baru Approved dan memenuhi syarat -> catat Barang Keluar sebesar Total Lembar.
     * - Jika dipanggil ulang tanpa perubahan -> tidak membuat transaksi ganda (null).
     * - Jika Total Lembar pada rekap Approved berubah -> catat hanya selisihnya (keluar / masuk).
     * - Jika rekap tidak lagi memenuhi syarat tetapi sebelumnya sudah mengurangi stok -> catat reversal.
     */
    public function syncForReport(
        SessionReport $report,
        ?Karyawan $actor = null,
        ?string $reasonContext = null,
    ): ?InventoryTransaction {
        if (! $this->hasReferenceColumns() || ! $report->id) {
            return null;
        }

        return DB::transaction(function () use ($report, $actor, $reasonContext): ?InventoryTransaction {
            $lockedReport = SessionReport::query()
                ->with(['cabang', 'approver'])
                ->lockForUpdate()
                ->find($report->id) ?? $report;

            $existingTransactions = InventoryTransaction::query()
                ->where('session_report_id', $lockedReport->id)
                ->where('reference_type', self::REFERENCE_TYPE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $netBuckets = $this->calculateNetDeductedBuckets($existingTransactions);
            $nextSequence = $existingTransactions->count() + 1;

            $isEligible = $this->isEligibleForAutoDeduction($lockedReport);
            $targetCabangId = $isEligible ? $this->resolveTargetCabangId($lockedReport) : null;
            $targetItem = $isEligible ? $this->resolvePhotoPaperItem($lockedReport->id) : null;
            $targetSheets = ($isEligible && $targetCabangId !== null && $targetItem !== null)
                ? $lockedReport->totalSheetCount()
                : 0;

            $targetBucketKey = ($targetItem && $targetCabangId)
                ? $this->bucketKey((int) $targetItem->id, $targetCabangId)
                : null;

            $lastRecorded = null;

            // Kembalikan stok pada bucket lama apabila cabang/item berubah atau rekap tidak lagi eligible
            foreach ($netBuckets as $bucketKey => $bucket) {
                if ($bucketKey === $targetBucketKey || $bucket['net_deducted'] <= 0) {
                    continue;
                }

                $reversalNote = $this->buildReversalDescription($lockedReport, $reasonContext);
                $lastRecorded = $this->recordAutomaticMovement(
                    report: $lockedReport,
                    itemId: $bucket['item_id'],
                    canonicalCabangId: $bucket['cabang_id'],
                    type: InventoryTransaction::TYPE_IN,
                    quantity: $bucket['net_deducted'],
                    description: $reversalNote,
                    sequence: $nextSequence++,
                    actor: $actor,
                    allowInactiveItem: true,
                );
            }

            if ($targetBucketKey === null || $targetSheets <= 0) {
                return $lastRecorded;
            }

            $currentNetDeducted = $netBuckets[$targetBucketKey]['net_deducted'] ?? 0;
            $difference = $targetSheets - $currentNetDeducted;

            if ($difference === 0) {
                return $lastRecorded;
            }

            if ($currentNetDeducted === 0 && $difference > 0) {
                return $this->recordAutomaticMovement(
                    report: $lockedReport,
                    itemId: (int) $targetItem->id,
                    canonicalCabangId: $targetCabangId,
                    type: InventoryTransaction::TYPE_OUT,
                    quantity: $difference,
                    description: $this->buildInitialDeductionDescription($lockedReport),
                    sequence: $nextSequence,
                    actor: $actor,
                );
            }

            if ($difference > 0) {
                return $this->recordAutomaticMovement(
                    report: $lockedReport,
                    itemId: (int) $targetItem->id,
                    canonicalCabangId: $targetCabangId,
                    type: InventoryTransaction::TYPE_OUT,
                    quantity: $difference,
                    description: $this->buildDeltaOutDescription($lockedReport, $reasonContext),
                    sequence: $nextSequence,
                    actor: $actor,
                );
            }

            return $this->recordAutomaticMovement(
                report: $lockedReport,
                itemId: (int) $targetItem->id,
                canonicalCabangId: $targetCabangId,
                type: InventoryTransaction::TYPE_IN,
                quantity: abs($difference),
                description: $this->buildDeltaInDescription($lockedReport, $reasonContext),
                sequence: $nextSequence,
                actor: $actor,
                allowInactiveItem: true,
            );
        });
    }

    /**
     * Lakukan pembalikan (reversal) penuh atas stok Kertas Foto yang pernah dikurangi
     * oleh Rekap Sesi ini tanpa menghapus histori transaksi sebelumnya.
     */
    public function reverseForReport(
        SessionReport $report,
        ?Karyawan $actor = null,
        ?string $reason = null,
    ): ?InventoryTransaction {
        if (! $this->hasReferenceColumns() || ! $report->id) {
            return null;
        }

        return DB::transaction(function () use ($report, $actor, $reason): ?InventoryTransaction {
            $existingTransactions = InventoryTransaction::query()
                ->where('session_report_id', $report->id)
                ->where('reference_type', self::REFERENCE_TYPE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($existingTransactions->isEmpty()) {
                return null;
            }

            $netBuckets = $this->calculateNetDeductedBuckets($existingTransactions);
            $nextSequence = $existingTransactions->count() + 1;
            $lastReversal = null;

            foreach ($netBuckets as $bucket) {
                if ($bucket['net_deducted'] <= 0) {
                    continue;
                }

                $lastReversal = $this->recordAutomaticMovement(
                    report: $report,
                    itemId: $bucket['item_id'],
                    canonicalCabangId: $bucket['cabang_id'],
                    type: InventoryTransaction::TYPE_IN,
                    quantity: $bucket['net_deducted'],
                    description: $this->buildReversalDescription($report, $reason),
                    sequence: $nextSequence++,
                    actor: $actor,
                    allowInactiveItem: true,
                );
            }

            return $lastReversal;
        });
    }

    /**
     * Hitung total bersih lembar yang saat ini masih terpotong untuk suatu Rekap Sesi.
     */
    public function getNetDeductedSheets(SessionReport $report): int
    {
        if (! $this->hasReferenceColumns() || ! $report->id) {
            return 0;
        }

        $transactions = InventoryTransaction::query()
            ->where('session_report_id', $report->id)
            ->where('reference_type', self::REFERENCE_TYPE)
            ->get();

        return (int) collect($this->calculateNetDeductedBuckets($transactions))
            ->sum('net_deducted');
    }

    /**
     * Sinkronisasi massal seluruh Rekap Sesi Approved yang memenuhi syarat sejak 7 November 2026.
     *
     * @return array{processed: int, created: int}
     */
    public function syncEligibleReports(?Karyawan $actor = null): array
    {
        if (! $this->hasReferenceColumns()) {
            return ['processed' => 0, 'created' => 0];
        }

        $eligibleCabangIds = array_values(array_unique(array_merge(
            InventoryBranches::expandCabangIds(InventoryBranches::canonicalIds()[InventoryBranches::EXPRESS] ?? null) ?? [],
            InventoryBranches::expandCabangIds(InventoryBranches::canonicalIds()[InventoryBranches::JANUS] ?? null) ?? [],
        )));

        if ($eligibleCabangIds === []) {
            return ['processed' => 0, 'created' => 0];
        }

        $reports = SessionReport::query()
            ->where('status', SessionReport::STATUS_APPROVED)
            ->whereIn('cabang_id', $eligibleCabangIds)
            ->whereDate('report_date', '>=', self::CUTOFF_DATE)
            ->orderBy('report_date')
            ->orderBy('id')
            ->get();

        $processed = 0;
        $created = 0;

        foreach ($reports as $report) {
            $processed++;
            if ($this->syncForReport($report, $actor) !== null) {
                $created++;
            }
        }

        return [
            'processed' => $processed,
            'created' => $created,
        ];
    }

    /**
     * @param  Collection<int, InventoryTransaction>  $transactions
     * @return array<string, array{item_id: int, cabang_id: string, net_deducted: int}>
     */
    private function calculateNetDeductedBuckets(Collection $transactions): array
    {
        $buckets = [];

        foreach ($transactions as $tx) {
            $canonicalCabangId = InventoryBranches::normalizeCabangIdForWrite((string) $tx->cabang_id)
                ?? (string) $tx->cabang_id;
            $itemId = (int) $tx->inventory_item_id;
            $key = $this->bucketKey($itemId, $canonicalCabangId);

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'item_id' => $itemId,
                    'cabang_id' => $canonicalCabangId,
                    'net_deducted' => 0,
                ];
            }

            if ($tx->jenis_transaksi === InventoryTransaction::TYPE_OUT) {
                $buckets[$key]['net_deducted'] += (int) $tx->jumlah;
            } elseif ($tx->jenis_transaksi === InventoryTransaction::TYPE_IN) {
                $buckets[$key]['net_deducted'] -= (int) $tx->jumlah;
            }
        }

        return $buckets;
    }

    private function recordAutomaticMovement(
        SessionReport $report,
        int $itemId,
        string $canonicalCabangId,
        string $type,
        int $quantity,
        string $description,
        int $sequence,
        ?Karyawan $actor = null,
        bool $allowInactiveItem = false,
    ): ?InventoryTransaction {
        if ($quantity <= 0) {
            return null;
        }

        $itemQuery = InventoryItem::query()->whereKey($itemId)->lockForUpdate();
        if (! $allowInactiveItem) {
            $itemQuery->where('is_active', true);
        }

        $item = $itemQuery->first();
        if (! $item) {
            return null;
        }

        $this->ensureCanonicalStockRowExists((int) $item->id, $canonicalCabangId, (int) $item->min_stok_default);

        $stocks = $this->lockBranchStocks((int) $item->id, $canonicalCabangId);
        $canonicalStock = $stocks->get($canonicalCabangId);
        if (! $canonicalStock) {
            return null;
        }

        $before = (int) $stocks->sum(fn (InventoryStock $stock): int => (int) $stock->jumlah);
        $signedDelta = $type === InventoryTransaction::TYPE_IN ? $quantity : -$quantity;
        $after = $before + $signedDelta;

        $this->applyAggregateStockChange($stocks, $canonicalStock, $signedDelta);

        [$userId, $userName] = $this->resolveActorIdentity($report, $actor);

        $transactionDate = $report->report_date?->toDateString() ?? now()->toDateString();
        $idempotencyKey = sprintf('sr-%d-seq-%d', $report->id, $sequence);

        return InventoryTransaction::create([
            'nomor_transaksi' => $this->generateTransactionNumber(),
            'idempotency_key' => $idempotencyKey,
            'session_report_id' => $report->id,
            'reference_type' => self::REFERENCE_TYPE,
            'reference_number' => $report->report_number,
            'tanggal_transaksi' => $transactionDate,
            'jenis_transaksi' => $type,
            'inventory_item_id' => $item->id,
            'cabang_id' => $canonicalCabangId,
            'jumlah' => $quantity,
            'stok_sebelum' => $before,
            'stok_sesudah' => $after,
            'alasan' => $description,
            'user_id' => $userId,
            'user_name' => $userName,
        ]);
    }

    private function ensureCanonicalStockRowExists(int $itemId, string $canonicalCabangId, int $minStock): void
    {
        $exists = InventoryStock::query()
            ->where('inventory_item_id', $itemId)
            ->where('cabang_id', $canonicalCabangId)
            ->exists();

        if ($exists) {
            return;
        }

        try {
            DB::transaction(function () use ($itemId, $canonicalCabangId, $minStock): void {
                InventoryStock::create([
                    'inventory_item_id' => $itemId,
                    'cabang_id' => $canonicalCabangId,
                    'jumlah' => 0,
                    'min_stok' => $minStock,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Baris stok sudah dibuat oleh proses paralel lain.
        }
    }

    /**
     * @return Collection<string, InventoryStock>
     */
    private function lockBranchStocks(int $itemId, string $canonicalCabangId): Collection
    {
        $memberIds = InventoryBranches::expandCabangIds($canonicalCabangId) ?? [$canonicalCabangId];

        return InventoryStock::query()
            ->where('inventory_item_id', $itemId)
            ->whereIn('cabang_id', $memberIds)
            ->orderBy('cabang_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('cabang_id');
    }

    /**
     * @param  Collection<string, InventoryStock>  $stocks
     */
    private function applyAggregateStockChange(Collection $stocks, InventoryStock $canonicalStock, int $delta): void
    {
        if ($delta === 0) {
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

            $available = max(0, (int) $stock->jumlah);
            $deduct = min($available, $remaining);

            if ($deduct <= 0) {
                continue;
            }

            $stock->update([
                'jumlah' => (int) $stock->jumlah - $deduct,
                'last_transaction_at' => $now,
            ]);

            $remaining -= $deduct;
        }

        // Apabila stok cabang belum sempat di-topup, bebankan selisih pada baris kanonis
        // agar total stok cabang selalu konsisten persis dengan stok_sesudah pada riwayat.
        if ($remaining > 0) {
            $canonicalStock->refresh();
            $canonicalStock->update([
                'jumlah' => (int) $canonicalStock->jumlah - $remaining,
                'last_transaction_at' => $now,
            ]);
        }
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function resolveActorIdentity(SessionReport $report, ?Karyawan $actor): array
    {
        $resolvedUser = $actor;

        if (! $resolvedUser && auth()->user() instanceof Karyawan) {
            $resolvedUser = auth()->user();
        }

        if (! $resolvedUser && filled($report->approved_by)) {
            $resolvedUser = $report->relationLoaded('approver') && $report->approver
                ? $report->approver
                : Karyawan::withTrashed()->find($report->approved_by);
        }

        if ($resolvedUser instanceof Karyawan) {
            return [
                (string) $resolvedUser->karyawan_id,
                (string) ($resolvedUser->nama_lengkap ?: self::SYSTEM_ACTOR_NAME),
            ];
        }

        return [null, self::SYSTEM_ACTOR_NAME];
    }

    private function buildInitialDeductionDescription(SessionReport $report): string
    {
        return sprintf(
            '%s (%s)',
            self::DEFAULT_DEDUCTION_NOTE,
            $report->report_number
        );
    }

    private function buildDeltaOutDescription(SessionReport $report, ?string $reasonContext = null): string
    {
        $base = sprintf(
            'Penyesuaian kurang kertas foto otomatis dari koreksi rekap sesi (%s)',
            $report->report_number
        );

        return filled($reasonContext) ? $base . ' - ' . trim($reasonContext) : $base;
    }

    private function buildDeltaInDescription(SessionReport $report, ?string $reasonContext = null): string
    {
        $base = sprintf(
            'Pengembalian selisih kertas foto otomatis dari koreksi rekap sesi (%s)',
            $report->report_number
        );

        return filled($reasonContext) ? $base . ' - ' . trim($reasonContext) : $base;
    }

    private function buildReversalDescription(SessionReport $report, ?string $reason = null): string
    {
        $base = sprintf(
            'Pembalikan stok kertas foto otomatis dari rekap sesi (%s)',
            $report->report_number
        );

        return filled($reason) ? $base . ' - ' . trim($reason) : $base;
    }

    private function bucketKey(int $itemId, string $canonicalCabangId): string
    {
        return $itemId . ':' . $canonicalCabangId;
    }

    private function normalizeLabel(?string $value): string
    {
        if (blank($value)) {
            return '';
        }

        return (string) preg_replace('/\s+/', ' ', strtolower(trim($value)));
    }

    private function hasReferenceColumns(): bool
    {
        return Schema::hasTable('inventory_transactions')
            && Schema::hasColumn('inventory_transactions', 'session_report_id')
            && Schema::hasColumn('inventory_transactions', 'reference_number');
    }

    private function generateTransactionNumber(): string
    {
        do {
            $number = 'INV-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (InventoryTransaction::query()->where('nomor_transaksi', $number)->exists());

        return $number;
    }
}
