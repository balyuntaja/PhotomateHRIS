<?php

namespace App\Services;

use App\Models\Cabang;
use App\Models\ExpressEvent;
use App\Models\ExpressEventAuditLog;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialParentCategory;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Utils\InventoryBranches;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExpressEventAccountingService
{
    public const SOURCE_TYPE = 'express_event_hpp';
    public const HPP_CATEGORY_NAME = 'HPP Express';

    public function __construct(
        protected ExpressEventHppService $hppService
    ) {}

    /**
     * Resolusi kategori pengeluaran untuk HPP Express.
     */
    public function resolveHppCategory(): FinancialCategory
    {
        $category = FinancialCategory::query()
            ->where('name', self::HPP_CATEGORY_NAME)
            ->where('transaction_type', FinancialTransaction::TYPE_EXPENSE)
            ->first();

        if ($category) {
            return $category;
        }

        // Cari parent category "Persediaan"
        $parent = FinancialParentCategory::query()
            ->where('name', 'Persediaan')
            ->first();

        if (! $parent) {
            $parent = FinancialParentCategory::create([
                'name' => 'Persediaan',
                'is_active' => true,
            ]);
        }

        return FinancialCategory::create([
            'name' => self::HPP_CATEGORY_NAME,
            'group_name' => 'Operasional',
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'parent_category_id' => $parent->id,
            'is_active' => true,
        ]);
    }

    /**
     * Resolusi akun keuangan untuk Express.
     */
    public function resolveExpressAccountId(): ?int
    {
        return FinancialAccount::query()
            ->where('name', 'Photomate Express')
            ->value('id')
            ?? FinancialAccount::query()->where('is_active', true)->value('id');
    }

    /**
     * Resolusi ID cabang kanonis untuk Express.
     */
    public function resolveExpressCabangId(): ?string
    {
        return InventoryBranches::canonicalCabangId(InventoryBranches::EXPRESS)
            ?? Cabang::where('nama_cabang', 'like', '%express%')->value('cabang_id');
    }

    /**
     * Posting HPP event yang sudah dikonfirmasi ke modul Akuntansi (FinancialTransaction).
     * Mencegah duplikasi posting dengan idempotency berdasarkan source_id.
     *
     * @return array<int, FinancialTransaction>
     */
    public function postHpp(ExpressEvent $event, ?Karyawan $actor = null): array
    {
        return DB::transaction(function () use ($event, $actor) {
            $lockedEvent = ExpressEvent::where('id', $event->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedEvent->hpp_status, [ExpressEvent::HPP_CONFIRMED, ExpressEvent::HPP_POSTED], true)) {
                throw new RuntimeException('Hanya event dengan HPP terkonfirmasi yang dapat diposting ke Akuntansi.');
            }

            $preview = $this->hppService->calculatePreview($lockedEvent);
            $category = $this->resolveHppCategory();
            $accountId = $this->resolveExpressAccountId();
            $cabangId = $this->resolveExpressCabangId();

            $postedTransactions = [];

            // Jika ada pembagian periode (lintas bulan), posting per periode
            $breakdowns = $preview['period_breakdown'];

            if (empty($breakdowns)) {
                // Fallback single period jika belum ada recap breakdown
                $eventDate = $lockedEvent->start_date ?? now();
                $breakdowns = [
                    $eventDate->format('Y-m') => [
                        'year' => (int) $eventDate->year,
                        'month' => (int) $eventDate->month,
                        'label' => $eventDate->translatedFormat('F Y'),
                        'hpp' => $preview['total_hpp'],
                        'sheets' => $preview['total_sheets'],
                    ],
                ];
            }

            foreach ($breakdowns as $periodKey => $pData) {
                $periodHpp = (int) $pData['hpp'];
                $year = (int) $pData['year'];
                $month = (int) $pData['month'];
                $sourceId = "{$lockedEvent->id}-{$year}-{$month}";

                // Jika HPP adalah Rp 0, tetap dibuat entri dengan nominal 0 untuk audit jika memang ada lembar
                $transactionDate = Carbon::create($year, $month, 1)->endOfMonth();
                // Jika periode sama dengan start_date, gunakan start_date
                if ($lockedEvent->start_date && $lockedEvent->start_date->year === $year && $lockedEvent->start_date->month === $month) {
                    $transactionDate = $lockedEvent->start_date;
                }

                $trx = FinancialTransaction::withTrashed()
                    ->where('source_type', self::SOURCE_TYPE)
                    ->where('source_id', $sourceId)
                    ->first();

                $description = "HPP Event Express · {$lockedEvent->event_name} ({$pData['sheets']} lbr @ Rp " . number_format($lockedEvent->hpp_rate_per_sheet ?? 0, 0, ',', '.') . ") · {$pData['label']}";

                if ($trx) {
                    // Update existing
                    if ($trx->trashed()) {
                        $trx->restore();
                    }
                    $trx->update([
                        'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
                        'amount' => $periodHpp,
                        'category_id' => $category->id,
                        'parent_category_id' => $category->parent_category_id,
                        'account_id' => $accountId,
                        'cabang_id' => $cabangId,
                        'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                        'transaction_date' => $transactionDate,
                        'period_year' => $year,
                        'period_month' => $month,
                        'description' => $description,
                        'updated_by' => $actor?->karyawan_id ?? auth()->id(),
                    ]);
                } else {
                    // Create new
                    $trx = FinancialTransaction::create([
                        'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
                        'amount' => $periodHpp,
                        'category_id' => $category->id,
                        'parent_category_id' => $category->parent_category_id,
                        'account_id' => $accountId,
                        'cabang_id' => $cabangId,
                        'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                        'transaction_date' => $transactionDate,
                        'period_year' => $year,
                        'period_month' => $month,
                        'description' => $description,
                        'source_type' => self::SOURCE_TYPE,
                        'source_id' => $sourceId,
                        'created_by' => $actor?->karyawan_id ?? auth()->id(),
                    ]);
                }

                $postedTransactions[] = $trx;
            }

            // Update status event & latest snapshot
            $lockedEvent->update([
                'hpp_status' => ExpressEvent::HPP_POSTED,
            ]);

            $latestHpp = $lockedEvent->latestHpp;
            if ($latestHpp) {
                $latestHpp->update([
                    'calculation_status' => ExpressEvent::HPP_POSTED,
                    'posted_at' => now(),
                ]);
            }

            ExpressEventAuditLog::record(
                $lockedEvent,
                'ACCOUNTING_POSTED',
                null,
                [
                    'posted_transactions_count' => count($postedTransactions),
                    'total_hpp' => $preview['total_hpp'],
                ],
                'HPP Event berhasil diposting ke Jurnal Keuangan (' . count($postedTransactions) . ' entri)'
            );

            return $postedTransactions;
        });
    }

    /**
     * Membalik (reversal) seluruh entri jurnal HPP yang pernah diposting untuk event ini.
     *
     * @return int jumlah entri yang di-reverse
     */
    public function reverseHpp(ExpressEvent $event, string $reason, ?Karyawan $actor = null): int
    {
        return DB::transaction(function () use ($event, $reason, $actor) {
            $transactions = FinancialTransaction::query()
                ->where('source_type', self::SOURCE_TYPE)
                ->where(function ($q) use ($event) {
                    $q->where('source_id', (string) $event->id)
                        ->orWhere('source_id', 'like', "{$event->id}-%");
                })
                ->get();

            $count = 0;
            foreach ($transactions as $trx) {
                $trx->update([
                    'description' => "[REVERSED: {$reason}] " . $trx->description,
                    'updated_by' => $actor?->karyawan_id ?? auth()->id(),
                ]);
                $trx->delete(); // Soft delete with audit log
                $count++;
            }

            $latestHpp = $event->latestHpp;
            if ($latestHpp) {
                $latestHpp->update([
                    'calculation_status' => ExpressEvent::HPP_REVERSED,
                ]);
            }

            ExpressEventAuditLog::record(
                $event,
                'ACCOUNTING_REVERSED',
                ['reversed_transactions' => $count],
                null,
                "Jurnal HPP dibalik ({$count} transaksi): {$reason}"
            );

            return $count;
        });
    }
}
