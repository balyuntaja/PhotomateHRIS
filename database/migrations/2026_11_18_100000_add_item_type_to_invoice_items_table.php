<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('item_type', 20)->default('service')->nullable()->after('invoice_id');
        });

        // Backfill existing records:
        // Items with no duration and no time range are 'product', all others are 'service'
        DB::table('invoice_items')
            ->where(function ($query) {
                $query->whereNull('duration')
                    ->orWhere('duration', '')
                    ->orWhere('duration', '-');
            })
            ->where(function ($query) {
                $query->whereNull('time_range')
                    ->orWhere('time_range', '')
                    ->orWhere('time_range', '-');
            })
            ->update(['item_type' => 'product']);

        DB::table('invoice_items')
            ->whereNull('item_type')
            ->update(['item_type' => 'service']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('item_type');
        });
    }
};
