<?php

use App\Models\Cabang;
use App\Models\Perusahaan;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (Cabang::where('nama_cabang', 'Golio')->exists()) {
            return;
        }

        $perusahaanId = null;
        foreach (['Express Self Run', 'Express Sewa', 'Photomate Express', 'Newspaper Janus'] as $siblingBranch) {
            $perusahaanId ??= Cabang::withTrashed()->where('nama_cabang', $siblingBranch)->value('perusahaan_id');
        }
        $perusahaanId ??= Perusahaan::first()?->perusahaan_id;

        if (! $perusahaanId) {
            return;
        }

        $nextNumber = ((int) Cabang::withTrashed()
            ->pluck('cabang_id')
            ->map(fn ($id) => (int) substr($id, 1))
            ->max()) + 1;

        Cabang::create([
            'cabang_id' => 'C' . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT),
            'perusahaan_id' => $perusahaanId,
            'nama_cabang' => 'Golio',
            'alamat' => 'Golio Booth',
        ]);
    }

    public function down(): void
    {
        Cabang::where('nama_cabang', 'Golio')->delete();
    }
};
