<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialPaymentMethod;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Utils\RupiahHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SessionReportFinancialService
{
    public const CATEGORY_NAME = 'Pendapatan Booth';

    public const SOURCE = 'session_report';

    public const METHOD_CASH = 'CASH';

    public const METHOD_QRIS = 'QRIS';

    /**
     * Tidak ada transaksi lama yang perlu digabungkan.
     */
    public const CONSOLIDATION_NOTHING = 'nothing';

    /**
     * Transaksi lama terverifikasi dan siap digabungkan menjadi satu transaksi Grand Total.
     */
    public const CONSOLIDATION_READY = 'ready';

    /**
     * Transaksi lama tidak dapat dipastikan relasinya; hanya dilaporkan untuk ditinjau.
     */
    public const CONSOLIDATION_REVIEW = 'review';

    /**
     * Catat transaksi keuangan dari rekap sesi yang sudah disetujui.
     * Kategori selalu Pendapatan Booth (ditentukan backend, bukan input pengguna).
     *
     * @return int jumlah transaksi yang dibuat
     */
    public function record(SessionReport $report, ?Karyawan $actor = null): int
    {
        $category = $this->pendapatanBoothCategory();
        $accountId = $this->resolveAccountId($report);
        $submitter = $report->submitter;
        $created = 0;

        if ($this->hasLegacySplitTransactions($report)) {
            throw new RuntimeException(
                'Rekap ' . $report->report_number . ' masih memiliki transaksi lama per metode pembayaran. '
                . 'Jalankan "php artisan keuangan:backfill-rekap-sesi" untuk menggabungkannya terlebih dahulu '
                . 'agar pendapatan tidak tercatat dua kali.'
            );
        }

        foreach ($this->plannedTransactions($report) as $plan) {
            $alreadyRecorded = FinancialTransaction::withTrashed()
                ->where('source_type', self::SOURCE)
                ->where('source_id', $plan['source_id'])
                ->exists();

            if ($alreadyRecorded) {
                continue;
            }

            FinancialTransaction::create([
                'transaction_type' => FinancialTransaction::TYPE_INCOME,
                'amount' => $plan['amount'],
                'category_id' => $category->id,
                'account_id' => $accountId,
                'cabang_id' => $report->cabang_id,
                'payment_method_id' => $plan['payment_method_id'],
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                'transaction_date' => $report->report_date,
                'period_year' => (int) $report->report_date?->year,
                'period_month' => (int) $report->report_date?->month,
                'description' => $plan['description'],
                'source_type' => self::SOURCE,
                'source_id' => $plan['source_id'],
                'created_by' => $actor?->karyawan_id,
                'submitted_by' => $report->submitted_by,
                'submitted_by_name' => $submitter?->nama_lengkap,
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * Rekap sesi Newspaper Janus dicatat sebagai SATU transaksi memakai Grand Total
     * (gabungan QRIS + tunai); cabang lain tetap dicatat per metode pembayaran.
     */
    private function usesConsolidatedAmount(SessionReport $report): bool
    {
        return $report->isNewspaperJanus();
    }

    /**
     * Rencana transaksi keuangan sebuah rekap sesi. Satu rekap sesi hanya menghasilkan
     * satu transaksi untuk cabang dengan pencatatan gabungan.
     *
     * @return array<int, array{source_id: string, amount: int, payment_method_id: int|null, description: string}>
     */
    private function plannedTransactions(SessionReport $report): array
    {
        if (! $this->usesConsolidatedAmount($report)) {
            $plans = [];

            foreach ($this->methodAmounts($report) as $method => $amount) {
                $plans[] = [
                    'source_id' => $this->sourceIdFor($report, $method),
                    'amount' => $amount,
                    'payment_method_id' => $this->paymentMethodId($method),
                    'description' => $this->description($report, $method),
                ];
            }

            return $plans;
        }

        $grandTotal = (int) $report->grand_total_amount;

        if ($grandTotal <= 0) {
            return [];
        }

        return [[
            'source_id' => $this->consolidatedSourceIdFor($report),
            'amount' => $grandTotal,
            'payment_method_id' => null,
            'description' => $this->consolidatedDescription($report),
        ]];
    }

    /**
     * Referensi sumber (source_id) yang seharusnya dimiliki sebuah rekap sesi beserta nominalnya.
     * Dipakai proses approval dan backfill agar keduanya selalu memakai format referensi yang sama.
     *
     * @return array<string, int>
     */
    public function expectedSources(SessionReport $report): array
    {
        $sources = [];

        foreach ($this->plannedTransactions($report) as $plan) {
            $sources[$plan['source_id']] = $plan['amount'];
        }

        return $sources;
    }

    /**
     * Referensi sumber gaya lama, yaitu satu transaksi per metode pembayaran.
     *
     * @return array<int, string>
     */
    private function legacySourceIds(SessionReport $report): array
    {
        return [
            $this->sourceIdFor($report, self::METHOD_CASH),
            $this->sourceIdFor($report, self::METHOD_QRIS),
        ];
    }

    /**
     * Periksa transaksi lama rekap sesi yang telanjur tercatat terpisah per metode pembayaran
     * dan tentukan apakah aman digabungkan menjadi satu transaksi Grand Total.
     *
     * Hanya transaksi yang benar-benar terhubung melalui referensi sumber rekap ini yang
     * dipertimbangkan; transaksi manual maupun transaksi cabang lain tidak pernah disentuh.
     *
     * @return array{status: string, reason: string|null, transactions: Collection<int, FinancialTransaction>, keep: FinancialTransaction|null, amount: int}
     */
    public function consolidationPlan(SessionReport $report): array
    {
        $plan = [
            'status' => self::CONSOLIDATION_NOTHING,
            'reason' => null,
            'transactions' => collect(),
            'keep' => null,
            'amount' => (int) $report->grand_total_amount,
        ];

        if (! $this->usesConsolidatedAmount($report)) {
            return $plan;
        }

        $existing = FinancialTransaction::withTrashed()
            ->where('source_type', self::SOURCE)
            ->where('source_id', $this->consolidatedSourceIdFor($report))
            ->first();

        if ($existing) {
            if (! $existing->trashed()) {
                return $plan;
            }

            $plan['status'] = self::CONSOLIDATION_REVIEW;
            $plan['reason'] = 'Transaksi gabungan sudah ada tetapi berstatus terhapus (soft delete)';

            return $plan;
        }

        $legacy = $this->legacySplitTransactions($report);

        if ($legacy->isEmpty()) {
            return $plan;
        }

        $reason = $this->consolidationBlocker($report, $legacy);

        $plan['status'] = $reason === null ? self::CONSOLIDATION_READY : self::CONSOLIDATION_REVIEW;
        $plan['reason'] = $reason;
        $plan['transactions'] = $legacy;
        $plan['keep'] = $reason === null ? $legacy->first() : null;

        return $plan;
    }

    /**
     * Gabungkan transaksi lama per metode pembayaran menjadi satu transaksi Grand Total.
     * Baris yang tidak dipertahankan di-soft delete sehingga tetap dapat ditelusuri (audit trail).
     *
     * @param  array{transactions: Collection<int, FinancialTransaction>, keep: FinancialTransaction|null}  $plan
     */
    public function consolidateLegacyTransactions(SessionReport $report, array $plan): void
    {
        $keep = $plan['keep'] ?? null;
        $target = $this->plannedTransactions($report)[0] ?? null;

        if (! $keep || ! $target) {
            return;
        }

        $category = $this->pendapatanBoothCategory();
        $accountId = $this->resolveAccountId($report);

        DB::transaction(function () use ($report, $plan, $keep, $target, $category, $accountId): void {
            $keep->fill([
                'amount' => $target['amount'],
                'category_id' => $category->id,
                'account_id' => $accountId,
                'payment_method_id' => $target['payment_method_id'],
                'transaction_date' => $report->report_date,
                'period_year' => (int) $report->report_date?->year,
                'period_month' => (int) $report->report_date?->month,
                'description' => $target['description'],
                'source_id' => $target['source_id'],
            ]);

            $keep->save();

            foreach ($plan['transactions'] as $transaction) {
                if ($transaction->getKey() === $keep->getKey()) {
                    continue;
                }

                $transaction->delete();
            }
        });
    }

    private function pendapatanBoothCategory(): FinancialCategory
    {
        $category = FinancialCategory::query()
            ->where('name', self::CATEGORY_NAME)
            ->where('transaction_type', FinancialTransaction::TYPE_INCOME)
            ->first();

        if (! $category) {
            throw new RuntimeException(
                'Kategori "' . self::CATEGORY_NAME . '" belum tersedia di master kategori keuangan. '
                . 'Tambahkan kategori tersebut terlebih dahulu sebelum menyetujui rekap sesi.'
            );
        }

        return $category;
    }

    /**
     * Transaksi lama sebuah rekap, termasuk yang sudah di-soft delete agar kondisinya
     * ikut dinilai sebelum ada perubahan data.
     *
     * @return Collection<int, FinancialTransaction>
     */
    private function legacySplitTransactions(SessionReport $report): Collection
    {
        return FinancialTransaction::withTrashed()
            ->where('source_type', self::SOURCE)
            ->whereIn('source_id', $this->legacySourceIds($report))
            ->orderBy('id')
            ->get();
    }

    /**
     * Masih ada transaksi lama aktif yang tidak boleh ditimpa transaksi gabungan baru.
     */
    private function hasLegacySplitTransactions(SessionReport $report): bool
    {
        if (! $this->usesConsolidatedAmount($report)) {
            return false;
        }

        return $this->legacySplitTransactions($report)
            ->contains(fn (FinancialTransaction $transaction): bool => ! $transaction->trashed());
    }

    /**
     * Alasan transaksi lama tidak boleh digabung otomatis; null berarti aman digabung.
     *
     * @param  Collection<int, FinancialTransaction>  $legacy
     */
    private function consolidationBlocker(SessionReport $report, Collection $legacy): ?string
    {
        $grandTotal = (int) $report->grand_total_amount;

        if ($grandTotal <= 0) {
            return 'Grand Total rekap tidak valid (' . $grandTotal . ')';
        }

        if ($legacy->contains(fn (FinancialTransaction $row): bool => $row->trashed())) {
            return 'Ada transaksi lama yang sudah berstatus terhapus (soft delete)';
        }

        if ($legacy->contains(fn (FinancialTransaction $row): bool => $row->transaction_type !== FinancialTransaction::TYPE_INCOME)) {
            return 'Ada transaksi lama yang bukan pemasukan';
        }

        $otherBranch = $legacy->first(fn (FinancialTransaction $row): bool => $row->cabang_id !== $report->cabang_id);

        if ($otherBranch) {
            return 'Transaksi lama terhubung ke cabang berbeda (' . ($otherBranch->cabang_id ?: 'tanpa cabang') . ')';
        }

        $legacyTotal = (int) $legacy->sum('amount');

        if ($legacyTotal !== $grandTotal) {
            return 'Total transaksi lama ' . RupiahHelper::format($legacyTotal)
                . ' tidak sama dengan Grand Total ' . RupiahHelper::format($grandTotal);
        }

        return null;
    }

    /**
     * Rincian pembayaran rekap sesi per metode (hanya nominal > 0).
     *
     * @return array<string, int>
     */
    private function methodAmounts(SessionReport $report): array
    {
        $amounts = [
            self::METHOD_CASH => (int) $report->total_cash_amount,
            self::METHOD_QRIS => (int) $report->total_qris_amount,
        ];

        return array_filter($amounts, fn (int $amount): bool => $amount > 0);
    }

    /**
     * Cocokkan akun keuangan dengan nama cabang rekap; null bila tidak ada akun yang cocok.
     */
    private function resolveAccountId(SessionReport $report): ?int
    {
        $branchName = $report->cabang?->nama_cabang;

        if (blank($branchName)) {
            return null;
        }

        $normalizedBranch = $this->normalize($branchName);

        return FinancialAccount::query()
            ->active()
            ->orderBy('sort_order')
            ->get()
            ->first(function (FinancialAccount $account) use ($normalizedBranch): bool {
                $normalizedAccount = $this->normalize($account->name);

                return $normalizedAccount !== '' && str_contains($normalizedBranch, $normalizedAccount);
            })
            ?->id;
    }

    private function paymentMethodId(string $method): ?int
    {
        $name = $method === self::METHOD_CASH ? 'Cash' : 'QRIS';

        return FinancialPaymentMethod::query()->where('name', $name)->value('id');
    }

    private function description(SessionReport $report, string $method): string
    {
        $methodLabel = $method === self::METHOD_CASH ? 'Tunai' : 'QRIS';

        return $this->consolidatedDescription($report) . ' · ' . $methodLabel;
    }

    /**
     * Satu rekap sesi = satu transaksi, sehingga keterangan cukup menyebut nomor rekap dan cabang.
     */
    private function consolidatedDescription(SessionReport $report): string
    {
        return 'Rekap Sesi ' . $report->report_number . ' · ' . ($report->cabang?->nama_cabang ?? '-');
    }

    /**
     * Referensi sumber transaksi gabungan: ID rekap sesi itu sendiri.
     */
    private function consolidatedSourceIdFor(SessionReport $report): string
    {
        return (string) $report->id;
    }

    private function sourceIdFor(SessionReport $report, string $method): string
    {
        return $report->id . '-' . $method;
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)) ?? '');
    }
}
