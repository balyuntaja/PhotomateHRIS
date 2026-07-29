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
        Schema::table('invoices', function (Blueprint $table) {
            $table->date('dp_date')->nullable()->after('down_payment');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->default(0)->after('time_range');
            $table->integer('device_count')->default(1)->after('price');
        });

        // Initialize price from amount for existing records
        DB::table('invoice_items')->update([
            'price' => DB::raw('amount')
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('dp_date');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['price', 'device_count']);
        });
    }
};
