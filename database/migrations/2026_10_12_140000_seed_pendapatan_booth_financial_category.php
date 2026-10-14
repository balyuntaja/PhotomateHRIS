<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CATEGORY_NAME = 'Pendapatan Booth';

    public function up(): void
    {
        $exists = DB::table('financial_categories')
            ->where('name', self::CATEGORY_NAME)
            ->where('transaction_type', 'income')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('financial_categories')->insert([
            'name' => self::CATEGORY_NAME,
            'group_name' => null,
            'transaction_type' => 'income',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('financial_categories')
            ->where('name', self::CATEGORY_NAME)
            ->where('transaction_type', 'income')
            ->delete();
    }
};
