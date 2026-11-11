<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SEED_NOTE = 'phase-1-initial-mapping';

    /**
     * Kategori income yang jelas merupakan pendapatan booth.
     * "Pendapatan Lain-lain" sengaja belum dipetakan karena belum ada akun
     * Other Income yang disepakati pada COA awal.
     */
    private const INCOME_CATEGORIES = [
        'Pendapatan Booth',
        'Event',
        'Sewa Booth',
        'DP',
        'Pelunasan',
        'Pelunasan Invoice',
        'Penjualan Produk',
        'Tambahan Jam',
        'Tambahan Session',
    ];

    private const HPP_CATEGORIES = [
        'Kertas' => '5000',
        'Tinta' => '5010',
    ];

    /**
     * Beban operasional yang jelas. Background, Properti, dan Peralatan
     * belum dipetakan karena bisa berupa aset tetap atau biaya produksi.
     */
    private const OPEX_CATEGORIES = [
        'Administrasi',
        'Advertising',
        'Bensin',
        'Bonus',
        'Canva',
        'Domain',
        'Fee Crew',
        'Freelance',
        'Gaji',
        'Hosting',
        'Lain-lain',
        'Maintenance',
        'Printing Promosi',
        'Server',
        'Social Media',
        'Software',
        'Subscription',
        'Transportasi',
    ];

    public function up(): void
    {
        $now = now();
        $revenueAccountId = $this->accountId('4000');

        if ($revenueAccountId !== null) {
            foreach (self::INCOME_CATEGORIES as $categoryName) {
                $this->insertMapping($categoryName, 'income', null, $revenueAccountId, $now);
            }
        }

        foreach (self::HPP_CATEGORIES as $categoryName => $accountCode) {
            $accountId = $this->accountId($accountCode);

            if ($accountId !== null) {
                $this->insertMapping($categoryName, 'expense', $accountId, null, $now);
            }
        }

        $opexAccountId = $this->accountId('5100');

        if ($opexAccountId !== null) {
            foreach (self::OPEX_CATEGORIES as $categoryName) {
                $this->insertMapping($categoryName, 'expense', $opexAccountId, null, $now);
            }
        }
    }

    public function down(): void
    {
        DB::table('category_account_mappings')
            ->where('notes', self::SEED_NOTE)
            ->delete();
    }

    private function accountId(string $code): ?int
    {
        $id = DB::table('chart_of_accounts')->where('code', $code)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function insertMapping(
        string $categoryName,
        string $transactionType,
        ?int $debitAccountId,
        ?int $creditAccountId,
        mixed $now
    ): void {
        $categoryId = DB::table('financial_categories')
            ->where('name', $categoryName)
            ->where('transaction_type', $transactionType)
            ->value('id');

        if ($categoryId === null) {
            return;
        }

        $exists = DB::table('category_account_mappings')
            ->where('financial_category_id', $categoryId)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('category_account_mappings')->insert([
            'financial_category_id' => $categoryId,
            'financial_parent_category_id' => null,
            'transaction_type' => $transactionType,
            'debit_account_id' => $debitAccountId,
            'credit_account_id' => $creditAccountId,
            'is_active' => true,
            'notes' => self::SEED_NOTE,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
