<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Konsolidasi transaksi impor CSV Luminash cabang Golio (kategori Pendapatan Booth)
 * menjadi satu transaksi rekap per tanggal transaksi.
 *
 * Transaksi per-order tetap tersimpan (soft delete) sebagai komponen rekap, sehingga
 * laporan keuangan tetap menghitung tepat satu kali dan tetap konsisten.
 */
class LuminashRekapService
{
    public const REKAP_SOURCE_PREFIX = 'REKAP-';

    public function rekapSourceId(string $date): string
    {
        return self::REKAP_SOURCE_PREFIX . $date;
    }

    public function isRekap(FinancialTransaction $transaction): bool
    {
        return $transaction->source_type === LuminashImportService::SOURCE
            && str_starts_with((string) $transaction->source_id, self::REKAP_SOURCE_PREFIX);
    }

    /**
     * Bangun/segarkan baris rekap untuk setiap tanggal yang diberikan.
     *
     * @param  array<int, string>  $dates
     * @return array{created: int, updated: int, components: int, total: int, dates: array<int, string>}
     */
    public function consolidateDates(array $dates, ?Karyawan $actor = null): array
    {
        $dates = $this->normalizeDates($dates);

        $stats = [
            'created' => 0,
            'updated' => 0,
            'components' => 0,
            'total' => 0,
            'dates' => [],
        ];

        if ($dates === []) {
            return $stats;
        }

        return DB::transaction(function () use ($dates, $actor, $stats): array {
            $category = $this->pendapatanBoothCategory();
            $accountId = $this->golioAccount()->id;
            $cabangId = $this->golioCabangId();

            foreach ($dates as $date) {
                $components = $this->componentQuery($date, $cabangId, $category->id)->get();

                if ($components->isEmpty()) {
                    continue;
                }

                $rekap = $this->findRekap($date);

                if ($rekap && $rekap->trashed()) {
                    // Baris rekap dihapus manual: hormati keputusan pengguna.
                    continue;
                }

                $total = (int) $components->sum('amount');
                $count = $components->count();

                $attributes = [
                    'transaction_type' => FinancialTransaction::TYPE_INCOME,
                    'amount' => $total,
                    'category_id' => $category->id,
                    'account_id' => $accountId,
                    'cabang_id' => $cabangId,
                    'payment_method_id' => null,
                    'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                    'transaction_date' => $date,
                    'period_year' => (int) Carbon::parse($date)->year,
                    'period_month' => (int) Carbon::parse($date)->month,
                    'description' => $this->description($count),
                    'source_type' => LuminashImportService::SOURCE,
                    'source_id' => $this->rekapSourceId($date),
                ];

                if ($rekap) {
                    $rekap->fill($attributes)->save();
                    $stats['updated']++;
                } else {
                    FinancialTransaction::create($attributes + ['created_by' => $actor?->karyawan_id]);
                    $stats['created']++;
                }

                foreach ($components as $component) {
                    if ($component->trashed() || $component->is($rekap)) {
                        continue;
                    }

                    $component->delete();
                }

                $stats['components'] += $count;
                $stats['total'] += $total;
                $stats['dates'][] = $date;
            }

            return $stats;
        });
    }

    /**
     * Rencana konsolidasi tanpa menyimpan apa pun (untuk mode dry-run).
     *
     * @param  array<int, string>  $dates
     * @return array<int, array{date: string, count: int, total: int, action: string}>
     */
    public function plan(array $dates): array
    {
        $dates = $this->normalizeDates($dates);
        $category = $this->pendapatanBoothCategory();
        $cabangId = $this->golioCabangId();
        $rows = [];

        foreach ($dates as $date) {
            $components = $this->componentQuery($date, $cabangId, $category->id)->get();

            if ($components->isEmpty()) {
                continue;
            }

            $rows[] = [
                'date' => $date,
                'count' => $components->count(),
                'total' => (int) $components->sum('amount'),
                'action' => $this->findRekap($date) ? 'update' : 'create',
            ];
        }

        return $rows;
    }

