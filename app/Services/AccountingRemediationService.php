<?php

namespace App\Services;

use App\Models\AccountingRemediationPlan;
use App\Models\Cabang;
use App\Models\CategoryAccountMapping;
use App\Models\ChartOfAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\Karyawan;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountingRemediationService
{
    public function __construct(
        protected TransactionAccountingService $accountingService
    ) {}

    /**
     * Membuat/menyegarkan rencana remediasi untuk seluruh transaksi aktif yang belum dapat diposting.
     *
     * @return array<string, int>
     */
    public function syncUnpostedTransactions(?Karyawan $actor = null, bool $dryRun = false): array
    {
        $stats = [
            'scanned' => 0,
            'eligible' => 0,
            'created' => 0,
            'updated' => 0,
            'pending_approval' => 0,
            'need_confirmation' => 0,
            'skipped' => 0,
        ];

        FinancialTransaction::query()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(100, function ($transactions) use (&$stats, $actor, $dryRun): void {
                foreach ($transactions as $transaction) {
                    $stats['scanned']++;

                    $plan = $this->accountingService->planFinancialTransaction($transaction);

                    if ($plan['error'] === null) {
                        $stats['skipped']++;

                        continue;
                    }

                    $stats['eligible']++;
                    $proposal = $this->proposalFor($transaction, $plan['error']);

                    if ($proposal['status'] === AccountingRemediationPlan::STATUS_PENDING_APPROVAL) {
                        $stats['pending_approval']++;
                    } else {
                        $stats['need_confirmation']++;
                    }

                    if ($dryRun) {
                        continue;
                    }

                    $existing = AccountingRemediationPlan::query()
                        ->where('financial_transaction_id', $transaction->getKey())
                        ->orderByDesc('version')
                        ->first();

                    if ($existing && $existing->isApplied()) {
                        $existing = null;
                    }

                    $reason = $this->buildReason($transaction, $proposal);

                    if ($existing) {
                        $proposalFields = $proposal;
                        unset($proposalFields['status']);

                        $before = $existing->only([
                            'proposed_category_id',
                            'proposed_account_id',
                            'proposed_coa_code',
                            'requires_mapping_change',
                        ]);

                        $existing->fill($proposalFields + [
                            'financial_transaction_id' => $transaction->getKey(),
                            'original_category_id' => $transaction->category_id,
                            'original_account_id' => $transaction->account_id,
                            'reason' => $reason,
                            'created_by' => $existing->created_by ?: $actor?->karyawan_id,
                        ]);

                        $after = $existing->only(array_keys($before));
                        $statusChanged = in_array($existing->status, [
                            AccountingRemediationPlan::STATUS_PENDING_APPROVAL,
                            AccountingRemediationPlan::STATUS_NEED_CONFIRMATION,
                        ], true) && $existing->status !== $proposal['status'];

                        if ($before != $after || $statusChanged) {
                            $existing->status = $proposal['status'];
                            $existing->approved_by = null;
                            $existing->approved_at = null;
                            $existing->approval_note = null;
                            $existing->approved_plan_snapshot = null;
                            $existing->approved_transaction_snapshot = null;
                            $existing->applied_by = null;
                            $existing->applied_at = null;
                            $existing->apply_result = null;
                        }

                        $existing->save();
                        $stats['updated']++;

                        continue;
                    }

                    $nextVersion = AccountingRemediationPlan::query()
                        ->where('financial_transaction_id', $transaction->getKey())
                        ->max('version') + 1;

                    AccountingRemediationPlan::create($proposal + [
                        'financial_transaction_id' => $transaction->getKey(),
                        'version' => $nextVersion,
                        'original_category_id' => $transaction->category_id,
                        'original_account_id' => $transaction->account_id,
                        'reason' => $reason,
                        'created_by' => $actor?->karyawan_id,
                    ]);

                    $stats['created']++;
                }
            });

        return $stats;
    }

    /**
     * Validasi rencana tanpa mengubah data.
     *
     * @return array<string, mixed>
     */
    public function validate(AccountingRemediationPlan $plan): array
    {
        $transaction = $plan->financialTransaction;

        if (! $transaction || $transaction->trashed()) {
            return $this->validationResult($plan, ['Transaksi sumber tidak ditemukan atau sudah dihapus.'], [], []);
        }

        $approvalErrors = [];
        $applyErrors = [];
        $warnings = [];

        if ($plan->status === AccountingRemediationPlan::STATUS_APPROVED) {
            if (blank($plan->approved_plan_snapshot) || blank($plan->approved_transaction_snapshot)) {
                $applyErrors[] = 'Snapshot approval tidak tersedia; rencana harus disetujui ulang.';
            } else {
                if ($plan->approved_plan_snapshot != $this->planSnapshot($plan)) {
                    $applyErrors[] = 'Rencana atau mapping berubah setelah approval; perlu approval ulang.';
                }

                if ($plan->approved_transaction_snapshot != $this->transactionSnapshot($transaction)) {
                    $applyErrors[] = 'Transaksi berubah setelah approval; perlu approval ulang.';
                }
            }
        }

        $proposedCategory = $plan->proposedCategory;
        $proposedAccount = $plan->proposedAccount;
        $originalCategory = $plan->originalCategory;
        $originalAccount = $plan->originalAccount;

        $finalCategory = $proposedCategory ?: $originalCategory;
        $finalAccount = $proposedAccount ?: $originalAccount;

        if ($plan->requires_mapping_change && $plan->proposed_coa_code && ! $this->plannedCategoryMappingExists($plan)) {
            $warnings[] = 'Mapping kategori '.($finalCategory?->name ?? '-').' → COA '.$plan->proposed_coa_code.' belum dibuat. Apply akan diblokir sampai mapping disetujui.';
        }

        if (! $finalCategory) {
            $approvalErrors[] = 'Kategori final belum ditentukan.';
        } elseif (! $finalCategory->is_active) {
            $approvalErrors[] = 'Kategori final tidak aktif.';
        }

        if (! $finalAccount) {
            $approvalErrors[] = 'Account operasional final belum ditentukan.';
        } elseif (! $finalAccount->is_active) {
            $approvalErrors[] = 'Account operasional final tidak aktif.';
        } elseif (blank($finalAccount->chart_of_account_id)) {
            $applyErrors[] = 'Account operasional belum terhubung ke Chart of Accounts.';
        }

        $mapping = $finalCategory ? CategoryAccountMapping::resolveForCategory($finalCategory) : null;

        if (! $mapping) {
            $applyErrors[] = 'Mapping kategori final belum tersedia atau tidak aktif.';
        } else {
            $mappedCode = $this->mappingCode($mapping);

            if ($plan->proposed_coa_code && $mappedCode && $plan->proposed_coa_code !== $mappedCode) {
                $applyErrors[] = 'COA pada rencana ('.$plan->proposed_coa_code.') tidak sama dengan mapping kategori ('.$mappedCode.').';
            }
        }

        if ($plan->proposed_coa_code) {
            $coa = ChartOfAccount::query()->where('code', $plan->proposed_coa_code)->first();

            if (! $coa) {
                $approvalErrors[] = 'COA '.$plan->proposed_coa_code.' tidak ditemukan.';
            } elseif (! $coa->is_active) {
                $approvalErrors[] = 'COA '.$plan->proposed_coa_code.' tidak aktif.';
            }
        }

        $changes = $this->changes($plan);

        if ($changes === [] && ! $plan->requires_mapping_change) {
            $approvalErrors[] = 'Tidak ada perubahan kategori/account yang direncanakan.';
        }

        $predictedLines = [];
        $predictedDate = null;

        if ($approvalErrors === [] && $mapping) {
            $clone = clone $transaction;
            $clone->category_id = $finalCategory->getKey();
            $clone->account_id = $finalAccount->getKey();

            $prediction = $this->accountingService->planFinancialTransaction($clone);

            if ($prediction['error'] !== null) {
                $applyErrors[] = $prediction['error'];
            } else {
                $predictedLines = $prediction['lines'];
                $predictedDate = $prediction['date'];
            }
        }

        if ($transaction->isJournalPosted()) {
            $current = $this->currentJournal($transaction);

            if ($current) {
                $reversalDate = $this->accountingService->resolveReversalDate($current->journal_date);
                $period = FiscalPeriod::forDate($reversalDate);

                if ($period && $period->isClosed()) {
                    $applyErrors[] = 'Periode reversal '.$period->label.' sudah ditutup.';
                }

                $warnings[] = 'Transaksi sudah posted. Apply akan membuat reversal jurnal '.$current->journal_number.' lalu posting versi baru.';
            }
        }

        return $this->validationResult($plan, $approvalErrors, $applyErrors, $warnings, $predictedLines, $predictedDate);
    }

    public function approve(AccountingRemediationPlan $plan, Karyawan $actor, ?string $note = null): AccountingRemediationPlan
    {
        $this->assertApprover($actor);

        if ($plan->status !== AccountingRemediationPlan::STATUS_PENDING_APPROVAL) {
            throw new RuntimeException('Hanya rencana berstatus PENDING_APPROVAL yang dapat disetujui.');
        }

        $validation = $this->validate($plan);

        if (! $validation['can_approve']) {
            throw new RuntimeException('Rencana belum dapat disetujui: '.implode(' ', $validation['approval_errors']));
        }

        $plan->forceFill([
            'status' => AccountingRemediationPlan::STATUS_APPROVED,
            'approved_by' => $actor->karyawan_id,
            'approved_at' => now(),
            'approval_note' => $note,
            'approved_plan_snapshot' => $this->planSnapshot($plan),
            'approved_transaction_snapshot' => $this->transactionSnapshot($plan->financialTransaction),
        ])->save();

        return $plan->refresh();
    }

    public function reject(AccountingRemediationPlan $plan, Karyawan $actor, string $note): AccountingRemediationPlan
    {
        $this->assertApprover($actor);

        $plan->forceFill([
            'status' => AccountingRemediationPlan::STATUS_REJECTED,
            'approved_by' => $actor->karyawan_id,
            'approved_at' => now(),
            'approval_note' => $note,
            'approved_plan_snapshot' => null,
            'approved_transaction_snapshot' => null,
        ])->save();

        return $plan->refresh();
    }

    /**
     * Dry-run atau terapkan satu rencana yang sudah APPROVED.
     *
     * @return array<string, mixed>
     */
    public function apply(AccountingRemediationPlan $plan, ?Karyawan $actor = null, bool $dryRun = true): array
    {
        if (! $dryRun) {
            if (! $actor) {
                throw new RuntimeException('Actor wajib diisi untuk apply remediasi.');
            }

            $this->assertApprover($actor);
        }

        if ($plan->status !== AccountingRemediationPlan::STATUS_APPROVED) {
            throw new RuntimeException('Hanya rencana APPROVED yang dapat di-apply. Status saat ini: '.$plan->status.'.');
        }

        $validation = $this->validate($plan);

        if (! $validation['ready_to_apply']) {
            throw new RuntimeException('Rencana belum siap di-apply: '.implode(' ', $validation['apply_errors']));
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'plan_id' => $plan->getKey(),
                'financial_transaction_id' => $plan->financial_transaction_id,
                'changes' => $this->changes($plan),
                'predicted_date' => $validation['predicted_date'],
                'predicted_lines' => $validation['predicted_lines'],
                'reversal_required' => $validation['reversal_required'],
                'warnings' => $validation['warnings'],
            ];
        }

        return DB::transaction(function () use ($plan, $actor): array {
            $lockedPlan = AccountingRemediationPlan::query()
                ->whereKey($plan->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPlan->status !== AccountingRemediationPlan::STATUS_APPROVED) {
                throw new RuntimeException('Status rencana berubah dan bukan APPROVED.');
            }

            $transaction = FinancialTransaction::query()
                ->whereKey($lockedPlan->financial_transaction_id)
                ->lockForUpdate()
                ->firstOrFail();

            $validation = $this->validate($lockedPlan);

            if (! $validation['ready_to_apply']) {
                throw new RuntimeException('Rencana belum siap di-apply: '.implode(' ', $validation['apply_errors']));
            }

            $reversal = null;
            $originalJournal = $this->currentJournal($transaction);

            if ($transaction->isJournalPosted()) {
                $reversal = $this->accountingService->reverseFinancialTransaction(
                    $transaction,
                    "Remediasi rencana #{$lockedPlan->getKey()}",
                    $actor
                );
            }

            $changes = $this->changes($lockedPlan);

            if ($changes !== []) {
                $transaction->fill($changes)->save();
                $transaction->refresh();
            }

            // Perubahan mapping-only (mis. Konsumsi → 5100) tidak mengubah field
            // transaksi, sehingga sinkronisasi jurnal harus dipanggil eksplisit.
            $this->accountingService->syncFinancialTransaction($transaction, $actor, true);
            $transaction->refresh();

            $newJournal = $this->currentJournal($transaction);

            if (! $newJournal) {
                throw new RuntimeException('Jurnal baru tidak terbentuk setelah remediasi.');
            }

            $result = [
                'plan_id' => $lockedPlan->getKey(),
                'financial_transaction_id' => $transaction->getKey(),
                'changes' => $changes,
                'original_journal_id' => $originalJournal?->getKey(),
                'original_journal_number' => $originalJournal?->journal_number,
                'reversal_journal_id' => $reversal?->getKey(),
                'reversal_journal_number' => $reversal?->journal_number,
                'new_journal_id' => $newJournal->getKey(),
                'new_journal_number' => $newJournal->journal_number,
                'applied_at' => now()->toIso8601String(),
            ];

            $lockedPlan->forceFill([
                'status' => AccountingRemediationPlan::STATUS_APPLIED,
                'applied_by' => $actor->karyawan_id,
                'applied_at' => now(),
                'apply_result' => $result,
            ])->save();

            return $result;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalFor(FinancialTransaction $transaction, string $planError): array
    {
        $category = FinancialCategory::query()->find($transaction->category_id);
        $mapping = CategoryAccountMapping::resolveForCategory($category);
        $accountProposal = $this->proposeAccount($transaction);

        $proposedCategoryId = null;
        $proposedCoaCode = null;
        $requiresMappingChange = false;
        $treatment = 'need_confirmation';
        $status = AccountingRemediationPlan::STATUS_NEED_CONFIRMATION;

        if ($mapping) {
            $proposedCategoryId = $category?->getKey();
            $proposedCoaCode = $this->mappingCode($mapping);
            $treatment = $category?->transaction_type === FinancialCategory::TYPE_INCOME ? 'revenue' : 'expense';
            $status = $accountProposal['account_id']
                ? AccountingRemediationPlan::STATUS_PENDING_APPROVAL
                : AccountingRemediationPlan::STATUS_NEED_CONFIRMATION;

            if ($category && ! $category->is_active) {
                $status = AccountingRemediationPlan::STATUS_NEED_CONFIRMATION;
                $evidenceSuffix = ' Kategori asal nonaktif; perlu keputusan kategori pengganti sebelum apply.';
            }
        } elseif ($category?->name === 'Konsumsi') {
            $proposedCategoryId = $category->getKey();
            $proposedCoaCode = '5100';
            $requiresMappingChange = true;
            $treatment = 'expense';
            $status = $accountProposal['account_id']
                ? AccountingRemediationPlan::STATUS_PENDING_APPROVAL
                : AccountingRemediationPlan::STATUS_NEED_CONFIRMATION;
        }

        $evidence = $accountProposal['reason'];

        if (isset($evidenceSuffix)) {
            $evidence .= $evidenceSuffix;
        }

        if (blank($transaction->account_id) && filled($transaction->evidence_path)) {
            $evidence .= ' Evidence path: '.$transaction->evidence_path.' (file tidak tersedia di salinan lokal).';
        }

        $evidence .= ' Alasan posting: '.$planError;

        if (! $mapping) {
            $evidence .= ' Mapping kategori belum tersedia; kategori/COA perlu keputusan bisnis.';
        }

        return [
            'proposed_category_id' => $proposedCategoryId,
            'proposed_category_name' => null,
            'proposed_account_id' => $accountProposal['account_id'],
            'proposed_coa_code' => $proposedCoaCode,
            'accounting_treatment' => $treatment,
            'evidence' => $evidence,
            'requires_mapping_change' => $requiresMappingChange,
            'status' => $status,
        ];
    }

    /**
     * @return array{account_id: int|null, reason: string, confidence: string}
     */
    private function proposeAccount(FinancialTransaction $transaction): array
    {
        if (filled($transaction->account_id)) {
            return [
                'account_id' => (int) $transaction->account_id,
                'reason' => 'Account sudah terisi; tidak ada perubahan account.',
                'confidence' => 'existing',
            ];
        }

        $branchName = filled($transaction->cabang_id)
            ? Cabang::query()->whereKey($transaction->cabang_id)->value('nama_cabang')
            : null;

        $description = strtolower((string) $transaction->description);
        $accountName = null;
        $reason = null;

        if ($branchName === 'Newspaper Janus') {
            $accountName = 'Janus';
            $reason = 'Cabang Newspaper Janus dan pola historis 46 transaksi memakai akun Janus.';
        } elseif (in_array($branchName, ['Express Sewa', 'Express Self Run', 'Express Sharing Profit'], true)) {
            $accountName = 'Photomate Express';
            $reason = 'Cabang kelompok Express; pola historis Express Sewa/Sharing Profit memakai Photomate Express.';
        } elseif (str_starts_with($description, 'ekspansi')) {
            $accountName = 'Ekspansi';
            $reason = 'Deskripsi berprefix Ekspansi_.';
        } elseif (
            str_contains($description, 'edwin')
            || str_contains($description, 'gilis')
            || str_contains($description, 'agrifest')
        ) {
            $accountName = 'Photomate Express';
            $reason = 'Nama/event pada deskripsi memiliki padanan historis yang memakai Photomate Express.';
        }

        if (! $accountName) {
            return [
                'account_id' => null,
                'reason' => 'Tidak ada bukti account yang cukup; NEED BUSINESS CONFIRMATION.',
                'confidence' => 'need',
            ];
        }

        $accountId = FinancialAccount::query()->where('name', $accountName)->value('id');

        if (! $accountId) {
            return [
                'account_id' => null,
                'reason' => 'Kandidat account '.$accountName.' tidak ditemukan di master.',
                'confidence' => 'need',
            ];
        }

        return [
            'account_id' => (int) $accountId,
            'reason' => $reason,
            'confidence' => 'candidate',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function changes(AccountingRemediationPlan $plan): array
    {
        $changes = [];

        $finalCategoryId = $plan->proposed_category_id ?: $plan->original_category_id;
        $finalAccountId = $plan->proposed_account_id ?: $plan->original_account_id;

        if ($finalCategoryId && (int) $finalCategoryId !== (int) $plan->original_category_id) {
            $changes['category_id'] = (int) $finalCategoryId;
        }

        if ($finalAccountId && (int) $finalAccountId !== (int) $plan->original_account_id) {
            $changes['account_id'] = (int) $finalAccountId;
        }

        return $changes;
    }

    /**
     * Snapshot rencana + mapping yang disetujui. Mapping hanya di-snapshot
     * untuk rencana yang tidak membutuhkan pembuatan mapping baru.
     *
     * @return array<string, mixed>
     */
    private function planSnapshot(AccountingRemediationPlan $plan): array
    {
        $category = $plan->proposedCategory ?: $plan->originalCategory;
        $mapping = $category ? CategoryAccountMapping::resolveForCategory($category) : null;
        $includeMapping = ! $plan->requires_mapping_change && $mapping !== null;

        return [
            'proposed_category_id' => $plan->proposed_category_id,
            'proposed_category_name' => $plan->proposed_category_name,
            'proposed_account_id' => $plan->proposed_account_id,
            'proposed_coa_code' => $plan->proposed_coa_code,
            'requires_mapping_change' => (bool) $plan->requires_mapping_change,
            'accounting_treatment' => $plan->accounting_treatment,
            'mapping' => $includeMapping ? [
                'id' => $mapping->getKey(),
                'debit_account_id' => $mapping->debit_account_id,
                'credit_account_id' => $mapping->credit_account_id,
                'debit_coa_code' => $mapping->debitAccount?->code,
                'credit_coa_code' => $mapping->creditAccount?->code,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionSnapshot(FinancialTransaction $transaction): array
    {
        return [
            'amount' => (int) $transaction->amount,
            'transaction_date' => $transaction->transaction_date?->toDateString(),
            'transaction_type' => $transaction->transaction_type,
            'category_id' => (int) $transaction->category_id,
            'account_id' => $transaction->account_id ? (int) $transaction->account_id : null,
            'payment_status' => $transaction->payment_status,
        ];
    }

    private function plannedCategoryMappingExists(AccountingRemediationPlan $plan): bool
    {
        $category = $plan->proposedCategory ?: $plan->originalCategory;

        if (! $category) {
            return false;
        }

        return CategoryAccountMapping::resolveForCategory($category) !== null;
    }

    private function mappingCode(CategoryAccountMapping $mapping): ?string
    {
        return $mapping->debitAccount?->code
            ?? $mapping->creditAccount?->code;
    }

    private function currentJournal(FinancialTransaction $transaction): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_FINANCIAL_TRANSACTION)
            ->where('reference_id', (string) $transaction->getKey())
            ->where('status', JournalEntry::STATUS_POSTED)
            ->whereNull('reversal_of_id')
            ->whereDoesntHave('reversals', fn ($query) => $query->where('status', JournalEntry::STATUS_POSTED))
            ->orderByDesc('id')
            ->first();
    }

    private function assertApprover(Karyawan $actor): void
    {
        if (! $actor->isSuperAdmin() && ! $actor->can('update_category_account_mapping')) {
            throw new RuntimeException('Actor tidak memiliki izin untuk menyetujui/menerapkan remediasi.');
        }
    }

    /**
     * @param  array<int, string>  $approvalErrors
     * @param  array<int, string>  $applyErrors
     * @param  array<int, string>  $warnings
     * @param  array<int, array<string, mixed>>  $predictedLines
     * @return array<string, mixed>
     */
    private function validationResult(
        AccountingRemediationPlan $plan,
        array $approvalErrors,
        array $applyErrors,
        array $warnings,
        array $predictedLines = [],
        ?string $predictedDate = null
    ): array {
        $errors = array_values(array_unique(array_merge($approvalErrors, $applyErrors)));

        return [
            'plan_id' => $plan->getKey(),
            'financial_transaction_id' => $plan->financial_transaction_id,
            'can_approve' => $approvalErrors === [],
            'ready_to_apply' => $approvalErrors === [] && $applyErrors === [],
            'approval_errors' => $approvalErrors,
            'apply_errors' => $applyErrors,
            'errors' => $errors,
            'warnings' => $warnings,
            'predicted_lines' => $predictedLines,
            'predicted_date' => $predictedDate,
            'reversal_required' => (bool) $plan->financialTransaction?->isJournalPosted(),
        ];
    }

    private function buildReason(FinancialTransaction $transaction, array $proposal): string
    {
        $category = $transaction->category;

        return 'Transaksi #'.$transaction->getKey().' ('.$category?->name.'): '.$proposal['evidence'];
    }
}
