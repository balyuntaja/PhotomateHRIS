<?php

namespace Tests\Feature;

use App\Filament\Resources\CategoryAccountMappingResource\Pages\ListCategoryAccountMappings;
use App\Models\AccountingMasterDataAuditLog;
use App\Models\CategoryAccountMapping;
use App\Models\ChartOfAccount;
use App\Models\FinancialCategory;
use App\Models\Karyawan;
use App\Services\AccountingMasterDataService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\AccountingTestHelpers;
use Tests\TestCase;

class AccountingMasterDataGovernanceTest extends TestCase
{
    use AccountingTestHelpers;
    use RefreshDatabase;

    protected Karyawan $admin;

    protected Karyawan $crew;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccountingData();

        $this->admin = $this->makeKaryawan('K0001', 'R01');
        $this->crew = $this->makeKaryawan('K0002', 'R07');

        $this->actingAs($this->admin);
    }

    public function test_create_update_dan_deactivate_kategori_membuat_audit_log(): void
    {
        $service = app(AccountingMasterDataService::class);

        $category = $service->createCategory([
            'name' => 'Kategori Audit Uji',
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'is_active' => true,
        ], $this->admin, 'Create audit uji');

        $this->assertDatabaseHas('accounting_master_data_audit_logs', [
            'table_name' => 'financial_categories',
            'record_id' => (string) $category->getKey(),
            'action' => AccountingMasterDataAuditLog::ACTION_CREATE,
            'actor_id' => $this->admin->karyawan_id,
            'reason' => 'Create audit uji',
        ]);

        $service->updateCategory($category, ['name' => 'Kategori Audit Uji Diubah'], $this->admin, 'Update audit uji');

        $this->assertDatabaseHas('accounting_master_data_audit_logs', [
            'table_name' => 'financial_categories',
            'record_id' => (string) $category->getKey(),
            'action' => AccountingMasterDataAuditLog::ACTION_UPDATE,
            'actor_id' => $this->admin->karyawan_id,
            'reason' => 'Update audit uji',
        ]);

        $service->deactivateCategory($category->fresh(), $this->admin, 'Deactivate audit uji');

        $this->assertDatabaseHas('accounting_master_data_audit_logs', [
            'table_name' => 'financial_categories',
            'record_id' => (string) $category->getKey(),
            'action' => AccountingMasterDataAuditLog::ACTION_DEACTIVATE,
            'actor_id' => $this->admin->karyawan_id,
            'reason' => 'Deactivate audit uji',
        ]);
    }

    public function test_mapping_dan_coa_membuat_audit_log(): void
    {
        $service = app(AccountingMasterDataService::class);

        $category = $service->createCategory([
            'name' => 'Kategori Mapping Audit',
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'is_active' => true,
        ], $this->admin, 'Kategori mapping audit');

        $mapping = $service->createMapping([
            'financial_category_id' => $category->getKey(),
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'debit_account_id' => $this->accountingAccount('5100')->getKey(),
            'credit_account_id' => null,
            'is_active' => true,
            'notes' => 'Mapping audit',
        ], $this->admin, 'Create mapping audit');

        $this->assertDatabaseHas('accounting_master_data_audit_logs', [
            'table_name' => 'category_account_mappings',
            'record_id' => (string) $mapping->getKey(),
            'action' => AccountingMasterDataAuditLog::ACTION_CREATE,
            'reason' => 'Create mapping audit',
        ]);

        $service->deactivateMapping($mapping, $this->admin, 'Deactivate mapping audit');

        $this->assertDatabaseHas('accounting_master_data_audit_logs', [
            'table_name' => 'category_account_mappings',
            'record_id' => (string) $mapping->getKey(),
            'action' => AccountingMasterDataAuditLog::ACTION_DEACTIVATE,
            'reason' => 'Deactivate mapping audit',
        ]);

        $account = $service->createAccount([
            'code' => '9901',
            'name' => 'Akun Audit Uji',
            'type' => ChartOfAccount::TYPE_EXPENSE,
            'report_group' => 'opex',
            'is_active' => true,
        ], $this->admin, 'Create COA audit');

        $service->deactivateAccount($account, $this->admin, 'Deactivate COA audit');

        $this->assertDatabaseHas('accounting_master_data_audit_logs', [
            'table_name' => 'chart_of_accounts',
            'record_id' => (string) $account->getKey(),
            'action' => AccountingMasterDataAuditLog::ACTION_DEACTIVATE,
            'reason' => 'Deactivate COA audit',
        ]);
    }

    public function test_audit_log_master_data_tidak_dapat_diubah_atau_dihapus(): void
    {
        $log = AccountingMasterDataAuditLog::create([
            'table_name' => 'financial_categories',
            'record_id' => '1',
            'action' => AccountingMasterDataAuditLog::ACTION_CREATE,
            'old_values' => null,
            'new_values' => ['name' => 'Uji'],
            'actor_id' => $this->admin->karyawan_id,
            'reason' => 'Uji immutable',
            'created_at' => now(),
        ]);

        try {
            $log->update(['reason' => 'Diubah']);
            $this->fail('Audit log seharusnya tidak dapat diubah.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('tidak dapat diubah', $exception->getMessage());
        }

        try {
            $log->delete();
            $this->fail('Audit log seharusnya tidak dapat dihapus.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('tidak dapat dihapus', $exception->getMessage());
        }
    }

    public function test_perubahan_mapping_melalui_filament_mencatat_actor_dan_reason(): void
    {
        $mapping = CategoryAccountMapping::query()
            ->where('financial_category_id', $this->category('Fee Crew', FinancialCategory::TYPE_EXPENSE)->getKey())
            ->firstOrFail();

        Livewire::test(ListCategoryAccountMappings::class)
            ->callTableAction('edit', $mapping, [
                'notes' => 'Catatan dari Filament',
                'change_reason' => 'Uji audit Filament',
            ])
            ->assertHasNoTableActionErrors();

        $log = AccountingMasterDataAuditLog::query()
            ->where('table_name', 'category_account_mappings')
            ->where('record_id', (string) $mapping->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(AccountingMasterDataAuditLog::ACTION_UPDATE, $log->action);
        $this->assertSame($this->admin->karyawan_id, $log->actor_id);
        $this->assertSame('Uji audit Filament', $log->reason);
    }

    public function test_crew_tidak_dapat_mengubah_master_data(): void
    {
        $mapping = CategoryAccountMapping::query()->firstOrFail();
        $account = ChartOfAccount::query()->firstOrFail();
        $category = FinancialCategory::query()->firstOrFail();

        $this->assertTrue(Gate::forUser($this->crew)->denies('create', CategoryAccountMapping::class));
        $this->assertTrue(Gate::forUser($this->crew)->denies('update', $mapping));
        $this->assertTrue(Gate::forUser($this->crew)->denies('delete', $mapping));
        $this->assertTrue(Gate::forUser($this->crew)->denies('update', $account));
        $this->assertTrue(Gate::forUser($this->crew)->denies('update', $category));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tidak memiliki izin');

        app(AccountingMasterDataService::class)->updateCategory(
            $category,
            ['name' => 'Perubahan Crew'],
            $this->crew,
            'Percobaan crew'
        );
    }

    public function test_konfigurasi_kapitalisasi_tersedia_dan_dapat_diubah(): void
    {
        $this->assertSame(1_000_000, (int) config('accounting.capitalization.minimum_amount'));
        $this->assertTrue((bool) config('accounting.capitalization.threshold_inclusive'));
        $this->assertSame('straight_line', config('accounting.capitalization.depreciation_method'));
        $this->assertSame(0, (int) config('accounting.capitalization.residual_value'));
        $this->assertSame('month_after_in_service', config('accounting.capitalization.depreciation_start'));
        $this->assertSame('evidence_required', config('accounting.capitalization.pre_system_assets'));
        $this->assertArrayHasKey('kamera', config('accounting.capitalization.useful_life_years'));
        $this->assertArrayHasKey('peralatan_aset_tetap', config('accounting.capitalization.asset_categories'));
    }
}
