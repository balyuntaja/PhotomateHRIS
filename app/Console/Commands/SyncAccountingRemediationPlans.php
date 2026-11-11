<?php

namespace App\Console\Commands;

use App\Models\Karyawan;
use App\Services\AccountingRemediationService;
use Illuminate\Console\Command;

class SyncAccountingRemediationPlans extends Command
{
    protected $signature = 'akuntansi:remediation:sync
                            {--dry-run : Hitung rencana tanpa menyimpan}
                            {--actor= : Karyawan yang dicatat sebagai pembuat rencana}';

    protected $description = 'Buat/segarkan rencana remediasi untuk transaksi historis yang belum dapat diposting';

    public function handle(AccountingRemediationService $service): int
    {
        $actor = null;

        if ($actorId = $this->option('actor')) {
            $actor = Karyawan::query()->where('karyawan_id', $actorId)->first();

            if (! $actor) {
                $this->error("Karyawan '{$actorId}' tidak ditemukan.");

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('MODE DRY-RUN: rencana remediasi tidak disimpan.');
        }

        $stats = $service->syncUnpostedTransactions($actor, $dryRun);

        $this->table(['Metrik', 'Jumlah'], [
            ['Transaksi aktif diperiksa', $stats['scanned']],
            ['Transaksi belum dapat diposting', $stats['eligible']],
            ['Rencana dibuat', $stats['created']],
            ['Rencana diperbarui', $stats['updated']],
            ['Rencana PENDING_APPROVAL', $stats['pending_approval']],
            ['Rencana NEED_BUSINESS_CONFIRMATION', $stats['need_confirmation']],
            ['Transaksi sudah postable/dilewati', $stats['skipped']],
        ]);

        return self::SUCCESS;
    }
}
