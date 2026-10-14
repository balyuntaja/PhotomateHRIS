<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialPaymentMethod;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Models\SessionReport;
use RuntimeException;

class SessionReportFinancialService
{
    public const CATEGORY_NAME = 'Pendapatan Booth';

    public const SOURCE = 'session_report';

    public const METHOD_CASH = 'CASH';

    public const METHOD_QRIS = 'QRIS';

    /**
     * Catat transaksi keuangan dari rekap sesi yang sudah disetujui.
     * Kategori selalu Pendapatan Booth (ditentukan backend, bukan input pengguna).
     *
     * @return int jumlah transaksi yang dibuat
     */
    public function record(SessionReport $report, ?Karyawan $actor = null): int
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

        $accountId = $this->resolveAccountId($report);
        $created = 0;

        foreach ($this->methodAmounts($report) as $method => $amount) {
            $sourceId = $this->sourceIdFor($report, $method);

            $alreadyRecorded = FinancialTransaction::withTrashed()
                ->where('source_type', self::SOURCE)
                ->where('source_id', $sourceId)
                ->exists();

            if ($alreadyRecorded) {
                continue;
            }

            FinancialTransaction::create([
                'transaction_type' => FinancialTransaction::TYPE_INCOME,
                'amount' => $amount,
                'category_id' => $category->id,
                'account_id' => $accountId,
                'cabang_id' => $report->cabang_id,
                'payment_method_id' => $this->paymentMethodId($method),
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                'transaction_date' => $report->report_date,
                'period_year' => (int) $report->report_date?->year,
                'period_month' => (int) $report->report_date?->month,
                'description' => $this->description($report, $method),
                'source_type' => self::SOURCE,
                'source_id' => $sourceId,
                'created_by' => $actor?->karyawan_id,
            ]);

            $created++;
        }

        return $created;
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

        foreach ($this->methodAmounts($report) as $method => $amount) {
            $sources[$this->sourceIdFor($report, $method)] = $amount;
        }

        return $sources;
    }

    private function sourceIdFor(SessionReport $report, string $method): string
    {
        return $report->id . '-' . $method;
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
        $branchName = $report->cabang?->nama_cabang ?? '-';

        return 'Rekap Sesi ' . $report->report_number . ' · ' . $branchName . ' · ' . $methodLabel;
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)) ?? '');
    }
}
