<?php

namespace App\Console\Commands;

use App\Models\AccountingRemediationPlan;
use App\Models\Karyawan;
use App\Services\AccountingRemediationService;
use Illuminate\Console\Command;

class ApproveAccountingRemediationPlan extends Command
{
    protected $signature = 'akuntansi:remediation:approve
                            {plan : ID rencana remediasi}
                            {--actor= : Karyawan penyetuju}
                            {--note= : Catatan persetujuan}';

    protected $description = 'Setujui satu rencana remediasi yang sudah tervalidasi';

    public function handle(AccountingRemediationService $service): int
    {
        $plan = AccountingRemediationPlan::query()->find($this->argument('plan'));

        if (! $plan) {
            $this->error('Rencana remediasi tidak ditemukan.');

            return self::FAILURE;
        }

        $actor = Karyawan::query()->where('karyawan_id', $this->option('actor'))->first();

        if (! $actor) {
            $this->error('Actor penyetuju wajib diisi dan valid.');

            return self::FAILURE;
        }

        try {
            $service->approve($plan, $actor, $this->option('note'));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Rencana remediasi #'.$plan->getKey().' disetujui oleh '.$actor->nama_lengkap.'.');

        return self::SUCCESS;
    }
}
