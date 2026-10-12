<?php

namespace App\Utils;

final class RupiahHelper
{
    /**
     * Format tampilan Rupiah: "Rp 1.500.000".
     */
    public static function format(mixed $value): string
    {
        return 'Rp ' . self::number($value);
    }

    /**
     * Angka dengan pemisah ribuan Indonesia tanpa prefix: "1.500.000".
     */
    public static function number(mixed $value): string
    {
        return number_format(self::toFloat($value), 0, ',', '.');
    }

    /**
     * Nominal bertanda: "+ Rp 1.000.000" (pemasukan) / "- Rp 500.000" (pengeluaran).
     */
    public static function signed(mixed $value, bool $isIncome): string
    {
        return ($isIncome ? '+ ' : '- ') . self::format(abs(self::toFloat($value)));
    }

    /**
     * Normalisasi nilai apa pun menjadi angka: null, kosong, dan nilai tidak valid menjadi 0.
     */
    public static function toFloat(mixed $value): float
    {
        if (is_int($value)) {
            return (float) $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : 0.0;
        }

        if (! is_string($value)) {
            return 0.0;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return 0.0;
        }

        if (is_numeric($normalized)) {
            return (float) $normalized;
        }

        // Buang simbol mata uang dan karakter lain: "Rp 1.500.000" → "1.500.000"
        $normalized = preg_replace('/[^0-9,.-]/', '', $normalized) ?? '';

        if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === ',') {
            return 0.0;
        }

        if (str_contains($normalized, ',')) {
            // Format Indonesia: titik = ribuan, koma = desimal.
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace('.', '', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }
}
