<?php

namespace App\Console\Commands;

use App\Models\AccountingRemediationPlan;
use App\Models\Karyawan;
use App\Services\AccountingRemediationService;
use Illuminate\Console\Command;

class ApplyAccountingRemediationPlan extends Command
{
    protected $signature = 'akuntansi:remediation:apply
                            {--plan=* : ID rencana yang diproses}
                            {--all-approved : Proses semua rencana APPROVED}
                            {--apply : Benar-benar menerapkan perubahan (default dry-run)}
                            {--actor= : Karyawan pelaksana apply}';

    protected $description = 'Dry-run atau terapkan rencana remediasi yang sudah APPROVED';

    public function handle(AccountingRemediationService $service): int
    {
        $dryRun = ! (bool) $this->option('apply');
        $actor = null;

        if (! $dryRun) {
            $actor = Karyawan::query()->where('karyawan_id', $this->option('actor'))->first();

            if (! $actor) {
                $this->error('Actor wajib diisi untuk apply sungguhan.');

                return self::FAILURE;
            }
        }

        $query = AccountingRemediationPlan::query()->with('financialTransaction');

        if ((bool) $this->option('all-approved')) {
            $plans = $query->where('status', AccountingRemediationPlan::STATUS_APPROVED)->orderBy('id')->get();
        } else {
            $ids = array_filter((array) $this->option('plan'));

            if ($ids === []) {
                $this->error('Gunakan --plan=ID atau --all-approved.');

                return self::FAILURE;
            }

            $plans = $query->whereIn('id', $ids)->orderBy('id')->get();
        }

        if ($plans->isEmpty()) {
            $this->warn('Tidak ada rencana APPROVED yang dipilih.');

            return self::SUCCESS;
        }

        $rows = [];
        $success = 0;
        $failed = 0;

        foreach ($plans as $plan) {
            try {
                $result = $service->apply($plan, $actor, $dryRun);
                $success++;
                $changes = $result['changes'] ?? [];
                $rows[] = [
                    (string) $plan->getKey(),
                    (string) $plan->financial_transaction_id,
                    'OK',
                    $dryRun ? 'DRY-RUN' : 'APPLIED',
                    $changes === [] ? 'mapping-only' : json_encode($changes),
                    $result['new_journal_number'] ?? '-',
                    $result['reversal_journal_number'] ?? '-',
                ];
            } catch (\Throwable $exception) {
                $failed++;
                $rows[] = [
                    (string) $plan->getKey(),
                    (string) $plan->financial_transaction_id,
                    'GAGAL',
                    $exception->getMessage(),
                    '-',
                    '-',
                    '-',
                ];
            }
        }

        $this->info($dryRun ? 'MODE DRY-RUN: tidak ada perubahan transaksi yang disimpan.' : 'MODE APPLY: perubahan transaksi disimpan.');
        $this->table([
            'Plan',
            'Tx',
            'Hasil',
            'Status/Keterangan',
            'Changes',
            'Jurnal Baru',
            'Jurnal Reversal',
        ], $rows);

        $this->line('Sukses: '.$success.' | Gagal: '.$failed);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
