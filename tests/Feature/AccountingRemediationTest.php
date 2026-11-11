<?php

namespace Tests\Feature;

use App\Models\AccountingRemediationPlan;
use App\Models\CategoryAccountMapping;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Models\Karyawan;
use App\Services\AccountingRemediationService;
use App\Services\TransactionAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\AccountingTestHelpers;
use Tests\TestCase;

class AccountingRemediationTest extends TestCase
{
    use AccountingTestHelpers;
    use RefreshDatabase;

    protected Karyawan $admin;

    protected Karyawan $crew;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccountingData();

        FinancialCategory::firstOrCreate(
            ['name' => 'Konsumsi', 'transaction_type' => FinancialCategory::TYPE_EXPENSE],
            ['is_active' => true]
        );

        $this->admin = $this->makeKaryawan('K0001', 'R01');
        $this->crew = $this->makeKaryawan('K0002', 'R07');

        $this->actingAs($this->admin);
    }

    public function test_sync_membuat_rencana_hanya_untuk_transaksi_belum_postable(): void
    {
        $accountMissing = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 200000,
            'category_id' => $this->category('Fee Crew', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => null,
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-10',
            'description' => 'Bayar Edwin (2 event)',
        ]);

        $mappingMissing = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 500000,
            'category_id' => $this->category('Peralatan', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-10',
            'description' => 'Peralatan uji',
        ]);

        $postable = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 50000,
            'category_id' => $this->category('Kertas', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => '2026-11-10',
            'description' => 'Kertas uji',
        ]);

        $this->assertNotNull($postable->fresh());
        $this->assertNull($accountMissing->fresh()->account_id);

        $stats = app(AccountingRemediationService::class)->syncUnpostedTransactions($this->admin);

        $this->assertSame(3, $stats['scanned']);
        $this->assertSame(2, $stats['eligible']);
        $this->assertSame(2, $stats['created']);
        $this->assertSame(1, $stats['pending_approval']);
        $this->assertSame(1, $stats['need_confirmation']);
        $this->assertSame(1, $stats['skipped']);

        $this->assertSame(2, AccountingRemediationPlan::query()->count());
        $this->assertFalse(AccountingRemediationPlan::query()
            ->where('financial_transaction_id', $postable->getKey())
            ->exists());

        $accountPlan = AccountingRemediationPlan::query()
            ->where('financial_transaction_id', $accountMissing->getKey())
            ->firstOrFail();
        $mappingPlan = AccountingRemediationPlan::query()
            ->where('financial_transaction_id', $mappingMissing->getKey())
            ->firstOrFail();

        $this->assertSame(AccountingRemediationPlan::STATUS_PENDING_APPROVAL, $accountPlan->status);
        $this->assertSame($this->account('Photomate Express')->getKey(), $accountPlan->proposed_account_id);
        $this->assertSame(AccountingRemediationPlan::STATUS_NEED_CONFIRMATION, $mappingPlan->status);
        $this->assertNull($mappingPlan->proposed_coa_code);

        $this->assertNull($accountMissing->fresh()->account_id);
        $this->assertSame(0, JournalEntry::query()
            ->where('reference_type', TransactionAccountingService::REF_FINANCIAL_TRANSACTION)
            ->where('reference_id', (string) $accountMissing->getKey())
            ->count());
    }

    public function test_transaksi_tanpa_account_tidak_bisa_diposting_tanpa_remediasi(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 100000,
            'category_id' => $this->category('Fee Crew', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => null,
            'transaction_date' => '2026-11-10',
        ]);

        $this->assertNull($transaction->fresh()->account_id);
        $this->assertNull($transaction->fresh()->postedJournal());
    }

    public function test_rencana_tanpa_persetujuan_tidak_bisa_di_apply(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 100000,
            'category_id' => $this->category('Fee Crew', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => null,
            'transaction_date' => '2026-11-10',
            'description' => 'Bayar Edwin',
        ]);

        app(AccountingRemediationService::class)->syncUnpostedTransactions($this->admin);

        $plan = AccountingRemediationPlan::query()
            ->where('financial_transaction_id', $transaction->getKey())
            ->firstOrFail();

        $this->assertSame(AccountingRemediationPlan::STATUS_PENDING_APPROVAL, $plan->status);

        try {
            app(AccountingRemediationService::class)->apply($plan, $this->admin, false);
            $this->fail('Plan tanpa approval seharusnya tidak dapat di-apply.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('APPROVED', $exception->getMessage());
        }

        $this->assertNull($transaction->fresh()->account_id);
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_rencana_approved_menerapkan_account_dan_mencatat_audit_trail(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 100000,
            'category_id' => $this->category('Fee Crew', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => null,
            'transaction_date' => '2026-11-10',
            'description' => 'Bayar Edwin',
        ]);

        app(AccountingRemediationService::class)->syncUnpostedTransactions($this->admin);

        $plan = AccountingRemediationPlan::query()
            ->where('financial_transaction_id', $transaction->getKey())
            ->firstOrFail();

        app(AccountingRemediationService::class)->approve($plan, $this->admin, 'Setuju uji');

        $result = app(AccountingRemediationService::class)->apply($plan->fresh(), $this->admin, false);

        $transaction->refresh();
        $plan->refresh();

        $this->assertSame($this->account('Photomate Express')->getKey(), $transaction->account_id);
        $this->assertNotNull($transaction->postedJournal());
        $this->assertSame(AccountingRemediationPlan::STATUS_APPLIED, $plan->status);
        $this->assertSame($this->admin->karyawan_id, $plan->applied_by);
        $this->assertNotNull($result['new_journal_id']);
        $this->assertSame($result['new_journal_id'], $plan->apply_result['new_journal_id']);

        $audit = $transaction->auditLogs()
            ->where('action', FinancialTransaction::ACTION_UPDATED)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame(
            $this->account('Photomate Express')->getKey(),
            (int) $audit->new_data['account_id']
        );
        $this->assertNull($audit->old_data['account_id'] ?? null);
    }

    public function test_kategori_campuran_tidak_mendapat_mapping_otomatis(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 500000,
            'category_id' => $this->category('Peralatan', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'transaction_date' => '2026-11-10',
        ]);

        app(AccountingRemediationService::class)->syncUnpostedTransactions($this->admin);

        $plan = AccountingRemediationPlan::query()
            ->where('financial_transaction_id', $transaction->getKey())
            ->firstOrFail();

        $this->assertSame(AccountingRemediationPlan::STATUS_NEED_CONFIRMATION, $plan->status);
        $this->assertNull($plan->proposed_coa_code);
        $this->assertFalse(CategoryAccountMapping::query()
            ->where('financial_category_id', $this->category('Peralatan', FinancialCategory::TYPE_EXPENSE)->getKey())
            ->exists());
    }

    public function test_mapping_belum_dibuat_memblokir_apply_walau_sudah_approved(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 100000,
            'category_id' => $this->category('Konsumsi', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'transaction_date' => '2026-11-10',
        ]);

        app(AccountingRemediationService::class)->syncUnpostedTransactions($this->admin);

        $plan = AccountingRemediationPlan::query()
            ->where('financial_transaction_id', $transaction->getKey())
            ->firstOrFail();

        $this->assertSame(AccountingRemediationPlan::STATUS_PENDING_APPROVAL, $plan->status);
        $this->assertTrue($plan->requires_mapping_change);

        app(AccountingRemediationService::class)->approve($plan, $this->admin, 'Setuju mapping Konsumsi');

        try {
            app(AccountingRemediationService::class)->apply($plan->fresh(), $this->admin, false);
            $this->fail('Apply seharusnya diblokir sampai mapping Konsumsi dibuat.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Mapping kategori final belum tersedia', $exception->getMessage());
        }

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame($this->account('Ekspansi')->getKey(), $transaction->fresh()->account_id);
    }

    public function test_perubahan_posted_membuat_reversal_lalu_posting_ulang(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 100000,
            'category_id' => $this->category('Kertas', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'transaction_date' => '2026-11-10',
        ]);

        $original = $transaction->fresh()->postedJournal();
        $this->assertNotNull($original);

        $plan = AccountingRemediationPlan::create([
            'financial_transaction_id' => $transaction->getKey(),
            'version' => 1,
            'original_category_id' => $transaction->category_id,
            'proposed_category_id' => $this->category('Tinta', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'original_account_id' => $transaction->account_id,
            'proposed_account_id' => $transaction->account_id,
            'proposed_coa_code' => '5010',
            'accounting_treatment' => 'expense',
            'reason' => 'Uji reversal kategori posted',
            'evidence' => 'Unit test',
            'status' => AccountingRemediationPlan::STATUS_PENDING_APPROVAL,
            'created_by' => $this->admin->karyawan_id,
        ]);

        app(AccountingRemediationService::class)->approve($plan, $this->admin, 'Setuju reversal');

        $result = app(AccountingRemediationService::class)->apply($plan->fresh(), $this->admin, false);

        $this->assertNotNull($result['reversal_journal_id']);
        $this->assertSame($original->getKey(), $result['original_journal_id']);
        $this->assertNotSame($result['original_journal_id'], $result['reversal_journal_id']);
        $this->assertNotSame($result['reversal_journal_id'], $result['new_journal_id']);

        $original->refresh();
        $this->assertSame(JournalEntry::STATUS_POSTED, $original->status);
        $this->assertSame(1, JournalEntry::query()->where('reversal_of_id', $original->getKey())->count());

        $newJournal = JournalEntry::query()->findOrFail($result['new_journal_id']);

        $this->assertSame('5010', $newJournal->lines()
            ->whereHas('chartOfAccount', fn ($query) => $query->where('code', '5010'))
            ->firstOrFail()
            ->chartOfAccount
            ->code);
        $this->assertSame($this->category('Tinta', FinancialCategory::TYPE_EXPENSE)->getKey(), $transaction->fresh()->category_id);
    }

    public function test_apply_ulang_tidak_membuat_jurnal_ganda(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 100000,
            'category_id' => $this->category('Fee Crew', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => null,
            'transaction_date' => '2026-11-10',
            'description' => 'Bayar Edwin',
        ]);

        app(AccountingRemediationService::class)->syncUnpostedTransactions($this->admin);

        $plan = AccountingRemediationPlan::query()
            ->where('financial_transaction_id', $transaction->getKey())
            ->firstOrFail();

        app(AccountingRemediationService::class)->approve($plan, $this->admin);
        app(AccountingRemediationService::class)->apply($plan->fresh(), $this->admin, false);

        $journalCount = JournalEntry::query()->count();

        try {
            app(AccountingRemediationService::class)->apply($plan->fresh(), $this->admin, false);
            $this->fail('Apply ulang seharusnya ditolak.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('APPROVED', $exception->getMessage());
        }

        $this->assertSame($journalCount, JournalEntry::query()->count());
    }

    public function test_backfill_setelah_remediasi_tetap_idempotent(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 100000,
            'category_id' => $this->category('Fee Crew', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => null,
            'transaction_date' => '2026-11-10',
            'description' => 'Bayar Edwin',
        ]);

        app(AccountingRemediationService::class)->syncUnpostedTransactions($this->admin);

        $plan = AccountingRemediationPlan::query()
            ->where('financial_transaction_id', $transaction->getKey())
            ->firstOrFail();

        app(AccountingRemediationService::class)->approve($plan, $this->admin);
        app(AccountingRemediationService::class)->apply($plan->fresh(), $this->admin, false);

        $journalCount = JournalEntry::query()->count();

        $this->artisan('akuntansi:backfill-transaksi')->assertSuccessful();

        $this->assertSame($journalCount, JournalEntry::query()->count());
    }

    public function test_crew_tidak_dapat_menyetujui_remediasi(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 100000,
            'category_id' => $this->category('Fee Crew', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => null,
            'transaction_date' => '2026-11-10',
            'description' => 'Bayar Edwin',
        ]);

        app(AccountingRemediationService::class)->syncUnpostedTransactions($this->admin);

        $plan = AccountingRemediationPlan::query()
            ->where('financial_transaction_id', $transaction->getKey())
            ->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak memiliki izin');

        app(AccountingRemediationService::class)->approve($plan, $this->crew);
    }

    public function test_plan_berubah_setelah_approval_memblokir_apply(): void
    {
        $plan = $this->makeApprovedReadyPlan();

        DB::table('accounting_remediation_plans')
            ->where('id', $plan->getKey())
            ->update(['proposed_account_id' => $this->account('Janus')->getKey()]);

        $plan->refresh();

        try {
            app(AccountingRemediationService::class)->apply($plan, $this->admin, false);
            $this->fail('Plan yang berubah setelah approval seharusnya diblokir.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('berubah setelah approval', $exception->getMessage());
        }

        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_transaksi_berubah_setelah_approval_memblokir_apply(): void
    {
        $plan = $this->makeApprovedReadyPlan();

        DB::table('financial_transactions')
            ->where('id', $plan->financial_transaction_id)
            ->update(['amount' => 150000]);

        try {
            app(AccountingRemediationService::class)->apply($plan->fresh(), $this->admin, false);
            $this->fail('Transaksi yang berubah setelah approval seharusnya diblokir.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Transaksi berubah setelah approval', $exception->getMessage());
        }

        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_mapping_berubah_setelah_approval_memblokir_apply(): void
    {
        $plan = $this->makeApprovedReadyPlan();

        DB::table('category_account_mappings')
            ->where('financial_category_id', $this->category('Fee Crew', FinancialCategory::TYPE_EXPENSE)->getKey())
            ->update(['debit_account_id' => $this->accountingAccount('5000')->getKey()]);

        try {
            app(AccountingRemediationService::class)->apply($plan->fresh(), $this->admin, false);
            $this->fail('Mapping yang berubah setelah approval seharusnya diblokir.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('berubah setelah approval', $exception->getMessage());
        }

        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_mapping_konsumsi_dibuat_setelah_approval_tetap_dapat_di_apply(): void
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 100000,
            'category_id' => $this->category('Konsumsi', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => $this->account('Ekspansi')->getKey(),
            'transaction_date' => '2026-11-10',
        ]);

        app(AccountingRemediationService::class)->syncUnpostedTransactions($this->admin);

        $plan = AccountingRemediationPlan::query()
            ->where('financial_transaction_id', $transaction->getKey())
            ->firstOrFail();

        app(AccountingRemediationService::class)->approve($plan, $this->admin, 'Setuju mapping Konsumsi');

        CategoryAccountMapping::create([
            'financial_category_id' => $this->category('Konsumsi', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'debit_account_id' => $this->accountingAccount('5100')->getKey(),
            'credit_account_id' => null,
            'is_active' => true,
            'notes' => 'Mapping Konsumsi setelah approval',
        ]);

        $result = app(AccountingRemediationService::class)->apply($plan->fresh(), $this->admin, false);

        $this->assertNotNull($result['new_journal_id']);
        $this->assertSame(AccountingRemediationPlan::STATUS_APPLIED, $plan->fresh()->status);

        $journal = JournalEntry::query()->findOrFail($result['new_journal_id']);

        $this->assertSame('5100', $journal->lines()
            ->whereHas('chartOfAccount', fn ($query) => $query->where('code', '5100'))
            ->firstOrFail()
            ->chartOfAccount
            ->code);
        $this->assertSame($this->account('Ekspansi')->getKey(), $transaction->fresh()->account_id);
    }

    private function makeApprovedReadyPlan(): AccountingRemediationPlan
    {
        $transaction = $this->makeFinancialTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 100000,
            'category_id' => $this->category('Fee Crew', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'account_id' => null,
            'transaction_date' => '2026-11-10',
            'description' => 'Bayar Edwin',
        ]);

        app(AccountingRemediationService::class)->syncUnpostedTransactions($this->admin);

        $plan = AccountingRemediationPlan::query()
            ->where('financial_transaction_id', $transaction->getKey())
            ->firstOrFail();

        return app(AccountingRemediationService::class)->approve($plan, $this->admin, 'Setuju uji');
    }

    private function account(string $name): FinancialAccount
    {
        return FinancialAccount::query()->where('name', $name)->firstOrFail();
    }
}
