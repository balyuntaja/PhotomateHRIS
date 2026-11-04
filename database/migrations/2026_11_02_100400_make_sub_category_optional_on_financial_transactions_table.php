<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->foreignId('parent_category_id')->nullable()->after('category_id')
                ->constrained('financial_parent_categories')->restrictOnDelete();
        });

        $backfilled = 0;

        $categories = DB::table('financial_categories')
            ->whereNotNull('parent_category_id')
            ->get(['id', 'parent_category_id']);

        foreach ($categories as $category) {
            $backfilled += DB::table('financial_transactions')
                ->where('category_id', $category->id)
                ->whereNull('parent_category_id')
                ->update(['parent_category_id' => $category->parent_category_id]);
        }

        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->change();
        });

        $withoutParent = DB::table('financial_transactions')
            ->whereNull('parent_category_id')
            ->count();

        echo 'Transaksi mendapat kategori utama: '.$backfilled.PHP_EOL;
        echo 'Transaksi tanpa kategori utama: '.$withoutParent.PHP_EOL;
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_category_id');
        });

        // category_id tetap nullable agar transaksi tanpa sub kategori tidak hilang saat rollback.
    }
};
