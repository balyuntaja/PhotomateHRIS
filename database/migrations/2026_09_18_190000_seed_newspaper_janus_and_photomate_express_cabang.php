<?php

use App\Models\Cabang;
use App\Models\Perusahaan;
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
        $perusahaan = Perusahaan::first();
        if (!$perusahaan) {
            return;
        }
        $perusahaanId = $perusahaan->perusahaan_id;

        // 1. Cabang Newspaper Janus
        if (!Cabang::where('nama_cabang', 'Newspaper Janus')->exists()) {
            Cabang::create([
                'cabang_id' => 'C0004',
                'perusahaan_id' => $perusahaanId,
                'nama_cabang' => 'Newspaper Janus',
                'alamat' => 'Janus Coffee, Malang',
            ]);
        }

        // 2. Cabang Photomate Express
        if (!Cabang::where('nama_cabang', 'Photomate Express')->exists()) {
            Cabang::create([
                'cabang_id' => 'C0005',
                'perusahaan_id' => $perusahaanId,
                'nama_cabang' => 'Photomate Express',
                'alamat' => 'Photomate Express Outlet',
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Cabang::whereIn('nama_cabang', ['Newspaper Janus', 'Photomate Express'])->delete();
    }
};
