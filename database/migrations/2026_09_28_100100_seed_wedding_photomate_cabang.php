<?php

use App\Models\Cabang;
use App\Models\Perusahaan;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $perusahaan = Perusahaan::first();
        if (!$perusahaan) {
            return;
        }

        if (!Cabang::where('nama_cabang', 'Wedding Photomate')->exists()) {
            Cabang::create([
                'cabang_id' => 'C0006',
                'perusahaan_id' => $perusahaan->perusahaan_id,
                'nama_cabang' => 'Wedding Photomate',
                'alamat' => 'Wedding Photomate Studio',
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Cabang::where('nama_cabang', 'Wedding Photomate')->delete();
    }
};
