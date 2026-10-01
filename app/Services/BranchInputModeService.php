<?php

namespace App\Services;

use App\Models\Cabang;

class BranchInputModeService
{
    /**
     * Cabang dengan mode input manual: cukup isi total Jumlah Sesi + Jumlah Lembar,
     * tanpa detail sesi per transaksi.
     */
    public const MANUAL_INPUT_BRANCH_KEYWORDS = [
        'wedding',
        'express sewa',
        'express self run',
    ];

    /**
     * Cek apakah cabang memakai mode input manual (Jumlah Sesi + Jumlah Lembar).
     */
    public function usesManualSessionInput(Cabang|string|null $cabang, ?string $cabangId = null): bool
    {
        if (!$cabang && $cabangId) {
            $cabang = Cabang::find($cabangId);
        }

        if (is_string($cabang)) {
            $cabang = Cabang::find($cabang);
        }

        if (!$cabang) {
            return false;
        }

        $normalizedName = preg_replace('/[^a-z0-9]+/', ' ', strtolower(trim($cabang->nama_cabang)));

        foreach (self::MANUAL_INPUT_BRANCH_KEYWORDS as $keyword) {
            if (str_contains($normalizedName, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
