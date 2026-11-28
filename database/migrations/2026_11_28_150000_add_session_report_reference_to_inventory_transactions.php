<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('session_report_id')->nullable()->after('idempotency_key');
            $table->string('reference_type', 40)->nullable()->after('session_report_id');
            $table->string('reference_number', 60)->nullable()->after('reference_type');

            $table->index('session_report_id', 'inv_tx_session_report_index');
            $table->index(['reference_type', 'session_report_id'], 'inv_tx_ref_type_report_index');
            $table->index('reference_number', 'inv_tx_reference_number_index');

            $table->foreign('session_report_id', 'inv_tx_session_report_fk')
                ->references('id')
                ->on('session_reports')
                ->nullOnDelete();
        });

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE inventory_stocks MODIFY jumlah INT NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE inventory_transactions MODIFY stok_sebelum INT NOT NULL');
            DB::statement('ALTER TABLE inventory_transactions MODIFY stok_sesudah INT NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropForeign('inv_tx_session_report_fk');
            $table->dropIndex('inv_tx_session_report_index');
            $table->dropIndex('inv_tx_ref_type_report_index');
            $table->dropIndex('inv_tx_reference_number_index');
            $table->dropColumn(['session_report_id', 'reference_type', 'reference_number']);
        });
    }
};
