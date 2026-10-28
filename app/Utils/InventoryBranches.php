<?php

namespace App\Utils;

use App\Models\Cabang;

/**
 * Pemetaan cabang Inventory ke 3 cabang utama: Express, Golio, Newspaper Janus.
 *
 * Penggabungan hanya berlaku pada konteks Inventory. Master cabang, modul
 * keuangan, dan modul lain tidak diubah. Data stok lama tidak dipindahkan
 * maupun dihapus; pengelompokan dilakukan pada query baca Inventory.
 *
 * - Express  : seluruh cabang yang namanya mengandung "express"
 *              (Photomate Express, Express Sewa, Express Self Run,
 *              Express Sharing Profit, dst).
 * - Golio    : cabang yang namanya mengandung "golio".
 * - Janus    : cabang yang namanya mengandung "janus".
 *
 * Cabang di luar ketiga kelompok tersebut bukan cabang Inventory.
 */
class InventoryBranches
{
    public const EXPRESS = 'express';

    public const GOLIO = 'golio';

    public const JANUS = 'janus';

    public const LABELS = [
        self::EXPRESS => 'Express',
        self::GOLIO => 'Golio',
        self::JANUS => 'Newspaper Janus',
    ];

    public const ORDER = [
        self::EXPRESS,
        self::GOLIO,
        self::JANUS,
    ];

    /**
     * Normalisasi nama agar pencocokan tidak sensitif kapitalisasi,
     * spasi ganda, atau tanda baca.
     */
    public static function normalizeName(?string $name): string
    {
        $normalized = strtolower(trim((string) $name));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
    }

