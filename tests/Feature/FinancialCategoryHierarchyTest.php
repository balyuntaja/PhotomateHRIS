<?php

namespace Tests\Feature;

use App\Models\FinancialCategory;
use App\Models\FinancialParentCategory;
use App\Models\FinancialTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialCategoryHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_kategori_utama_di_seed_lengkap(): void
    {
        $expected = [
            'Box',
            'Gaji',
            'Konsumsi',
            'Langganan',
            'Pendapatan Booth',
            'Pengadaan Peralatan',
            'Persediaan',
            'RnD',
            'Tenant/Sharprof',
            'Transportasi',
        ];

        $this->assertSame(
            $expected,
            FinancialParentCategory::query()->orderBy('name')->pluck('name')->all()
        );
    }

    public function test_semua_kategori_pemasukan_memakai_parent_pendapatan_booth(): void
    {
        $parent = FinancialParentCategory::query()
            ->where('name', FinancialParentCategory::NAME_INCOME)
            ->firstOrFail();

        $this->assertSame(
            0,
            FinancialCategory::query()
                ->where('transaction_type', FinancialCategory::TYPE_INCOME)
                ->whereNull('parent_category_id')
                ->count()
        );

        $this->assertSame(
            FinancialCategory::query()->where('transaction_type', FinancialCategory::TYPE_INCOME)->count(),
            FinancialCategory::query()
                ->where('transaction_type', FinancialCategory::TYPE_INCOME)
                ->where('parent_category_id', $parent->id)
                ->count()
        );
    }

    public function test_kategori_pengeluaran_yang_jelas_dipetakan_ke_parent_yang_benar(): void
    {
        $expected = [
            'Kertas' => 'Persediaan',
            'Tinta' => 'Persediaan',
            'Bensin' => 'Transportasi',
            'Transportasi' => 'Transportasi',
            'Peralatan' => 'Pengadaan Peralatan',
            'Gaji' => 'Gaji',
            'Fee Crew' => 'Gaji',
            'Bonus' => 'Gaji',
            'Freelance' => 'Gaji',
            'Hosting' => 'Langganan',
            'Domain' => 'Langganan',
            'Software' => 'Langganan',
            'Subscription' => 'Langganan',
            'Server' => 'Langganan',
            'Canva' => 'Langganan',
        ];

        foreach ($expected as $categoryName => $parentName) {
            $category = FinancialCategory::query()
                ->where('name', $categoryName)
                ->where('transaction_type', FinancialCategory::TYPE_EXPENSE)
                ->firstOrFail();

            $this->assertSame(
                $parentName,
                $category->parentCategory?->name,
                'Mapping parent salah untuk kategori '.$categoryName
            );
        }
    }

    public function test_transaksi_otomatis_mendapat_kategori_utama_dari_sub_kategori(): void
    {
        $category = FinancialCategory::query()
            ->where('name', 'Tinta')
            ->where('transaction_type', FinancialCategory::TYPE_EXPENSE)
            ->firstOrFail();

        $transaction = FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 100000,
            'category_id' => $category->id,
            'transaction_date' => now()->toDateString(),
        ]);

        $this->assertSame($category->parent_category_id, $transaction->parent_category_id);
    }

    public function test_kategori_pengeluaran_yang_ambigu_tidak_dipetakan_otomatis(): void
    {
        $unmapped = [
            'Administrasi',
            'Advertising',
            'Background',
            'Lain-lain',
            'Maintenance',
            'Printing Promosi',
            'Properti',
            'Social Media',
        ];

        $categories = FinancialCategory::query()
            ->whereIn('name', $unmapped)
            ->where('transaction_type', FinancialCategory::TYPE_EXPENSE)
            ->get();

        $this->assertCount(count($unmapped), $categories);

        foreach ($categories as $category) {
            $this->assertNull(
                $category->parent_category_id,
                'Kategori '.$category->name.' seharusnya belum dipetakan.'
            );
        }
    }
}
