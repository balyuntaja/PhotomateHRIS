<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Kategori pemasukan default (dikelompokkan flat, tanpa group_name).
     */
    private const INCOME_CATEGORIES = [
        'Event',
        'Sewa Booth',
        'DP',
        'Pelunasan',
        'Tambahan Jam',
        'Tambahan Session',
        'Penjualan Produk',
        'Pendapatan Lain-lain',
    ];

    /**
     * Kategori pengeluaran default, dikelompokkan per grup.
     */
    private const EXPENSE_CATEGORIES = [
        'Operasional' => ['Kertas', 'Tinta', 'Background', 'Properti', 'Maintenance', 'Peralatan', 'Bensin', 'Transportasi'],
        'SDM' => ['Gaji', 'Fee Crew', 'Bonus', 'Freelance'],
        'Marketing' => ['Canva', 'Advertising', 'Social Media', 'Printing Promosi'],
        'Teknologi' => ['Hosting', 'Domain', 'Software', 'Subscription', 'Server'],
        'Lain-lain' => ['Administrasi', 'Lain-lain'],
    ];

    private const PAYMENT_METHODS = [
        'Cash',
        'Transfer',
        'QRIS',
        'E-Wallet',
        'Debit/Kredit',
        'Lain-lain',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::INCOME_CATEGORIES as $name) {
            $this->insertCategory($name, null, 'income', $now);
        }

        foreach (self::EXPENSE_CATEGORIES as $group => $names) {
            foreach ($names as $name) {
                $this->insertCategory($name, $group, 'expense', $now);
            }
        }

        foreach (self::PAYMENT_METHODS as $name) {
            $exists = DB::table('financial_payment_methods')->where('name', $name)->exists();

            if ($exists) {
                continue;
            }

            DB::table('financial_payment_methods')->insert([
                'name' => $name,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Master data dibiarkan agar transaksi yang sudah memakainya tetap valid.
    }

    private function insertCategory(string $name, ?string $group, string $type, mixed $now): void
    {
        $exists = DB::table('financial_categories')
            ->where('name', $name)
            ->where('transaction_type', $type)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('financial_categories')->insert([
            'name' => $name,
            'group_name' => $group,
            'transaction_type' => $type,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
