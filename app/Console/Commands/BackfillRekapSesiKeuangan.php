<?php

namespace App\Console\Commands;

use App\Models\Cabang;
use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Models\LuminashImportRow;
use App\Models\SessionReport;
use App\Services\LuminashImportService;
use App\Services\SessionReportFinancialService;
use App\Utils\RupiahHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillRekapSesiKeuangan extends Command
{
    protected $signature = 'keuangan:backfill-rekap-sesi
                            {--cabang= : Batasi ke satu cabang (cabang_id atau nama cabang, contoh: C0006 atau "Newspaper Janus")}
                            {--dry-run : Tampilkan rencana perubahan tanpa menyimpan apa pun}
                            {--actor= : ID karyawan yang dicatat sebagai pembuat transaksi backfill (opsional)}';

    protected $description = 'Rekonsiliasi rekap sesi yang sudah approved ke laporan keuangan: satukan transaksi lama per metode pembayaran menjadi satu transaksi Grand Total, catat rekap yang belum tersinkron tanpa membuat transaksi ganda, dan samakan kategori Pendapatan Booth pada transaksi hasil integrasi yang lama';

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

        $cabang = $this->resolveCabang();

        if (blank($this->option('cabang')) === false && ! $cabang) {
            $this->error('Cabang "' . $this->option('cabang') . '" tidak ditemukan.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('MODE DRY-RUN: tidak ada perubahan yang disimpan.');
        }

        $this->line('Cabang: ' . ($cabang ? $cabang->nama_cabang . ' (' . $cabang->cabang_id . ')' : 'Semua cabang'));

        $categoryFixed = $this->syncIntegrationCategories($category, $dryRun);
        $stats = $this->recordApprovedReports($dryRun, $actor, $cabang);

        $this->newLine();
        $this->table(['Ringkasan', 'Jumlah'], [
            ['Transaksi integrasi ber-kategori lama disamakan', $categoryFixed],
            ['Transaksi lama per metode digabung' . ($dryRun ? ' (dry-run)' : ''), $stats['merged']],
            ['Rekap approved memenuhi syarat', $stats['eligible']],
            ['Rekap sudah tersinkron', $stats['alreadySynced']],
            ['Rekap akan disinkronkan' . ($dryRun ? ' (dry-run)' : ''), $stats['pending']],
            ['Transaksi keuangan dibuat', $stats['created']],
            ['Rekap gagal diproses', $stats['failed']],
            ['Rekap perlu ditinjau', $stats['needsReview']],
            ['Rekap dilewati (Grand Total tidak valid)', $stats['skippedNoAmount']],
            ['Rekap dilewati (sudah tercatat via impor Luminash)', $stats['skippedImport']],
        ]);

        if ($stats['issues'] !== []) {
            $this->newLine();
            $this->warn('Perlu ditinjau (tidak ada data yang diubah otomatis):');
            $this->table(['Rekap', 'Alasan'], $stats['issues']);
        }

        $this->info($dryRun ? 'Dry-run selesai. Jalankan tanpa --dry-run untuk menyimpan.' : 'Backfill selesai.');

        return self::SUCCESS;
    }

    private function resolveCabang(): ?Cabang
    {
        $value = $this->option('cabang');

        if (blank($value)) {
            return null;
        }

        $value = trim((string) $value);

        return Cabang::query()
            ->where('cabang_id', $value)
            ->orWhere('nama_cabang', 'like', '%' . $value . '%')
            ->orderBy('cabang_id')
            ->first();
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
     * @return array{eligible: int, alreadySynced: int, pending: int, created: int, merged: int, failed: int, needsReview: int, skippedNoAmount: int, skippedImport: int, issues: array<int, array<int, string>>}
     */
    private function recordApprovedReports(bool $dryRun, ?Karyawan $actor, ?Cabang $cabang): array
    {
        $importedReportIds = LuminashImportRow::query()
            ->whereNotNull('session_report_id')
            ->pluck('session_report_id')
            ->all();

        $service = app(SessionReportFinancialService::class);

        $stats = [
            'eligible' => 0,
            'alreadySynced' => 0,
            'pending' => 0,
            'created' => 0,
            'merged' => 0,
            'failed' => 0,
            'needsReview' => 0,
            'skippedNoAmount' => 0,
            'skippedImport' => 0,
            'issues' => [],
        ];

        $query = SessionReport::query()
            ->where('status', SessionReport::STATUS_APPROVED)
            ->with('cabang');

        if ($cabang) {
            $query->where('cabang_id', $cabang->cabang_id);
        }

        $query->chunkById(100, function ($reports) use (&$stats, $service, $importedReportIds, $dryRun, $actor): void {
            foreach ($reports as $report) {
                if (in_array($report->id, $importedReportIds, true)) {
                    $stats['skippedImport']++;

                    continue;
                }

                if ((int) $report->grand_total_amount <= 0) {
                    $stats['skippedNoAmount']++;

                    continue;
                }

                $stats['eligible']++;

                // Rekap dengan pencatatan gabungan (Newspaper Janus) hanya boleh menghasilkan
                // satu transaksi. Transaksi lama yang telanjur terpisah per metode digabungkan
                // lebih dulu supaya pendapatan tidak terhitung dua kali.
                $consolidation = $service->consolidationPlan($report);

                if ($consolidation['status'] === SessionReportFinancialService::CONSOLIDATION_REVIEW) {
                    $stats['needsReview']++;
                    $stats['issues'][] = [$report->report_number, 'Rekonsiliasi: ' . $consolidation['reason']];

                    continue;
                }

                if ($consolidation['status'] === SessionReportFinancialService::CONSOLIDATION_READY) {
                    $stats['merged']++;

                    if ($dryRun) {
                        $this->line('  [akan digabung] ' . $report->report_number . ' · ' . $consolidation['transactions']->count() . ' transaksi lama → 1 transaksi ' . RupiahHelper::format($consolidation['amount']));

                        continue;
                    }

                    try {
                        $service->consolidateLegacyTransactions($report, $consolidation);
                        $this->line('  [digabung] ' . $report->report_number . ' · 1 transaksi ' . RupiahHelper::format($consolidation['amount']));
                    } catch (Throwable $exception) {
                        $stats['failed']++;
                        $stats['issues'][] = [$report->report_number, 'Gagal menggabungkan: ' . $exception->getMessage()];
                        $this->error('  [gagal] ' . $report->report_number . ' · ' . $exception->getMessage());

                        continue;
                    }
                }

                $expected = $service->expectedSources($report);

                if ($expected === []) {
                    $stats['needsReview']++;
                    $stats['issues'][] = [$report->report_number, 'Grand Total ' . $report->grand_total_amount . ' tidak dapat dipetakan ke nominal Tunai/QRIS'];

                    continue;
                }

                $existing = FinancialTransaction::withTrashed()
                    ->where('source_type', SessionReportFinancialService::SOURCE)
                    ->whereIn('source_id', array_keys($expected))
                    ->get()
                    ->groupBy('source_id');

                $reasons = [];

                foreach ($existing as $sourceId => $rows) {
                    if ($rows->count() > 1) {
                        $reasons[] = 'Terdapat ' . $rows->count() . ' transaksi untuk referensi ' . $sourceId;
                    }

                    if ($rows->contains(fn (FinancialTransaction $row): bool => $row->trashed())) {
                        $reasons[] = 'Transaksi referensi ' . $sourceId . ' berstatus terhapus (soft delete)';
                    }
                }

                // Transaksi yang sudah di-soft delete bukan duplikat aktif: baris tersebut
                // memang sengaja ditinggalkan oleh proses penggabungan agar jejaknya tetap ada.
                $suspects = FinancialTransaction::withTrashed()
                    ->where('source_type', SessionReportFinancialService::SOURCE)
                    ->whereNull('deleted_at')
                    ->where('description', 'like', '%' . $report->report_number . '%')
                    ->whereNotIn('source_id', array_keys($expected))
                    ->pluck('source_id');

                if ($suspects->isNotEmpty()) {
                    $reasons[] = 'Ada transaksi dengan nomor rekap sama tetapi referensi berbeda: ' . $suspects->implode(', ');
                }

                $missing = array_values(array_diff(array_keys($expected), $existing->keys()->all()));

                if ($missing === [] && $reasons === []) {
                    $stats['alreadySynced']++;

                    continue;
                }

                // Selama masih ada indikasi duplikat atau transaksi yang dihapus manual,
                // rekap tidak dibuatkan transaksi baru dan hanya dilaporkan untuk ditinjau.
                if ($reasons !== []) {
                    $stats['needsReview']++;
                    $stats['issues'][] = [$report->report_number, implode('; ', $reasons)];

                    continue;
                }

                if ($dryRun) {
                    $stats['pending']++;
                    $this->line('  [akan dibuat] ' . $report->report_number . ' · ' . ($report->cabang?->nama_cabang ?? 'Tanpa Cabang') . ' · ' . implode(', ', $missing));

                    continue;
                }

                $stats['pending']++;

                try {
                    $created = DB::transaction(fn (): int => $service->record($report, $actor));

                    $stats['created'] += $created;
                    $this->line('  [dibuat] ' . $report->report_number . ' · ' . $created . ' transaksi');
                } catch (Throwable $exception) {
                    $stats['failed']++;
                    $stats['issues'][] = [$report->report_number, 'Gagal: ' . $exception->getMessage()];
                    $this->error('  [gagal] ' . $report->report_number . ' · ' . $exception->getMessage());
                }
            }
        });

        return $stats;
    }
}
