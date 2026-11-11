<?php

namespace App\Console\Commands;

use App\Models\AccountingRemediationPlan;
use App\Models\Karyawan;
use App\Services\AccountingRemediationService;
use Illuminate\Console\Command;

class RejectAccountingRemediationPlan extends Command
{
    protected $signature = 'akuntansi:remediation:reject
                            {plan : ID rencana remediasi}
                            {--actor= : Karyawan yang menolak}
                            {--note= : Alasan penolakan}';

    protected $description = 'Tolak satu rencana remediasi';

    public function handle(AccountingRemediationService $service): int
    {
        $plan = AccountingRemediationPlan::query()->find($this->argument('plan'));

        if (! $plan) {
            $this->error('Rencana remediasi tidak ditemukan.');

            return self::FAILURE;
        }

        $actor = Karyawan::query()->where('karyawan_id', $this->option('actor'))->first();

        if (! $actor) {
            $this->error('Actor wajib diisi dan valid.');

            return self::FAILURE;
        }

        $note = trim((string) $this->option('note'));

        if ($note === '') {
            $this->error('Alasan penolakan wajib diisi.');

            return self::FAILURE;
        }

        try {
            $service->reject($plan, $actor, $note);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Rencana remediasi #'.$plan->getKey().' ditolak.');

        return self::SUCCESS;
    }
}
