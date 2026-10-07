<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Nama akun lama yang terlanjur ter-seed sebelum migration ini dibuat.
     */
    private const RENAMES = [
        'Express' => 'Photomate Express',
        'Janus Ekspansi' => 'Janus',
    ];

    /**
     * Empat akun keuangan final beserta urutannya.
     */
    private const ACCOUNTS = [
        'Photomate Express' => 1,
        'Golio' => 2,
        'Janus' => 3,
        'Ekspansi' => 4,
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->rename($old, $new);
        }

        $now = now();

        foreach (self::ACCOUNTS as $name => $sortOrder) {
            if (DB::table('financial_accounts')->where('name', $name)->exists()) {
                continue;
            }

            DB::table('financial_accounts')->insert([
                'name' => $name,
                'sort_order' => $sortOrder,
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

    private function rename(string $old, string $new): void
    {
        $oldExists = DB::table('financial_accounts')->where('name', $old)->exists();

        if (! $oldExists || DB::table('financial_accounts')->where('name', $new)->exists()) {
            return;
        }

        DB::table('financial_accounts')
            ->where('name', $old)
            ->update([
                'name' => $new,
                'updated_at' => now(),
            ]);
    }
};
