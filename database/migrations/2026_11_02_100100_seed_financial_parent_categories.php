<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PARENT_NAMES = [
        'Box',
        'Transportasi',
        'Pengadaan Peralatan',
        'Gaji',
        'Persediaan',
        'Konsumsi',
        'Langganan',
        'Tenant/Sharprof',
        'RnD',
        'Pendapatan Booth',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PARENT_NAMES as $name) {
            $exists = DB::table('financial_parent_categories')->where('name', $name)->exists();

            if ($exists) {
                continue;
            }

            DB::table('financial_parent_categories')->insert([
                'name' => $name,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Kategori utama dibiarkan agar relasi sub kategori yang sudah ada tetap valid.
    }
};