    /**
     * Komponen penyusun sebuah rekap (termasuk yang sudah di-soft delete).
     *
     * @return Collection<int, FinancialTransaction>
     */
    public function constituents(FinancialTransaction $rekap): Collection
    {
        return $this->componentQuery(
            (string) $rekap->transaction_date?->toDateString(),
            $rekap->cabang_id,
            (int) $rekap->category_id,
        )
            ->orderBy('id')
            ->get();
    }

    /**
     * Tanggal transaksi yang masih memiliki komponen aktif (belum direkap).
     *
     * @return array<int, string>
     */
    public function pendingDates(): array
    {
        $cabangId = $this->golioCabangId();
        $categoryId = $this->pendapatanBoothCategory()->id;

        return FinancialTransaction::query()
            ->where('source_type', LuminashImportService::SOURCE)
            ->where('cabang_id', $cabangId)
            ->where('category_id', $categoryId)
            ->where('transaction_type', FinancialTransaction::TYPE_INCOME)
            ->where('payment_status', FinancialTransaction::PAYMENT_STATUS_LUNAS)
            ->where(function (Builder $query): void {
                $query->whereNull('source_id')
                    ->orWhere('source_id', 'not like', self::REKAP_SOURCE_PREFIX . '%');
            })
            ->orderBy('transaction_date')
            ->get(['transaction_date'])
            ->map(fn (FinancialTransaction $transaction): ?string => $transaction->transaction_date?->toDateString())
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function componentQuery(string $date, ?string $cabangId, int $categoryId): Builder
    {
        return FinancialTransaction::withTrashed()
            ->where('source_type', LuminashImportService::SOURCE)
            ->where('source_id', '!=', $this->rekapSourceId($date))
            ->where('transaction_type', FinancialTransaction::TYPE_INCOME)
            ->where('payment_status', FinancialTransaction::PAYMENT_STATUS_LUNAS)
            ->where('category_id', $categoryId)
            ->whereDate('transaction_date', $date)
            ->when(filled($cabangId), fn (Builder $query): Builder => $query->where('cabang_id', $cabangId));
    }

    private function findRekap(string $date): ?FinancialTransaction
    {
        return FinancialTransaction::withTrashed()
            ->where('source_type', LuminashImportService::SOURCE)
            ->where('source_id', $this->rekapSourceId($date))
            ->first();
    }

    private function description(int $count): string
    {
        return 'Rekap Pendapatan Booth Luminash (' . $count . ' transaksi)';
    }

    /**
     * @param  array<int, string|null>  $dates
     * @return array<int, string>
     */
    private function normalizeDates(array $dates): array
    {
        return collect($dates)
            ->filter(fn ($date): bool => filled($date))
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function pendapatanBoothCategory(): FinancialCategory
    {
        $category = FinancialCategory::query()
            ->where('name', SessionReportFinancialService::CATEGORY_NAME)
            ->where('transaction_type', FinancialTransaction::TYPE_INCOME)
            ->first();

        if (! $category) {
            throw new RuntimeException(
                'Kategori "' . SessionReportFinancialService::CATEGORY_NAME . '" belum tersedia di master kategori keuangan. '
                . 'Tambahkan kategori tersebut terlebih dahulu sebelum melakukan rekap.'
            );
        }

        return $category;
    }

    private function golioAccount(): FinancialAccount
    {
        $account = FinancialAccount::query()->where('name', LuminashImportService::ACCOUNT_NAME)->first();

        if (! $account) {
            throw new RuntimeException('Akun "' . LuminashImportService::ACCOUNT_NAME . '" tidak ditemukan pada master akun keuangan.');
        }

        return $account;
    }

    private function golioCabangId(): string
    {
        $cabangId = LuminashImportService::golioCabangId();

        if (! $cabangId) {
            throw new RuntimeException('Cabang "' . LuminashImportService::CABANG_NAME . '" belum tersedia.');
        }

        return $cabangId;
    }
}
