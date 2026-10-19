<?php

namespace App\Services;

use App\Models\FinancialTransaction;
use App\Models\SessionReport;
use Illuminate\Support\Collection;

/**
 * Identifikasi dan detail rekap Photomate Express yang dicatat sebagai satu
 * transaksi gabungan per rekap (QRIS + Tunai).
 *
 * Kategori transaksi tetap Pendapatan Booth; service ini tidak mengubah
 * kategori maupun master data.
 */
class ExpressRekapService
{
    public const BRANCH_KEYWORD = 'photomate express';

    public const ACCOUNT_NAME = 'Photomate Express';

    public function isExpressBranch(SessionReport $report): bool
    {
        $branchName = $this->normalize($report->cabang?->nama_cabang ?? '');

        return $branchName !== '' && str_contains($branchName, self::BRANCH_KEYWORD);
    }

    public function isConsolidated(FinancialTransaction $transaction): bool
    {
        return $transaction->source_type === SessionReportFinancialService::SOURCE
            && $transaction->payment_method_id === null
            && $transaction->category?->name === SessionReportFinancialService::CATEGORY_NAME
            && $transaction->account?->name === self::ACCOUNT_NAME
            && $this->isReportSourceId($transaction->source_id);
    }

    public function isRekap(FinancialTransaction $transaction): bool
    {
        return $this->isConsolidated($transaction);
    }

    /**
     * Rincian per metode untuk modal audit.
     *
     * @return array{report: SessionReport|null, rows: array<int, array<string, mixed>>, total: int}
     */
    public function detailRows(FinancialTransaction $transaction): array
    {
        $report = $this->reportFor($transaction);

        if ($report) {
            $rows = [];

            if ((int) $report->total_cash_amount > 0) {
                $rows[] = [
                    'method' => 'Tunai',
                    'transactions' => (int) $report->total_cash_transactions,
                    'sessions' => (int) $report->total_cash_sessions,
                    'amount' => (int) $report->total_cash_amount,
                    'description' => 'Rekap Sesi ' . $report->report_number . ' · Tunai',
                ];
            }

            if ((int) $report->total_qris_amount > 0) {
                $rows[] = [
                    'method' => 'QRIS',
                    'transactions' => (int) $report->total_qris_transactions,
                    'sessions' => (int) $report->total_qris_sessions,
                    'amount' => (int) $report->total_qris_amount,
                    'description' => 'Rekap Sesi ' . $report->report_number . ' · QRIS',
                ];
            }

            return [
                'report' => $report,
                'rows' => $rows,
                'total' => (int) $report->total_cash_amount + (int) $report->total_qris_amount,
            ];
        }

        $components = $this->componentsFor($transaction);

        return [
            'report' => null,
            'rows' => $components
                ->map(fn (FinancialTransaction $component): array => [
                    'method' => $component->paymentMethod?->name ?? '-',
                    'transactions' => null,
                    'sessions' => null,
                    'amount' => (int) $component->amount,
                    'description' => $component->description ?: '-',
                ])
                ->values()
                ->all(),
            'total' => (int) $components->sum('amount'),
        ];
    }

    public function reportFor(FinancialTransaction $transaction): ?SessionReport
    {
        if (! $this->isReportSourceId($transaction->source_id)) {
            return null;
        }

        return SessionReport::query()
            ->with('cabang')
            ->find((int) $transaction->source_id);
    }

    /**
     * Baris per metode yang tersimpan sebagai komponen (termasuk soft delete).
     *
     * @return Collection<int, FinancialTransaction>
     */
    public function componentsFor(FinancialTransaction $transaction): Collection
    {
        if (! $this->isReportSourceId($transaction->source_id)) {
            return collect();
        }

        return FinancialTransaction::withTrashed()
            ->where('source_type', SessionReportFinancialService::SOURCE)
            ->whereIn('source_id', [
                $transaction->source_id . '-' . SessionReportFinancialService::METHOD_CASH,
                $transaction->source_id . '-' . SessionReportFinancialService::METHOD_QRIS,
            ])
            ->orderBy('id')
            ->get();
    }

    private function isReportSourceId(?string $sourceId): bool
    {
        return filled($sourceId) && ctype_digit((string) $sourceId);
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)) ?? '');
    }
}
