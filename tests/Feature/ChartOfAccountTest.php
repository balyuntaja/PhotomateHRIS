<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AccountingTestHelpers;
use Tests\TestCase;

class ChartOfAccountTest extends TestCase
{
    use AccountingTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccountingData();
    }

    public function test_coa_dapat_dibuat(): void
    {
        $account = $this->makeAccount([
            'code' => '9001',
            'name' => 'Akun Uji COA',
            'type' => ChartOfAccount::TYPE_ASSET,
            'report_group' => 'current_asset',
        ]);

        $this->assertDatabaseHas('chart_of_accounts', [
            'id' => $account->getKey(),
            'code' => '9001',
            'name' => 'Akun Uji COA',
            'type' => ChartOfAccount::TYPE_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
        ]);
    }

    public function test_kode_akun_duplikat_ditolak(): void
    {
        $this->makeAccount(['code' => '9001']);

        $this->expectException(DomainException::class);

        $this->makeAccount(['code' => '9001', 'name' => 'Duplikat']);
    }

    public function test_akun_parent_child_valid(): void
    {
        $parent = $this->makeAccount([
            'code' => '9001',
            'name' => 'Aset Induk',
            'type' => ChartOfAccount::TYPE_ASSET,
            'report_group' => 'current_asset',
        ]);

        $child = $this->makeAccount([
            'code' => '9002',
            'name' => 'Aset Anak',
            'type' => ChartOfAccount::TYPE_ASSET,
            'report_group' => 'current_asset',
            'parent_id' => $parent->getKey(),
        ]);

        $this->assertSame($parent->getKey(), $child->parent_id);
        $this->assertTrue($child->isDescendantOf($parent));
    }

    public function test_parent_dengan_tipe_berbeda_ditolak(): void
    {
        $parent = $this->makeAccount([
            'code' => '9001',
            'name' => 'Aset Induk',
            'type' => ChartOfAccount::TYPE_ASSET,
            'report_group' => 'current_asset',
        ]);

        $this->expectException(DomainException::class);

        $this->makeAccount([
            'code' => '9002',
            'name' => 'Liabilitas Anak',
            'type' => ChartOfAccount::TYPE_LIABILITY,
            'report_group' => 'current_liability',
            'parent_id' => $parent->getKey(),
        ]);
    }

    public function test_normal_balance_tidak_sesuai_tipe_ditolak(): void
    {
        $this->expectException(DomainException::class);

        $this->makeAccount([
            'code' => '9001',
            'name' => 'Liabilitas Salah Normal',
            'type' => ChartOfAccount::TYPE_LIABILITY,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'report_group' => 'current_liability',
        ]);
    }

    public function test_akun_yang_masih_dipakai_tidak_dapat_dihapus(): void
    {
        $parent = $this->makeAccount([
            'code' => '9001',
            'name' => 'Induk',
            'type' => ChartOfAccount::TYPE_ASSET,
            'report_group' => 'current_asset',
        ]);

        $this->makeAccount([
            'code' => '9002',
            'name' => 'Anak',
            'type' => ChartOfAccount::TYPE_ASSET,
            'report_group' => 'current_asset',
            'parent_id' => $parent->getKey(),
        ]);

        $this->expectException(DomainException::class);

        $parent->delete();
    }

    public function test_seed_coa_dan_koneksi_financial_accounts_berjalan(): void
    {
        $this->assertSame(15, ChartOfAccount::query()->count());

        $expectedLinks = [
            'Photomate Express' => '1001',
            'Golio' => '1002',
            'Janus' => '1003',
            'Ekspansi' => '1004',
        ];

        foreach ($expectedLinks as $financialAccountName => $chartOfAccountCode) {
            $financialAccount = \App\Models\FinancialAccount::query()
                ->where('name', $financialAccountName)
                ->firstOrFail();

            $this->assertNotNull($financialAccount->chart_of_account_id);
            $this->assertSame(
                $chartOfAccountCode,
                $financialAccount->chartOfAccount?->code
            );
        }
    }
}
