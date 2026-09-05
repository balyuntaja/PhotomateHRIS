<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->date('max_dp_date')->nullable()->after('invoice_date');
        });

        // Initialize max_dp_date for existing invoices as invoice_date + 1 day
        $invoices = DB::table('invoices')->whereNull('max_dp_date')->whereNotNull('invoice_date')->get(['id', 'invoice_date']);
        foreach ($invoices as $inv) {
            DB::table('invoices')->where('id', $inv->id)->update([
                'max_dp_date' => Carbon::parse($inv->invoice_date)->addDay()->toDateString(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('max_dp_date');
        });
    }
};
