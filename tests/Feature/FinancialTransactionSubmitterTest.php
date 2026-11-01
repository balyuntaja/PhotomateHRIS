<?php

namespace Tests\Feature;

use App\Models\FinancialCategory;
use App\Models\FinancialParty;
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

    public function test_transaksi_manual_menampilkan_pihak_pembayar_bukan_pencatat(): void
    {
        $pencatat = $this->makeKaryawan('K0006', 'Super Admin');
        $aldo = FinancialParty::create([
            'name' => 'Aldo',
            'type' => FinancialParty::TYPE_EMPLOYEE,
            'is_active' => true,
        ]);

        $transaction = $this->makeTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'party_id' => $aldo->id,
            'created_by' => $pencatat->karyawan_id,
            'submitted_by' => $pencatat->karyawan_id,
            'submitted_by_name' => $pencatat->nama_lengkap,
        ]);

        $this->assertSame('Dibayarkan Oleh', $transaction->recording_info_label);
        $this->assertSame('Aldo', $transaction->recording_info_name);
    }

    public function test_transaksi_manual_pemasukan_menampilkan_pihak_penerima(): void
    {
        $penerima = FinancialParty::create([
            'name' => 'Penerima Uji',
            'type' => FinancialParty::TYPE_EMPLOYEE,
            'is_active' => true,
        ]);

        $transaction = $this->makeTransaction([
            'party_id' => $penerima->id,
        ]);

        $this->assertSame('Diterima Oleh', $transaction->recording_info_label);
        $this->assertSame('Penerima Uji', $transaction->recording_info_name);
    }

    public function test_transaksi_manual_tanpa_pihak_tidak_memakai_nama_pencatat(): void
    {
        $pencatat = $this->makeKaryawan('K0007', 'Super Admin');

        $transaction = $this->makeTransaction([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'created_by' => $pencatat->karyawan_id,
            'submitted_by' => $pencatat->karyawan_id,
            'submitted_by_name' => $pencatat->nama_lengkap,
        ]);

        $this->assertSame('Dibayarkan Oleh', $transaction->recording_info_label);
        $this->assertSame('-', $transaction->recording_info_name);
    }

    public function test_transaksi_otomatis_tetap_menampilkan_disubmit_oleh(): void
    {
        $importer = $this->makeKaryawan('K0008', 'Petugas Import');
        $pihak = FinancialParty::create([
            'name' => 'Pihak Tidak Relevan',
            'type' => FinancialParty::TYPE_OTHER,
            'is_active' => true,
        ]);

        $transaction = $this->makeTransaction([
            'source_type' => 'luminash_csv',
            'source_id' => 'ORDER-1',
            'party_id' => $pihak->id,
            'submitted_by' => $importer->karyawan_id,
            'submitted_by_name' => $importer->nama_lengkap,
        ]);

        $this->assertSame('Disubmit Oleh', $transaction->recording_info_label);
        $this->assertSame('Petugas Import', $transaction->recording_info_name);
    }

    public function test_export_memakai_akun_dan_nama_pembayar_tanpa_cabang(): void
    {
        $headers = KeuanganService::EXPORT_HEADERS;

        $this->assertNotContains('Cabang', $headers);
        $this->assertContains('Akun', $headers);
        $this->assertContains('Dibayarkan / Disubmit Oleh', $headers);

        $pembayar = FinancialParty::create([
            'name' => 'Pembayar Export',
            'type' => FinancialParty::TYPE_OTHER,
            'is_active' => true,
        ]);

        $transaction = $this->makeTransaction([
            'party_id' => $pembayar->id,
        ]);
        $row = app(KeuanganService::class)->exportRow($transaction);

        $this->assertSame('Tanpa Akun', $row[array_search('Akun', $headers, true)]);
        $this->assertSame(
            'Pembayar Export',
            $row[array_search('Dibayarkan / Disubmit Oleh', $headers, true)]
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
