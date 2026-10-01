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
        $perusahaanId = null;
        foreach (['Wedding Photomate', 'Photomate Express', 'Newspaper Janus'] as $siblingBranch) {
            $perusahaanId ??= Cabang::withTrashed()->where('nama_cabang', $siblingBranch)->value('perusahaan_id');
        }
        $perusahaanId ??= Perusahaan::first()?->perusahaan_id;

        if (!$perusahaanId) {
            return;
        }

        $nextNumber = ((int) Cabang::withTrashed()
            ->pluck('cabang_id')
            ->map(fn ($id) => (int) substr($id, 1))
            ->max()) + 1;

        $branches = [
            ['nama_cabang' => 'Express Sewa', 'alamat' => 'Express Sewa Outlet'],
            ['nama_cabang' => 'Express Self Run', 'alamat' => 'Express Self Run Outlet'],
        ];

        foreach ($branches as $branch) {
            if (Cabang::where('nama_cabang', $branch['nama_cabang'])->exists()) {
                continue;
            }

            Cabang::create([
                'cabang_id' => 'C' . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT),
                'perusahaan_id' => $perusahaanId,
                'nama_cabang' => $branch['nama_cabang'],
                'alamat' => $branch['alamat'],
            ]);

            $nextNumber++;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Cabang::whereIn('nama_cabang', ['Express Sewa', 'Express Self Run'])->delete();
    }
};
