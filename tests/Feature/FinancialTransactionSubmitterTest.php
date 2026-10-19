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

    public function test_transaksi_lama_manual_tanpa_submitter_menampilkan_data_lama(): void
    {
        $transaction = $this->makeTransaction();

        $this->assertNull($transaction->submitted_by);
        $this->assertSame(FinancialTransaction::SUBMITTER_LEGACY_LABEL, $transaction->submitted_by_label);
    }

    public function test_transaksi_otomatis_lama_tanpa_submitter_menampilkan_otomatis(): void
    {
        $transaction = $this->makeTransaction([
            'source_type' => 'invoice',
            'source_id' => '1',
        ]);

        $this->assertNull($transaction->submitted_by);
        $this->assertSame(FinancialTransaction::SUBMITTER_SYSTEM_LABEL, $transaction->submitted_by_label);
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
        $user = Karyawan::create([
            'karyawan_id' => 'K0001',
            'perusahaan_id' => 'P0001',
            'nik' => '123456789012',
            'nama_lengkap' => 'User Snapshot',
            'email' => 'snapshot@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);

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

        $transaction = $this->makeTransaction();
        $row = app(KeuanganService::class)->exportRow($transaction);

        $this->assertSame('Tanpa Akun', $row[array_search('Akun', $headers, true)]);
        $this->assertSame(
            FinancialTransaction::SUBMITTER_LEGACY_LABEL,
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
}
