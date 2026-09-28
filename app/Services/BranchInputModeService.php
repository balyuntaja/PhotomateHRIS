<?php

namespace App\Services;

use App\Models\Cabang;

class BranchInputModeService
{
    public const WEDDING_BRANCH_KEYWORD = 'wedding';

    /**
     * Cek apakah cabang menggunakan mode input sederhana Wedding Photomate
     * (Jumlah Sesi + Jumlah Lembar), bukan detail sesi per transaksi.
     */
    public function isWeddingPhotomate(Cabang|string|null $cabang, ?string $cabangId = null): bool
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

        return str_contains(strtolower(trim($cabang->nama_cabang)), self::WEDDING_BRANCH_KEYWORD);
    }
}
