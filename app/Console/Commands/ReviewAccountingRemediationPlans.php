<?php

namespace App\Console\Commands;

use App\Models\AccountingRemediationPlan;
use App\Services\AccountingRemediationService;
use App\Utils\RupiahHelper;
use Illuminate\Console\Command;

class ReviewAccountingRemediationPlans extends Command
{
    protected $signature = 'akuntansi:remediation:review
                            {--status= : Filter status rencana}
                            {--limit=50 : Maksimal baris ditampilkan}
                            {--json : Keluarkan laporan JSON}';

    protected $description = 'Tinjau rencana remediasi dan validasi kelayakannya tanpa mengubah data';

    public function handle(AccountingRemediationService $service): int
    {
        $query = AccountingRemediationPlan::query()
            ->with([
                'financialTransaction',
                'originalCategory',
                'proposedCategory',
                'originalAccount',
                'proposedAccount',
                'approver',
            ])
            ->orderBy('status')
            ->orderBy('financial_transaction_id');

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $plans = $query->get();
        $limit = max(1, (int) $this->option('limit'));
        $rows = [];
        $counts = [];
        $ready = 0;
        $canApprove = 0;

        foreach ($plans as $plan) {
            $counts[$plan->status] = ($counts[$plan->status] ?? 0) + 1;
            $validation = $service->validate($plan);
            $canApproveNow = $plan->status === AccountingRemediationPlan::STATUS_PENDING_APPROVAL
                && $validation['can_approve'];

            if ($canApproveNow) {
                $canApprove++;
            }

            if ($validation['ready_to_apply']) {
                $ready++;
            }

            if (count($rows) < $limit) {
                $rows[] = [
                    (string) $plan->getKey(),
                    (string) $plan->financial_transaction_id,
                    $plan->financialTransaction?->transaction_date?->toDateString() ?? '-',
                    RupiahHelper::format($plan->financialTransaction?->amount ?? 0),
                    $plan->originalCategory?->name ?? '-',
                    $plan->proposedCategory?->name ?? ($plan->proposed_category_name ?? '-'),
                    $plan->proposed_coa_code ?? '-',
                    $plan->originalAccount?->name ?? '-',
                    $plan->proposedAccount?->name ?? '-',
                    $plan->status,
                    $canApproveNow ? 'YES' : 'NO',
                    $validation['ready_to_apply'] ? 'YES' : 'NO',
                    implode(' ', array_slice($validation['errors'], 0, 2)) ?: '-',
                ];
            }
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'counts' => $counts,
                'can_approve' => $canApprove,
                'ready_to_apply' => $ready,
                'plans' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('=== REVIEW RENCANA REMEDIASI (READ-ONLY) ===');
        $this->table(['Status', 'Jumlah'], collect($counts)->map(fn ($count, $status) => [$status, $count])->values()->all());
        $this->line('Dapat disetujui: '.$canApprove.' | Siap apply: '.$ready);
        $this->newLine();

        $this->table([
            'Plan',
            'Tx',
            'Tanggal',
            'Nominal',
            'Kategori Asal',
            'Kategori Usulan',
            'COA',
            'Akun Asal',
            'Akun Usulan',
            'Status',
            'Approve?',
            'Apply?',
            'Catatan',
        ], $rows);

        if ($plans->count() > $limit) {
            $this->comment('Menampilkan '.$limit.' dari '.$plans->count().' rencana. Gunakan --limit untuk menampilkan lebih banyak.');
        }

        return self::SUCCESS;
    }
}
