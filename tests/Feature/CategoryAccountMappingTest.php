<?php

namespace Tests\Feature;

use App\Models\CategoryAccountMapping;
use App\Models\FinancialCategory;
use App\Models\FinancialParentCategory;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AccountingTestHelpers;
use Tests\TestCase;

class CategoryAccountMappingTest extends TestCase
{
    use AccountingTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccountingData();
    }

    public function test_mapping_kategori_berhasil(): void
    {
        $category = $this->category('Background', FinancialCategory::TYPE_EXPENSE);
        $account = $this->accountingAccount('5100');

        $mapping = CategoryAccountMapping::create([
            'financial_category_id' => $category->getKey(),
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'debit_account_id' => $account->getKey(),
            'credit_account_id' => null,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('category_account_mappings', [
            'id' => $mapping->getKey(),
            'financial_category_id' => $category->getKey(),
            'debit_account_id' => $account->getKey(),
        ]);

        $this->assertSame($mapping->getKey(), CategoryAccountMapping::resolveForCategory($category)?->getKey());
    }

    public function test_kategori_income_tidak_dapat_memakai_mapping_expense(): void
    {
        $category = $this->category('Pendapatan Lain-lain', FinancialCategory::TYPE_INCOME);
        $expenseAccount = $this->accountingAccount('5100');

        $this->expectException(DomainException::class);

        CategoryAccountMapping::create([
            'financial_category_id' => $category->getKey(),
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'debit_account_id' => $expenseAccount->getKey(),
            'credit_account_id' => null,
            'is_active' => true,
        ]);
    }

    public function test_mapping_income_tidak_boleh_memakai_akun_beban_sebagai_kredit(): void
    {
        $category = $this->category('Pendapatan Lain-lain', FinancialCategory::TYPE_INCOME);
        $expenseAccount = $this->accountingAccount('5100');

        $this->expectException(DomainException::class);

        CategoryAccountMapping::create([
            'financial_category_id' => $category->getKey(),
            'transaction_type' => FinancialCategory::TYPE_INCOME,
            'debit_account_id' => null,
            'credit_account_id' => $expenseAccount->getKey(),
            'is_active' => true,
        ]);
    }

    public function test_satu_kategori_tidak_boleh_memiliki_mapping_ganda(): void
    {
        $category = $this->category('Background', FinancialCategory::TYPE_EXPENSE);
        $account = $this->accountingAccount('5100');

        CategoryAccountMapping::create([
            'financial_category_id' => $category->getKey(),
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'debit_account_id' => $account->getKey(),
            'is_active' => true,
        ]);

        $this->expectException(DomainException::class);

        CategoryAccountMapping::create([
            'financial_category_id' => $category->getKey(),
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'debit_account_id' => $account->getKey(),
            'is_active' => true,
        ]);
    }

    public function test_fallback_mapping_parent_digunakan_dan_mapping_nonaktif_diabaikan(): void
    {
        $parent = FinancialParentCategory::query()->where('name', 'Pengadaan Peralatan')->firstOrFail();
        $category = $this->category('Peralatan', FinancialCategory::TYPE_EXPENSE);
        $account = $this->accountingAccount('5100');

        $mapping = CategoryAccountMapping::create([
            'financial_parent_category_id' => $parent->getKey(),
            'transaction_type' => FinancialCategory::TYPE_EXPENSE,
            'debit_account_id' => $account->getKey(),
            'is_active' => true,
        ]);

        $this->assertSame($mapping->getKey(), CategoryAccountMapping::resolveForCategory($category)?->getKey());

        $mapping->update(['is_active' => false]);

        $this->assertNull(CategoryAccountMapping::resolveForCategory($category->fresh()));
    }

    public function test_seed_mapping_awal_mencakup_kategori_yang_jelas(): void
    {
        $this->assertSame(29, CategoryAccountMapping::query()->count());

        $this->assertDatabaseHas('category_account_mappings', [
            'financial_category_id' => $this->category('Pendapatan Booth', FinancialCategory::TYPE_INCOME)->getKey(),
            'credit_account_id' => $this->accountingAccount('4000')->getKey(),
        ]);

        $this->assertDatabaseHas('category_account_mappings', [
            'financial_category_id' => $this->category('Kertas', FinancialCategory::TYPE_EXPENSE)->getKey(),
            'debit_account_id' => $this->accountingAccount('5000')->getKey(),
        ]);

        $this->assertDatabaseMissing('category_account_mappings', [
            'financial_category_id' => $this->category('Pendapatan Lain-lain', FinancialCategory::TYPE_INCOME)->getKey(),
        ]);
    }
}
