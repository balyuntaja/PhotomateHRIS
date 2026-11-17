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

        $isNegative = str_starts_with($normalized, '-');

        // Buang simbol mata uang dan karakter lain: "Rp 1.500.000" → "1.500.000"
        $normalized = preg_replace('/[^0-9,.-]/', '', $normalized) ?? '';

        if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === ',') {
            return 0.0;
        }

        if (str_starts_with($normalized, '-')) {
            $isNegative = true;
            $normalized = ltrim($normalized, '-');
        }

        $hasComma = str_contains($normalized, ',');
        $hasDot = str_contains($normalized, '.');

        if ($hasComma && $hasDot) {
            $lastComma = strrpos($normalized, ',');
            $lastDot = strrpos($normalized, '.');
            if ($lastComma > $lastDot) {
                // Format Indonesia: 2.199.000,50 -> titik = ribuan, koma = desimal
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                // Format US: 2,199,000.50 -> koma = ribuan, titik = desimal
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($hasComma) {
            $parts = explode(',', $normalized);
            $lastPart = end($parts);
            $firstPart = reset($parts);
            if (strlen($lastPart) !== 3) {
                // Koma desimal: "100,50" -> 100.50
                $decimal = array_pop($parts);
                $normalized = implode('', $parts) . '.' . $decimal;
            } elseif (count($parts) === 2 && strlen($firstPart) > 3) {
                // "2199000,000" -> koma desimal
                $normalized = $firstPart . '.' . $lastPart;
            } else {
                // Pemisah ribuan koma (US): "2,199,000"
                $normalized = implode('', $parts);
            }
        } elseif ($hasDot) {
            $parts = explode('.', $normalized);
            $lastPart = end($parts);
            $firstPart = reset($parts);
            if (strlen($lastPart) !== 3) {
                // Titik desimal (mis. nilai database "2199000.00", "30000.00", "150.50", "0.00")
                $decimal = array_pop($parts);
                $normalized = implode('', $parts) . '.' . $decimal;
            } elseif (count($parts) === 2 && strlen($firstPart) > 3) {
                // Titik setelah lebih dari 3 digit: "2199000.000" -> titik desimal
                $normalized = $firstPart . '.' . $lastPart;
            } else {
                // Format Indonesia: titik = ribuan ("30.000", "150.000", "1.000.000", "2.199.000", "15.000.000")
                $normalized = implode('', $parts);
            }
        }

        $num = is_numeric($normalized) ? (float) $normalized : 0.0;
        return $isNegative ? -$num : $num;
    }

    /**
     * Normalisasi nilai menjadi integer rupiah bersih tanpa desimal.
     */
    public static function toInt(mixed $value): int
    {
        return (int) round(self::toFloat($value));
    }
}
