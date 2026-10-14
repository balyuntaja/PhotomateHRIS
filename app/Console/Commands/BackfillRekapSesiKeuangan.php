<?php

namespace App\Console\Commands;

use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Models\LuminashImportRow;
use App\Models\SessionReport;
use App\Services\LuminashImportService;
use App\Services\SessionReportFinancialService;
use Illuminate\Console\Command;

class BackfillRekapSesiKeuangan extends Command
{
    protected $signature = 'keuangan:backfill-rekap-sesi
                            {--dry-run : Tampilkan rencana perubahan tanpa menyimpan apa pun}
                            {--actor= : ID karyawan yang dicatat sebagai pembuat transaksi backfill (opsional)}';

    protected $description = 'Catat transaksi keuangan untuk rekap sesi yang sudah approved dan samakan kategori Pendapatan Booth pada transaksi hasil integrasi yang lama';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $category = FinancialCategory::query()
            ->where('name', SessionReportFinancialService::CATEGORY_NAME)
            ->where('transaction_type', FinancialTransaction::TYPE_INCOME)
            ->first();

        if (! $category) {
            $this->error('Kategori "' . SessionReportFinancialService::CATEGORY_NAME . '" belum tersedia di master kategori keuangan. Jalankan migrasi terlebih dahulu.');

            return self::FAILURE;
        }

        $actor = null;

        if ($actorId = $this->option('actor')) {
            $actor = Karyawan::query()->where('karyawan_id', $actorId)->first();

            if (! $actor) {
                $this->error('Karyawan dengan ID "' . $actorId . '" tidak ditemukan.');

                return self::FAILURE;
            }
        }

        if ($dryRun) {
            $this->warn('MODE DRY-RUN: tidak ada perubahan yang disimpan.');
        }

        $categoryFixed = $this->syncIntegrationCategories($category, $dryRun);
        [$processed, $created, $skippedImport] = $this->recordApprovedReports($dryRun, $actor);

        $this->newLine();
        $this->table(['Ringkasan', 'Jumlah'], [
            ['Transaksi integrasi ber-kategori lama disamakan', $categoryFixed],
            ['Rekap approved diproses', $processed],
            ['Transaksi keuangan dibuat', $created],
            ['Rekap dilewati (sudah tercatat via impor Luminash)', $skippedImport],
        ]);

        $this->info($dryRun ? 'Dry-run selesai. Jalankan tanpa --dry-run untuk menyimpan.' : 'Backfill selesai.');

        return self::SUCCESS;
    }

    private function syncIntegrationCategories(FinancialCategory $category, bool $dryRun): int
    {
        $query = FinancialTransaction::withTrashed()
            ->whereIn('source_type', [SessionReportFinancialService::SOURCE, LuminashImportService::SOURCE])
            ->where('category_id', '!=', $category->id);

        $count = (clone $query)->count();

        if (! $dryRun && $count > 0) {
            $query->update([
                'category_id' => $category->id,
                'updated_at' => now(),
            ]);
        }

        return $count;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function recordApprovedReports(bool $dryRun, ?Karyawan $actor): array
    {
        $importedReportIds = LuminashImportRow::query()
            ->whereNotNull('session_report_id')
            ->pluck('session_report_id')
            ->all();

        $processed = 0;
        $created = 0;
        $skippedImport = 0;

        SessionReport::query()
            ->where('status', SessionReport::STATUS_APPROVED)
            ->with('cabang')
            ->chunkById(100, function ($reports) use (&$processed, &$created, &$skippedImport, $importedReportIds, $dryRun, $actor): void {
                foreach ($reports as $report) {
                    if (in_array($report->id, $importedReportIds, true)) {
                        $skippedImport++;

                        continue;
                    }

                    $processed++;

                    if ($dryRun) {
                        $this->line('  [dry-run] ' . $report->report_number . ' · ' . ($report->cabang?->nama_cabang ?? 'Tanpa Cabang') . ' · Tunai ' . $report->total_cash_amount . ' · QRIS ' . $report->total_qris_amount);

                        continue;
                    }

                    $created += app(SessionReportFinancialService::class)->record($report, $actor);
                }
            });

        return [$processed, $created, $skippedImport];
    }
}
