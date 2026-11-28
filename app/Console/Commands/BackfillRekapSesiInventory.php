<?php

namespace App\Console\Commands;

use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Services\SessionReportInventoryService;
use App\Utils\InventoryBranches;
use Illuminate\Console\Command;

class BackfillRekapSesiInventory extends Command
{
    protected $signature = 'inventory:sync-rekap-sesi
                            {--dry-run : Tampilkan daftar rekap sesi yang akan disinkronkan tanpa mengubah stok}
                            {--actor= : ID karyawan opsional untuk pencatatan pengguna}';

    protected $description = 'Sinkronkan pengurangan stok Kertas Foto otomatis dari Rekap Sesi Approved (Express & Newspaper Janus mulai 7 November 2026) tanpa duplikasi.';

    public function handle(SessionReportInventoryService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $actor = null;

        if ($actorId = $this->option('actor')) {
            $actor = Karyawan::withTrashed()->find($actorId);
            if (! $actor) {
                $this->error("Karyawan dengan ID '{$actorId}' tidak ditemukan.");

                return self::FAILURE;
            }
        }

        $paperItem = $service->resolvePhotoPaperItem();
        if (! $paperItem) {
            $this->error('Master barang aktif "Kertas Foto" belum ditemukan di tabel inventory_items.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('MODE DRY-RUN: Tidak ada transaksi atau stok yang diubah.');

            $groups = InventoryBranches::groups(true);
            $eligibleCabangIds = array_values(array_unique(array_merge(
                $groups[InventoryBranches::EXPRESS] ?? [],
                $groups[InventoryBranches::JANUS] ?? [],
            )));

            $reports = SessionReport::query()
                ->with(['cabang', 'transactions'])
                ->whereIn('status', [SessionReport::STATUS_APPROVED, strtolower(SessionReport::STATUS_APPROVED)])
                ->whereIn('cabang_id', $eligibleCabangIds)
                ->whereDate('report_date', '>=', SessionReportInventoryService::CUTOFF_DATE)
                ->orderBy('report_date')
                ->orderBy('approved_at')
                ->orderBy('id')
                ->get();

            $eligibleCount = 0;
            $alreadySyncedCount = 0;
            $pendingRows = [];

            foreach ($reports as $report) {
                if (! $service->isEligibleForAutoDeduction($report)) {
                    continue;
                }

                $eligibleCount++;
                $targetSheets = $report->totalSheetCount();
                $netDeducted = $service->getNetDeductedSheets($report);

                if ($netDeducted === $targetSheets) {
                    $alreadySyncedCount++;

                    continue;
                }

                $pendingRows[] = [
                    $report->report_number,
                    $report->report_date?->toDateString() ?? '-',
                    $report->cabang?->nama_cabang ?? '-',
                    $targetSheets,
                    $netDeducted,
                    $targetSheets - $netDeducted,
                ];
            }

            if ($pendingRows !== []) {
                $this->table(
                    ['No. Rekap', 'Tgl Sesi', 'Cabang', 'Total Lembar', 'Sudah Dipotong', 'Selisih Akan Dicatat'],
                    $pendingRows
                );
            }

            $this->table(['Ringkasan Dry-Run', 'Jumlah'], [
                ['Barang Kertas Foto', "{$paperItem->nama_barang} ({$paperItem->satuan})"],
                ['Rekap Approved >= 2026-11-07 memenuhi syarat', $eligibleCount],
                ['Sudah tersinkron', $alreadySyncedCount],
                ['Akan dibuatkan transaksi stok', count($pendingRows)],
            ]);

            return self::SUCCESS;
        }

        $result = $service->syncEligibleReports($actor);

        $this->table(['Ringkasan Sinkronisasi', 'Jumlah'], [
            ['Barang Kertas Foto', "{$paperItem->nama_barang} ({$paperItem->satuan})"],
            ['Rekap Approved >= 2026-11-07 diproses', $result['processed']],
            ['Sudah tersinkron sebelumnya', $result['already_synced']],
            ['Transaksi stok baru tercatat', $result['created']],
        ]);

        $this->info('Sinkronisasi stok Rekap Sesi selesai.');

        return self::SUCCESS;
    }
}
