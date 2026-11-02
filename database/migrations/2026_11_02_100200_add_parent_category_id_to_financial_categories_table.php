<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_categories', function (Blueprint $table) {
            $table->foreignId('parent_category_id')->nullable()->after('name')
                ->constrained('financial_parent_categories')->restrictOnDelete();

            $table->index(
                ['parent_category_id', 'transaction_type', 'is_active'],
                'fin_cat_parent_type_active_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('financial_categories', function (Blueprint $table) {
            $table->dropIndex('fin_cat_parent_type_active_idx');
            $table->dropConstrainedForeignId('parent_category_id');
        });
    }
};
