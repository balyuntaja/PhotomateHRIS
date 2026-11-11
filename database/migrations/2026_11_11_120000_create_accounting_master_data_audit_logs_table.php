<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_master_data_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('table_name', 100);
            $table->string('record_id', 100);
            $table->string('action', 20);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('actor_id', 5)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['table_name', 'record_id', 'created_at'], 'master_audit_table_record_time_index');
            $table->index(['action', 'created_at'], 'master_audit_action_time_index');

            $table->foreign('actor_id')->references('karyawan_id')->on('karyawan')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_master_data_audit_logs');
    }
};
