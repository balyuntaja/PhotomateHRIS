<?php

namespace Tests\Feature;

use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Services\KeuanganService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialTransactionSubmitterTest extends TestCase
{
    use RefreshDatabase;

    protected FinancialCategory $incomeCategory;

    protected function setUp(): void
    {
        parent::setUp();

        Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        $this->incomeCategory = FinancialCategory::where('name', 'Pelunasan')
            ->where('transaction_type', FinancialCategory::TYPE_INCOME)
            ->firstOrFail();
    }

    public function test_transaksi_lama_manual_memakai_nama_pembuat_transaksi(): void
    {
        $creator = $this->makeKaryawan('K0001', 'Pembuat Manual');

        $transaction = $this->makeTransaction([
            'created_by' => $creator->karyawan_id,
        ]);

        $this->assertNull($transaction->submitted_by);
        $this->assertSame('Pembuat Manual', $transaction->submitted_by_label);
    }

    public function test_transaksi_otomatis_lama_memakai_nama_pembuat_transaksi(): void
    {
        $creator = $this->makeKaryawan('K0002', 'Pembuat Otomatis');

        $transaction = $this->makeTransaction([
            'source_type' => 'invoice',
            'source_id' => '1',
            'created_by' => $creator->karyawan_id,
        ]);

        $this->assertNull($transaction->submitted_by);
        $this->assertSame('Pembuat Otomatis', $transaction->submitted_by_label);
    }

    public function test_pembuat_transaksi_soft_deleted_tetap_tampil_namanya(): void
    {
        $creator = $this->makeKaryawan('K0005', 'Pembuat Terhapus');

        $transaction = $this->makeTransaction([
            'created_by' => $creator->karyawan_id,
        ]);

        $creator->delete();

        $this->assertSame('Pembuat Terhapus', $transaction->fresh()->submitted_by_label);
    }

    public function test_tanpa_user_sama_sekali_menampilkan_strip(): void
    {
        $transaction = $this->makeTransaction();

        $this->assertNull($transaction->submitted_by);
        $this->assertNull($transaction->created_by);
        $this->assertSame('-', $transaction->submitted_by_label);
    }

    public function test_snapshot_nama_dipakai_bila_relasi_submitter_tidak_tersedia(): void
    {
        $transaction = $this->makeTransaction([
            'source_type' => 'invoice',
            'source_id' => '2',
            'submitted_by_name' => 'Nama Snapshot',
        ]);

        $this->assertNull($transaction->submitted_by);
        $this->assertSame('Nama Snapshot', $transaction->submitted_by_label);
    }

    public function test_user_soft_deleted_tetap_tampil_namanya(): void
    {
        $user = $this->makeKaryawan('K0003', 'User Snapshot', 'snapshot@photomate.id');

        $transaction = $this->makeTransaction([
            'source_type' => 'invoice',
            'source_id' => '3',
            'submitted_by' => $user->karyawan_id,
        ]);

        $this->assertSame('User Snapshot', $transaction->submitted_by_name);

        $user->delete();

        $this->assertSame('User Snapshot', $transaction->fresh()->submitted_by_label);
    }

    public function test_export_memakai_akun_dan_disubmit_oleh_tanpa_cabang(): void
    {
        $headers = KeuanganService::EXPORT_HEADERS;

        $this->assertNotContains('Cabang', $headers);
        $this->assertContains('Akun', $headers);
        $this->assertContains('Disubmit Oleh', $headers);

        $creator = $this->makeKaryawan('K0004', 'Pembuat Export');

        $transaction = $this->makeTransaction([
            'created_by' => $creator->karyawan_id,
        ]);
        $row = app(KeuanganService::class)->exportRow($transaction);

        $this->assertSame('Tanpa Akun', $row[array_search('Akun', $headers, true)]);
        $this->assertSame(
            'Pembuat Export',
            $row[array_search('Disubmit Oleh', $headers, true)]
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeTransaction(array $attributes = []): FinancialTransaction
    {
        return FinancialTransaction::create(array_merge([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => 100000,
            'category_id' => $this->incomeCategory->id,
            'transaction_date' => '2026-10-01',
            'description' => 'Transaksi uji submitter',
        ], $attributes));
    }

    protected function makeKaryawan(
        string $karyawanId,
        string $namaLengkap,
        ?string $email = null
    ): Karyawan {
        return Karyawan::create([
            'karyawan_id' => $karyawanId,
            'perusahaan_id' => 'P0001',
            'nik' => str_pad((string) crc32($karyawanId), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => $namaLengkap,
            'email' => $email ?? strtolower($karyawanId) . '@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);
    }
}
