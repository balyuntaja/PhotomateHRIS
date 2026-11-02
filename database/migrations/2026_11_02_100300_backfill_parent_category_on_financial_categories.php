<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INCOME_PARENT = 'Pendapatan Booth';

    /**
     * Hanya kategori dengan padanan yang jelas yang dipetakan otomatis.
     * Kategori lain dibiarkan NULL untuk dipetakan manual oleh admin.
     */
    private const EXPENSE_PARENT_MAP = [
        'Persediaan' => ['Kertas', 'Tinta'],
        'Transportasi' => ['Bensin', 'Transportasi'],
        'Pengadaan Peralatan' => ['Peralatan'],
        'Gaji' => ['Gaji', 'Fee Crew', 'Bonus', 'Freelance'],
        'Langganan' => ['Hosting', 'Domain', 'Software', 'Subscription', 'Server', 'Canva'],
    ];

    public function up(): void
    {
        $parentIds = DB::table('financial_parent_categories')->pluck('id', 'name');

        $incomeParentId = $parentIds[self::INCOME_PARENT] ?? null;

        if ($incomeParentId === null) {
            throw new RuntimeException('Kategori utama "'.self::INCOME_PARENT.'" belum tersedia.');
        }

        $mappedCount = 0;

        $mappedCount += DB::table('financial_categories')
            ->where('transaction_type', 'income')
            ->whereNull('parent_category_id')
            ->update(['parent_category_id' => $incomeParentId]);

        foreach (self::EXPENSE_PARENT_MAP as $parentName => $categoryNames) {
            $parentId = $parentIds[$parentName] ?? null;

            if ($parentId === null) {
                continue;
            }

            $mappedCount += DB::table('financial_categories')
                ->where('transaction_type', 'expense')
                ->whereNull('parent_category_id')
                ->whereIn('name', $categoryNames)
                ->update(['parent_category_id' => $parentId]);
        }

        $unmapped = DB::table('financial_categories')
            ->whereNull('parent_category_id')
            ->orderBy('transaction_type')
            ->orderBy('name')
            ->get(['name', 'transaction_type']);

        echo 'Sub kategori dipetakan: '.$mappedCount.PHP_EOL;

        if ($unmapped->isNotEmpty()) {
            echo 'Sub kategori belum dipetakan (isi manual): '.PHP_EOL;

            foreach ($unmapped as $category) {
                echo '- '.$category->transaction_type.': '.$category->name.PHP_EOL;
            }
        }
    }

    public function down(): void
    {
        $parentIds = DB::table('financial_parent_categories')->pluck('id', 'name');

        foreach (self::EXPENSE_PARENT_MAP as $parentName => $categoryNames) {
            $parentId = $parentIds[$parentName] ?? null;

            if ($parentId === null) {
                continue;
            }

            DB::table('financial_categories')
                ->where('parent_category_id', $parentId)
                ->where('transaction_type', 'expense')
                ->whereIn('name', $categoryNames)
                ->update(['parent_category_id' => null]);
        }

        $incomeParentId = $parentIds[self::INCOME_PARENT] ?? null;

        if ($incomeParentId !== null) {
            DB::table('financial_categories')
                ->where('parent_category_id', $incomeParentId)
                ->where('transaction_type', 'income')
                ->update(['parent_category_id' => null]);
        }
    }
};
