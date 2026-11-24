<?php

namespace App\Services;

use App\Models\BoothSessionHpp;
use App\Models\Cabang;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialParentCategory;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Utils\RupiahHelper;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BoothHppAccountingService
{
    public const SOURCE_TYPE = 'booth_session_hpp';

    /**
     * Resolusi kategori pengeluaran untuk HPP Booth (Janus / Golio).
     */
    public function resolveHppCategory(string $boothType): FinancialCategory
    {
        $categoryName = "HPP {$boothType}";

        $category = FinancialCategory::query()
            ->where('name', $categoryName)
            ->where('transaction_type', FinancialTransaction::TYPE_EXPENSE)
            ->first();

        if ($category) {
            return $category;
        }

        // Cari atau buat parent category "Persediaan"
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
            'name' => $categoryName,
            'group_name' => 'Operasional',
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'parent_category_id' => $parent->id,
            'is_active' => true,
        ]);
    }

    /**
     * Resolusi akun keuangan untuk booth.
     */
    public function resolveBoothAccountId(string $boothType): ?int
    {
        $account = FinancialAccount::query()
            ->where('name', 'like', "%{$boothType}%")
            ->where('is_active', true)
            ->first();

        return $account?->id ?? FinancialAccount::query()->where('is_active', true)->value('id');
    }

    /**
     * Posting HPP booth yang sudah dikonfirmasi ke modul Akuntansi (FinancialTransaction).
     * Bersifat idempoten: jika transaksi untuk source_id ini sudah ada, lakukan update amount.
     */
    public function postHpp(BoothSessionHpp $record, ?Karyawan $actor = null): FinancialTransaction
    {
        return DB::transaction(function () use ($record, $actor) {
            $lockedRecord = BoothSessionHpp::where('id', $record->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedRecord->status, [BoothSessionHpp::STATUS_CONFIRMED, BoothSessionHpp::STATUS_POSTED], true)) {
                throw new RuntimeException("Hanya data HPP dengan status Confirmed yang dapat diposting ke Akuntansi.");
            }

            if ((float) $lockedRecord->total_hpp <= 0 && $lockedRecord->hpp_rate_per_sheet > 0) {
                throw new RuntimeException("Total nominal HPP harus lebih dari Rp 0 untuk dapat diposting.");
            }

            $category = $this->resolveHppCategory($lockedRecord->booth_type);
            $accountId = $this->resolveBoothAccountId($lockedRecord->booth_type);
            $cabangId = $lockedRecord->cabang_id;

            $recapNumber = $lockedRecord->sessionReport?->report_number ?? "SR-{$lockedRecord->session_report_id}";
            $rateFormatted = RupiahHelper::format($lockedRecord->hpp_rate_per_sheet ?? 0);
            $description = "HPP {$lockedRecord->booth_type} ({$lockedRecord->total_sheets} lbr @ {$rateFormatted}) - Rekap {$recapNumber}";

            // Idempotency: periksa apakah transaksi sudah pernah dibuat
            $transaction = FinancialTransaction::where('source_type', self::SOURCE_TYPE)
                ->where('source_id', $lockedRecord->id)
                ->first();

            if ($transaction) {
                $transaction->update([
                    'amount' => (int) $lockedRecord->total_hpp,
                    'transaction_date' => $lockedRecord->report_date->toDateString(),
                    'description' => $description,
                    'category_id' => $category->id,
                    'account_id' => $accountId,
                    'cabang_id' => $cabangId,
                ]);
            } else {
                $transaction = FinancialTransaction::create([
                    'transaction_date' => $lockedRecord->report_date->toDateString(),
                    'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
                    'category_id' => $category->id,
                    'account_id' => $accountId,
                    'cabang_id' => $cabangId,
                    'amount' => (int) $lockedRecord->total_hpp,
                    'description' => $description,
                    'source_type' => self::SOURCE_TYPE,
                    'source_id' => $lockedRecord->id,
                    'submitted_by' => $actor?->karyawan_id,
                ]);
            }

            $lockedRecord->status = BoothSessionHpp::STATUS_POSTED;
            $lockedRecord->financial_transaction_id = $transaction->id;
            $lockedRecord->save();

            // Catat audit trail
            app(BoothHppService::class)->logAudit(
                $lockedRecord,
                'posted',
                $actor,
                "Diposting ke Akuntansi Transaksi #{$transaction->id} (" . RupiahHelper::format($lockedRecord->total_hpp) . ")"
            );

            return $transaction;
        });
    }

    /**
     * Balikkan (reverse) posting akuntansi saat data HPP dibuka kembali.
     */
    public function reverseHpp(
        BoothSessionHpp $record,
        ?Karyawan $actor = null,
        ?string $reason = null
    ): void {
        DB::transaction(function () use ($record, $actor, $reason) {
            $existing = FinancialTransaction::where('source_type', self::SOURCE_TYPE)
                ->where('source_id', $record->id)
                ->first();

            if ($existing) {
                $existing->delete();
            }

            $record->financial_transaction_id = null;
            $record->save();

            app(BoothHppService::class)->logAudit(
                $record,
                'reversed',
                $actor,
                "Jurnal transaksi HPP dibatalkan karena dibuka kembali: {$reason}"
            );
        });
    }
}
