<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('financial_categories', function (Blueprint $table) {
            $table->dropUnique('financial_categories_name_transaction_type_unique');

            $table->unique(
                ['parent_category_id', 'name', 'transaction_type'],
                'fin_cat_parent_name_type_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('financial_categories', function (Blueprint $table) {
            $table->dropUnique('fin_cat_parent_name_type_unique');

            $table->unique(
                ['name', 'transaction_type'],
                'financial_categories_name_transaction_type_unique'
            );
        });
    }
};