    /**
     * Kunci kelompok Inventory untuk sebuah nama cabang, atau null bila
     * cabang tersebut bukan cabang Inventory.
     */
    public static function canonicalKeyForName(?string $name): ?string
    {
        $normalized = self::normalizeName($name);

        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, self::EXPRESS)) {
            return self::EXPRESS;
        }

        if (str_contains($normalized, self::GOLIO)) {
            return self::GOLIO;
        }

        if (str_contains($normalized, self::JANUS)) {
            return self::JANUS;
        }

        return null;
    }

    /**
     * Seluruh cabang (opsional termasuk yang soft-deleted) dalam bentuk
     * [cabang_id => nama_cabang], terurut berdasarkan cabang_id.
     *
     * @return array<string, string>
     */
    public static function branchNames(bool $withTrashed = false): array
    {
        $query = $withTrashed ? Cabang::withTrashed() : Cabang::query();

        return $query->orderBy('cabang_id')->pluck('nama_cabang', 'cabang_id')->all();
    }

    /**
     * Pengelompokan cabang: [kunci kanonis => daftar cabang_id].
     *
     * @return array<string, array<int, string>>
     */
    public static function groups(bool $withTrashed = false): array
    {
        $groups = [];

        foreach (self::branchNames($withTrashed) as $cabangId => $namaCabang) {
            $canonical = self::canonicalKeyForName($namaCabang);

            if ($canonical === null) {
                continue;
            }

            $groups[$canonical][] = (string) $cabangId;
        }

        $result = [];

        foreach (self::ORDER as $canonical) {
            if (! isset($groups[$canonical])) {
                continue;
            }

            $members = array_values(array_unique($groups[$canonical]));
            sort($members);
            $result[$canonical] = $members;
        }

        return $result;
    }

    /**
     * ID cabang utama (aktif) untuk sebuah kelompok. Prioritas:
     * nama persis, lalu nama yang sudah dikenal, lalu ID terkecil.
     */
    public static function canonicalCabangId(string $canonical): ?string
    {
        $members = [];

        foreach (self::branchNames() as $cabangId => $namaCabang) {
            if (self::canonicalKeyForName($namaCabang) === $canonical) {
                $members[(string) $cabangId] = self::normalizeName($namaCabang);
            }
        }

        if ($members === []) {
            return null;
        }

        if (count($members) === 1) {
            return (string) array_key_first($members);
        }

        $priorities = match ($canonical) {
            self::EXPRESS => ['express', 'photomate express', 'express sharing profit'],
            self::GOLIO => ['golio'],
            self::JANUS => ['newspaper janus', 'janus'],
            default => [],
        };

        foreach ($priorities as $wanted) {
            foreach ($members as $cabangId => $normalized) {
                if ($normalized === $wanted) {
                    return (string) $cabangId;
                }
            }
        }

        ksort($members);

        return (string) array_key_first($members);
    }

    /**
     * ID kanonis untuk setiap kelompok yang memiliki cabang aktif.
     *
     * @return array<string, string> [kunci kanonis => cabang_id]
     */
    public static function canonicalIds(): array
    {
        $ids = [];

        foreach (self::ORDER as $canonical) {
            $cabangId = self::canonicalCabangId($canonical);

            if ($cabangId !== null) {
                $ids[$canonical] = $cabangId;
            }
        }

        return $ids;
    }

    /**
     * Kelompok baca: mencakup cabang aktif dan soft-deleted agar data
     * historis tetap terpetakan. Setiap kelompok memakai ID kanonis aktif
     * bila tersedia.
     *
     * @return array<string, array{cabang_id: string, members: array<int, string>}>
     */
    public static function readGroups(): array
    {
        $result = [];

        foreach (self::groups(true) as $canonical => $members) {
            $canonicalId = self::canonicalCabangId($canonical);

            if ($canonicalId === null || ! in_array($canonicalId, $members, true)) {
                $canonicalId = $members[0];
            }

            $result[$canonical] = [
                'cabang_id' => (string) $canonicalId,
                'members' => $members,
            ];
        }

        return $result;
    }

    /**
     * Opsi dropdown cabang Inventory: hanya tiga cabang utama.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::ORDER as $canonical) {
            $cabangId = self::canonicalCabangId($canonical);

            if ($cabangId !== null) {
                $options[$cabangId] = self::LABELS[$canonical];
            }
        }

        return $options;
    }

    /**
     * Kunci kelompok untuk sebuah cabang_id (termasuk soft-deleted).
     */
    public static function canonicalKeyForCabangId(?string $cabangId): ?string
    {
        if (blank($cabangId)) {
            return null;
        }

        $namaCabang = Cabang::query()->whereKey($cabangId)->value('nama_cabang')
            ?? Cabang::withTrashed()->whereKey($cabangId)->value('nama_cabang');

        return self::canonicalKeyForName($namaCabang);
    }

    /**
     * ID kanonis yang dipakai query baca untuk sebuah nilai filter.
     * Nilai non-Inventory dikembalikan apa adanya.
     */
    public static function filterCanonicalId(?string $cabangId): ?string
    {
        if (blank($cabangId)) {
            return null;
        }

        $canonical = self::canonicalKeyForCabangId($cabangId);

        if ($canonical === null) {
            return (string) $cabangId;
        }

        return self::readGroups()[$canonical]['cabang_id'] ?? (string) $cabangId;
    }

    /**
     * Kembangkan filter cabang menjadi seluruh ID anggota kelompoknya
     * (termasuk soft-deleted) untuk query riwayat transaksi.
     *
     * @return array<int, string>|null Null berarti tanpa filter.
     */
    public static function expandCabangIds(?string $cabangId): ?array
    {
        if (blank($cabangId)) {
            return null;
        }

        $canonical = self::canonicalKeyForCabangId($cabangId);

        if ($canonical === null) {
            return [(string) $cabangId];
        }

        $members = self::groups(true)[$canonical] ?? [(string) $cabangId];

        return array_values(array_unique($members));
    }

    /**
     * Normalisasi cabang untuk penulisan baru: seluruh anggota Express
     * (termasuk ID lama seperti Express Sewa/Self Run) ditulis ke ID
     * kanonis Express yang aktif. Cabang non-Inventory dikembalikan
     * apa adanya agar validasi dapat menolaknya secara eksplisit.
     */
    public static function normalizeCabangIdForWrite(?string $cabangId): ?string
    {
        if (blank($cabangId)) {
            return $cabangId;
        }

        $canonical = self::canonicalKeyForCabangId($cabangId);

        if ($canonical === null) {
            return (string) $cabangId;
        }

        return self::canonicalCabangId($canonical) ?? (string) $cabangId;
    }

    /**
     * Label cabang untuk tampilan Inventory.
     */
    public static function labelForCabangId(?string $cabangId, ?string $fallbackName = null): string
    {
        if (blank($cabangId)) {
            return $fallbackName ?? '-';
        }

        if (filled($fallbackName)) {
            $canonical = self::canonicalKeyForName($fallbackName);

            return $canonical !== null
                ? self::LABELS[$canonical]
                : (string) $fallbackName;
        }

        $canonical = self::canonicalKeyForCabangId($cabangId);

        if ($canonical !== null) {
            return self::LABELS[$canonical];
        }

        return (string) (Cabang::withTrashed()->whereKey($cabangId)->value('nama_cabang') ?? '-');
    }

    /**
     * Seluruh ID cabang Inventory (opsional termasuk soft-deleted).
     *
     * @return array<int, string>
     */
    public static function allMemberCabangIds(bool $withTrashed = false): array
    {
        $ids = [];

        foreach (self::groups($withTrashed) as $members) {
            $ids = array_merge($ids, $members);
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
