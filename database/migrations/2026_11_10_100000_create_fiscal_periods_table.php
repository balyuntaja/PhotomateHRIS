<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 10)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by', 5)->nullable();
            $table->timestamps();

            $table->unique(['year', 'month']);
            $table->index(['status', 'start_date', 'end_date']);

            $table->foreign('closed_by')
                ->references('karyawan_id')
                ->on('karyawan')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
    }
};
