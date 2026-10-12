<?php

namespace Tests\Unit;

use App\Utils\RupiahHelper;
use PHPUnit\Framework\TestCase;

class RupiahHelperTest extends TestCase
{
    public function test_format_rupiah_standar_angka_bulat(): void
    {
        $this->assertSame('Rp 1.000', RupiahHelper::format(1000));
        $this->assertSame('Rp 10.000', RupiahHelper::format(10000));
        $this->assertSame('Rp 100.000', RupiahHelper::format(100000));
        $this->assertSame('Rp 1.500.000', RupiahHelper::format(1500000));
        $this->assertSame('Rp 10.000.000', RupiahHelper::format(10000000));
        $this->assertSame('Rp 0', RupiahHelper::format(0));
    }

    public function test_format_rupiah_aman_untuk_nilai_kosong_dan_tidak_valid(): void
    {
        $this->assertSame('Rp 0', RupiahHelper::format(null));
        $this->assertSame('Rp 0', RupiahHelper::format(''));
        $this->assertSame('Rp 0', RupiahHelper::format('abc'));
        $this->assertSame('Rp 0', RupiahHelper::format('Rp'));
        $this->assertSame(0.0, RupiahHelper::toFloat(null));
        $this->assertSame(0.0, RupiahHelper::toFloat('bukan angka'));
    }

    public function test_format_rupiah_membaca_string_berformat_indonesia(): void
    {
        $this->assertSame('Rp 1.500.000', RupiahHelper::format('1.500.000'));
        $this->assertSame('Rp 1.500.000', RupiahHelper::format('Rp 1.500.000'));
        $this->assertSame('Rp 1.500.001', RupiahHelper::format('1.500.000,50'));
        $this->assertSame('Rp 1.500', RupiahHelper::format('1500'));
    }

    public function test_number_tanpa_prefix_dan_signed_bertanda(): void
    {
        $this->assertSame('1.500.000', RupiahHelper::number(1500000));
        $this->assertSame('0', RupiahHelper::number(null));

        $this->assertSame('+ Rp 1.000.000', RupiahHelper::signed(1000000, true));
        $this->assertSame('- Rp 500.000', RupiahHelper::signed(500000, false));
        $this->assertSame('- Rp 500.000', RupiahHelper::signed(-500000, false));
    }
}
