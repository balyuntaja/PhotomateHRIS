<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Akun keuangan Photomate yang dipakai pada Input Transaksi V2.
     */
    private const ACCOUNTS = [
        'Photomate Express',
        'Golio',
        'Janus',
    ];

    /**
     * Pihak awal (dibayarkan oleh / diterima oleh), dikelompokkan per tipe.
     */
    private const PARTIES = [
        'employee' => ['Balyun', 'Iffah'],
        'crew' => ['Crew'],
        'company' => ['Photomate'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::ACCOUNTS as $index => $name) {
            $this->insertOnce('financial_accounts', $name, [
                'sort_order' => $index + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::PARTIES as $type => $names) {
            foreach ($names as $name) {
                $this->insertOnce('financial_parties', $name, [
                    'type' => $type,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Master data dibiarkan agar transaksi yang sudah memakainya tetap valid.
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function insertOnce(string $table, string $name, array $values): void
    {
        if (DB::table($table)->where('name', $name)->exists()) {
            return;
        }

        DB::table($table)->insert($values + ['name' => $name]);
    }
};
