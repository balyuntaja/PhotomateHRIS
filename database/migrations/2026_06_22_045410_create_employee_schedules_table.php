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
        Schema::create('employee_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('karyawan_id');
            $table->string('employee_name');
            $table->date('shift_date');
            $table->enum('shift_type', ['morning', 'afternoon', 'evening', 'full_day']);
            $table->string('booth_location')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('karyawan_id')->references('karyawan_id')->on('karyawan')->onDelete('cascade');
            $table->unique(['karyawan_id', 'shift_date'], 'emp_shift_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_schedules');
    }
};
