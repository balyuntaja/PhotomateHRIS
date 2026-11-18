<?php

namespace Tests\Feature;

use App\Models\Absensi;
use App\Models\Cabang;
use App\Models\Cuti;
use App\Models\EmployeeSchedule;
use App\Models\FinancialCategory;
use App\Models\FinancialParentCategory;
use App\Models\FinancialPaymentMethod;
use App\Models\FinancialTransaction;
use App\Models\Izin;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Role;
use App\Models\SessionReport;
use App\Services\KeuanganService;
use App\Utils\RupiahHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['role_id' => 'R01'], ['name' => 'Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['role_id' => 'R07'], ['name' => 'Karyawan', 'guard_name' => 'web']);
    }

    public function test_dashboard_menampilkan_ringkasan_dari_data_aktual(): void
    {
        $perusahaan = Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        $cabang = Cabang::create([
            'cabang_id' => 'C0001',
            'perusahaan_id' => $perusahaan->perusahaan_id,
            'nama_cabang' => 'Golio',
            'alamat' => 'Golio Booth',
        ]);

        $admin = $this->makeUser('K0001', 'Admin Photomate', 'R01', 'admin@photomate.id');
        $crew = $this->makeUser('K0002', 'Crew Golio', 'R07', 'crew@photomate.id');

        $report = SessionReport::create([
            'report_date' => now()->toDateString(),
            'status' => SessionReport::STATUS_APPROVED,
            'submitted_by' => $crew->karyawan_id,
            'approved_by' => $admin->karyawan_id,
            'approved_at' => now(),
            'total_sessions' => 5,
            'total_transactions' => 2,
            'total_cash_amount' => 50000,
            'total_cash_sessions' => 2,
            'total_cash_transactions' => 1,
            'total_qris_amount' => 100000,
            'total_qris_sessions' => 3,
            'total_qris_transactions' => 1,
            'grand_total_amount' => 150000,
        ]);
        $report->crews()->attach($crew->karyawan_id);

        $this->createBoothIncomeTransaction(100000, now()->toDateString(), $cabang->cabang_id, 'QRIS', 'session_report', $report->id . '-QRIS');
        $this->createBoothIncomeTransaction(50000, now()->toDateString(), $cabang->cabang_id, 'Cash', 'session_report', $report->id . '-CASH');

        EmployeeSchedule::create([
            'karyawan_id' => $crew->karyawan_id,
            'employee_name' => $crew->nama_lengkap,
            'shift_date' => now()->toDateString(),
            'shift_type' => 'morning',
            'booth_location' => 'Golio Booth',
        ]);

        Cuti::create([
            'cuti_id' => 'CT0001',
            'karyawan_id' => $crew->karyawan_id,
            'jenis_cuti' => 'Cuti Tahunan',
            'tanggal_mulai' => now()->toDateString(),
            'tanggal_selesai' => now()->toDateString(),
            'keterangan' => 'Keperluan keluarga',
            'status_cuti' => 'Disetujui',
        ]);

        Izin::create([
            'izin_id' => 'IZ0001',
            'karyawan_id' => $crew->karyawan_id,
            'jenis_izin' => 'Izin Sakit',
            'tanggal_mulai' => now()->toDateString(),
            'tanggal_selesai' => now()->toDateString(),
            'keterangan' => 'Demam',
            'status_izin' => 'Disetujui',
        ]);

        Absensi::create([
            'absensi_id' => 'AB000001',
            'karyawan_id' => $crew->karyawan_id,
            'cabang_id' => $cabang->cabang_id,
            'tanggal' => now()->toDateString(),
            'waktu_masuk' => now()->setTime(8, 0),
            'koordinat_masuk' => '-7.9,112.6',
            'foto_masuk' => 'absensi/masuk.jpg',
            'status_absensi' => 'Hadir',
        ]);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('css/filament/admin/theme.css')
            ->assertSee('Ringkasan Bisnis')
            ->assertSee('Total Pendapatan')
            ->assertSee(RupiahHelper::format(150000))
            ->assertSee('Ringkasan Pembayaran')
            ->assertSee('Status Operasional')
            ->assertSee('Perlu Perhatian')
            ->assertSee('Aktivitas Hari Ini')
            ->assertSee('Jadwal Karyawan')
            ->assertSee('Karyawan Cuti')
            ->assertSee('Karyawan Izin')
            ->assertSee($crew->nama_lengkap);
    }

    public function test_crew_hanya_melihat_rekap_revisinya_sendiri_di_perlu_perhatian(): void
    {
        $perusahaan = Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        Cabang::create([
            'cabang_id' => 'C0001',
            'perusahaan_id' => $perusahaan->perusahaan_id,
            'nama_cabang' => 'Golio',
            'alamat' => 'Golio Booth',
        ]);

        $crew = $this->makeUser('K0002', 'Crew Golio', 'R07', 'crew@photomate.id');
        $otherCrew = $this->makeUser('K0003', 'Crew Lain', 'R07', 'crew.lain@photomate.id');

        $ownRevision = SessionReport::create([
            'report_date' => now()->toDateString(),
            'status' => SessionReport::STATUS_REVISION,
            'submitted_by' => $crew->karyawan_id,
            'revision_note' => 'Perbaiki nominal QRIS.',
        ]);
        $ownRevision->crews()->attach($crew->karyawan_id);

        $otherRevision = SessionReport::create([
            'report_date' => now()->toDateString(),
            'status' => SessionReport::STATUS_REVISION,
            'submitted_by' => $otherCrew->karyawan_id,
            'revision_note' => 'Perbaiki jumlah sesi.',
        ]);
        $otherRevision->crews()->attach($otherCrew->karyawan_id);

        $this->actingAs($crew)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Perlu Perhatian')
            ->assertSee($ownRevision->report_number)
            ->assertDontSee($otherRevision->report_number);
    }

    public function test_dashboard_mengakumulasi_seluruh_cabang_secara_dinamis(): void
    {
        $perusahaan = Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        $admin = $this->makeUser('K0001', 'Admin Photomate', 'R01', 'admin@photomate.id');
        $crew1 = $this->makeUser('K0002', 'Crew Janus', 'R07', 'crew1@photomate.id');
        $crew2 = $this->makeUser('K0003', 'Crew Golio', 'R07', 'crew2@photomate.id');
        $crew3 = $this->makeUser('K0004', 'Crew Express', 'R07', 'crew3@photomate.id');
        $crew4 = $this->makeUser('K0005', 'Crew Surabaya', 'R07', 'crew4@photomate.id');

        // Daftarkan cabang: Janus, Golio, Photomate Express, dan cabang dinamis (Surabaya)
        $cabangJanus = Cabang::create([
            'cabang_id' => 'C0001',
            'perusahaan_id' => $perusahaan->perusahaan_id,
            'nama_cabang' => 'Newspaper Janus',
            'alamat' => 'Janus Coffee, Malang',
        ]);

        $cabangGolio = Cabang::create([
            'cabang_id' => 'C0002',
            'perusahaan_id' => $perusahaan->perusahaan_id,
            'nama_cabang' => 'Golio',
            'alamat' => 'Golio Booth',
        ]);

        $cabangExpress = Cabang::create([
            'cabang_id' => 'C0003',
            'perusahaan_id' => $perusahaan->perusahaan_id,
            'nama_cabang' => 'Photomate Express',
            'alamat' => 'Express Booth',
        ]);

        $cabangSurabaya = Cabang::create([
            'cabang_id' => 'C0004',
            'perusahaan_id' => $perusahaan->perusahaan_id,
            'nama_cabang' => 'Photomate Surabaya',
            'alamat' => 'Surabaya Mall',
        ]);

        // Rekap Janus Approved: 10 sesi, Rp 300.000 (QRIS: Rp 200.000, Tunai: Rp 100.000)
        $reportJanus = SessionReport::create([
            'report_date' => now()->toDateString(),
            'cabang_id' => $cabangJanus->cabang_id,
            'status' => SessionReport::STATUS_APPROVED,
            'submitted_by' => $crew1->karyawan_id,
            'approved_by' => $admin->karyawan_id,
            'approved_at' => now(),
            'total_sessions' => 10,
            'total_transactions' => 4,
            'total_cash_amount' => 100000,
            'total_cash_sessions' => 2,
            'total_cash_transactions' => 2,
            'total_qris_amount' => 200000,
            'total_qris_sessions' => 8,
            'total_qris_transactions' => 2,
            'grand_total_amount' => 300000,
        ]);
        $reportJanus->crews()->attach($crew1->karyawan_id);

        // Rekap Golio Approved: 15 sesi, Rp 450.000 (QRIS: Rp 300.000, Tunai: Rp 150.000)
        $reportGolio = SessionReport::create([
            'report_date' => now()->toDateString(),
            'cabang_id' => $cabangGolio->cabang_id,
            'status' => SessionReport::STATUS_APPROVED,
            'submitted_by' => $crew2->karyawan_id,
            'approved_by' => $admin->karyawan_id,
            'approved_at' => now(),
            'total_sessions' => 15,
            'total_transactions' => 5,
            'total_cash_amount' => 150000,
            'total_cash_sessions' => 3,
            'total_cash_transactions' => 2,
            'total_qris_amount' => 300000,
            'total_qris_sessions' => 12,
            'total_qris_transactions' => 3,
            'grand_total_amount' => 450000,
        ]);
        $reportGolio->crews()->attach($crew2->karyawan_id);

        // Rekap Photomate Express Approved: 20 sesi, Rp 600.000 (QRIS: Rp 400.000, Tunai: Rp 200.000)
        $reportExpress = SessionReport::create([
            'report_date' => now()->toDateString(),
            'cabang_id' => $cabangExpress->cabang_id,
            'status' => SessionReport::STATUS_APPROVED,
            'submitted_by' => $crew3->karyawan_id,
            'approved_by' => $admin->karyawan_id,
            'approved_at' => now(),
            'total_sessions' => 20,
            'total_transactions' => 6,
            'total_cash_amount' => 200000,
            'total_cash_sessions' => 4,
            'total_cash_transactions' => 2,
            'total_qris_amount' => 400000,
            'total_qris_sessions' => 16,
            'total_qris_transactions' => 4,
            'grand_total_amount' => 600000,
        ]);
        $reportExpress->crews()->attach($crew3->karyawan_id);

        // Rekap Cabang Surabaya Approved: 5 sesi, Rp 200.000 (QRIS: Rp 50.000, Tunai: Rp 50.000, Transfer/Lainnya: Rp 100.000)
        $reportSurabaya = SessionReport::create([
            'report_date' => now()->toDateString(),
            'cabang_id' => $cabangSurabaya->cabang_id,
            'status' => SessionReport::STATUS_APPROVED,
            'submitted_by' => $crew4->karyawan_id,
            'approved_by' => $admin->karyawan_id,
            'approved_at' => now(),
            'total_sessions' => 5,
            'total_transactions' => 3,
            'total_cash_amount' => 50000,
            'total_cash_sessions' => 1,
            'total_cash_transactions' => 1,
            'total_qris_amount' => 50000,
            'total_qris_sessions' => 1,
            'total_qris_transactions' => 1,
            'grand_total_amount' => 200000,
        ]);
        $reportSurabaya->crews()->attach($crew4->karyawan_id);

        // Tambahkan session_transaction metode TRANSFER untuk menguji metode pembayaran lain
        \App\Models\SessionTransaction::create([
            'session_report_id' => $reportSurabaya->id,
            'payment_method' => 'TRANSFER',
            'session_count' => 3,
            'amount' => 100000,
            'notes' => 'Transfer BCA Pelanggan',
        ]);

        // Transaksi Keuangan kategori Pendapatan Booth untuk masing-masing cabang:
        // Janus: QRIS Rp 200.000, Tunai Rp 100.000
        $this->createBoothIncomeTransaction(200000, now()->toDateString(), $cabangJanus->cabang_id, 'QRIS', 'session_report', $reportJanus->id . '-QRIS');
        $this->createBoothIncomeTransaction(100000, now()->toDateString(), $cabangJanus->cabang_id, 'Cash', 'session_report', $reportJanus->id . '-CASH');

        // Golio: QRIS Rp 300.000, Tunai Rp 150.000
        $this->createBoothIncomeTransaction(300000, now()->toDateString(), $cabangGolio->cabang_id, 'QRIS', 'session_report', $reportGolio->id . '-QRIS');
        $this->createBoothIncomeTransaction(150000, now()->toDateString(), $cabangGolio->cabang_id, 'Cash', 'session_report', $reportGolio->id . '-CASH');

        // Photomate Express: QRIS Rp 400.000, Tunai Rp 200.000
        $this->createBoothIncomeTransaction(400000, now()->toDateString(), $cabangExpress->cabang_id, 'QRIS', 'session_report', $reportExpress->id . '-QRIS');
        $this->createBoothIncomeTransaction(200000, now()->toDateString(), $cabangExpress->cabang_id, 'Cash', 'session_report', $reportExpress->id . '-CASH');

        // Surabaya: QRIS Rp 50.000, Tunai Rp 50.000, Transfer Rp 100.000
        $this->createBoothIncomeTransaction(50000, now()->toDateString(), $cabangSurabaya->cabang_id, 'QRIS', 'session_report', $reportSurabaya->id . '-QRIS');
        $this->createBoothIncomeTransaction(50000, now()->toDateString(), $cabangSurabaya->cabang_id, 'Cash', 'session_report', $reportSurabaya->id . '-CASH');
        $this->createBoothIncomeTransaction(100000, now()->toDateString(), $cabangSurabaya->cabang_id, 'Transfer', 'session_report', $reportSurabaya->id . '-TRANSFER');

        // Rekap DRAFT tidak boleh masuk ke pendapatan dan sesi approved
        $reportDraft = SessionReport::create([
            'report_date' => now()->toDateString(),
            'cabang_id' => $cabangJanus->cabang_id,
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $crew1->karyawan_id,
            'total_sessions' => 50,
            'total_transactions' => 10,
            'grand_total_amount' => 1500000,
        ]);
        $reportDraft->crews()->attach($crew1->karyawan_id);

        // Total pendapatan yang diharapkan: 300.000 + 450.000 + 600.000 + 200.000 = Rp 1.550.000
        $expectedTotalRevenue = 1550000;
        // Total sesi yang diharapkan: 10 + 15 + 20 + 5 = 50 sesi
        $expectedTotalSessions = 50;
        // Total QRIS yang diharapkan: 200.000 + 300.000 + 400.000 + 50.000 = Rp 950.000
        $expectedQrisRevenue = 950000;
        // Total Tunai yang diharapkan: 100.000 + 150.000 + 200.000 + 50.000 = Rp 500.000
        $expectedCashRevenue = 500000;
        // Total Metode Lainnya: Rp 100.000
        $expectedOtherRevenue = 100000;

        $response = $this->actingAs($admin)
            ->get('/admin')
            ->assertOk();

        // 1. Verifikasi Ringkasan Bisnis gabungan seluruh cabang
        $response->assertSee('Ringkasan Bisnis')
            ->assertSee('Total Pendapatan')
            ->assertSee(RupiahHelper::format($expectedTotalRevenue))
            ->assertSee('Total Sesi')
            ->assertSee(number_format($expectedTotalSessions, 0, ',', '.'))
            ->assertSee('Karyawan')
            ->assertSee('5')
            ->assertSee('Cabang')
            ->assertSee('4');

        // 2. Verifikasi Ringkasan Pembayaran gabungan seluruh cabang
        $response->assertSee('Ringkasan Pembayaran')
            ->assertSee('QRIS')
            ->assertSee(RupiahHelper::format($expectedQrisRevenue))
            ->assertSee('Tunai')
            ->assertSee(RupiahHelper::format($expectedCashRevenue))
            ->assertSee('Lainnya')
            ->assertSee(RupiahHelper::format($expectedOtherRevenue))
            ->assertSee('Total seluruh pembayaran')
            ->assertSee(RupiahHelper::format($expectedTotalRevenue));

        // 3. Verifikasi widget chart pendapatan 6 bulan terakhir mengagregasi seluruh cabang
        $chartComponent = \Livewire\Livewire::test(\App\Filament\Widgets\DashboardRevenueChart::class);
        $chartData = (new \ReflectionMethod(\App\Filament\Widgets\DashboardRevenueChart::class, 'getData'))
            ->invoke($chartComponent->instance());
        $this->assertNotEmpty($chartData['datasets'][0]['data']);
        $currentMonthRevenue = end($chartData['datasets'][0]['data']);
        $this->assertSame($expectedTotalRevenue, $currentMonthRevenue);
    }

    public function test_dashboard_rekap_belum_approved_tidak_masuk_ke_agregasi_pendapatan_seluruh_cabang(): void
    {
        $perusahaan = Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        $admin = $this->makeUser('K0001', 'Admin Photomate', 'R01', 'admin@photomate.id');
        $crew1 = $this->makeUser('K0002', 'Crew Janus', 'R07', 'crew1@photomate.id');
        $crew2 = $this->makeUser('K0003', 'Crew Express', 'R07', 'crew2@photomate.id');

        $cabangJanus = Cabang::create([
            'cabang_id' => 'C0001',
            'perusahaan_id' => $perusahaan->perusahaan_id,
            'nama_cabang' => 'Newspaper Janus',
            'alamat' => 'Janus Coffee',
        ]);

        $cabangExpress = Cabang::create([
            'cabang_id' => 'C0002',
            'perusahaan_id' => $perusahaan->perusahaan_id,
            'nama_cabang' => 'Photomate Express',
            'alamat' => 'Express Booth',
        ]);

        // Rekap Janus Approved: Rp 100.000, 5 sesi
        $reportJanus = SessionReport::create([
            'report_date' => now()->toDateString(),
            'cabang_id' => $cabangJanus->cabang_id,
            'status' => SessionReport::STATUS_APPROVED,
            'submitted_by' => $crew1->karyawan_id,
            'approved_by' => $admin->karyawan_id,
            'approved_at' => now(),
            'total_sessions' => 5,
            'total_transactions' => 2,
            'total_cash_amount' => 50000,
            'total_qris_amount' => 50000,
            'grand_total_amount' => 100000,
        ]);

        $this->createBoothIncomeTransaction(50000, now()->toDateString(), $cabangJanus->cabang_id, 'QRIS', 'session_report', $reportJanus->id . '-QRIS');
        $this->createBoothIncomeTransaction(50000, now()->toDateString(), $cabangJanus->cabang_id, 'Cash', 'session_report', $reportJanus->id . '-CASH');

        // Rekap Express WAITING_APPROVAL: Rp 500.000 (tidak boleh masuk pendapatan bisnis)
        SessionReport::create([
            'report_date' => now()->toDateString(),
            'cabang_id' => $cabangExpress->cabang_id,
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'submitted_by' => $crew2->karyawan_id,
            'total_sessions' => 25,
            'total_transactions' => 10,
            'total_cash_amount' => 250000,
            'total_qris_amount' => 250000,
            'grand_total_amount' => 500000,
        ]);

        // Rekap Express REVISION: Rp 300.000 (tidak boleh masuk pendapatan bisnis)
        SessionReport::create([
            'report_date' => now()->toDateString(),
            'cabang_id' => $cabangExpress->cabang_id,
            'status' => SessionReport::STATUS_REVISION,
            'submitted_by' => $crew2->karyawan_id,
            'total_sessions' => 15,
            'total_transactions' => 6,
            'total_cash_amount' => 150000,
            'total_qris_amount' => 150000,
            'grand_total_amount' => 300000,
        ]);

        // Rekap Janus DRAFT: Rp 200.000 (tidak boleh masuk pendapatan bisnis)
        SessionReport::create([
            'report_date' => now()->toDateString(),
            'cabang_id' => $cabangJanus->cabang_id,
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $crew1->karyawan_id,
            'total_sessions' => 10,
            'total_transactions' => 4,
            'total_cash_amount' => 100000,
            'total_qris_amount' => 100000,
            'grand_total_amount' => 200000,
        ]);

        $response = $this->actingAs($admin)
            ->get('/admin')
            ->assertOk();

        // Pendapatan bisnis hanya menghitung yang approved (Rp 100.000)
        $response->assertSee('Ringkasan Bisnis')
            ->assertSee(RupiahHelper::format(100000))
            ->assertDontSee(RupiahHelper::format(1100000));

        // Status Operasional menampilkan antrean approval / revisi / draft
        $response->assertSee('Status Operasional')
            ->assertSee('Waiting Approval')
            ->assertSee('Perlu Revisi');
    }

    public function test_dashboard_tren_pendapatan_enam_bulan_mengakumulasi_seluruh_cabang_tanpa_duplikasi(): void
    {
        $perusahaan = Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        $admin = $this->makeUser('K0001', 'Admin Photomate', 'R01', 'admin@photomate.id');
        $crew = $this->makeUser('K0002', 'Crew', 'R07', 'crew@photomate.id');

        $cabangJanus = Cabang::create([
            'cabang_id' => 'C0001',
            'perusahaan_id' => $perusahaan->perusahaan_id,
            'nama_cabang' => 'Newspaper Janus',
            'alamat' => 'Janus Coffee',
        ]);

        $cabangGolio = Cabang::create([
            'cabang_id' => 'C0002',
            'perusahaan_id' => $perusahaan->perusahaan_id,
            'nama_cabang' => 'Golio',
            'alamat' => 'Golio Booth',
        ]);

        $cabangExpress = Cabang::create([
            'cabang_id' => 'C0003',
            'perusahaan_id' => $perusahaan->perusahaan_id,
            'nama_cabang' => 'Photomate Express',
            'alamat' => 'Express Booth',
        ]);

        // Bulan lalu (subMonth): Janus Rp 200.000, Golio Rp 300.000, Express Rp 500.000 -> Total Rp 1.000.000
        $lastMonthDate = now()->subMonthNoOverflow()->startOfMonth()->addDays(5)->toDateString();

        SessionReport::create([
            'report_date' => $lastMonthDate,
            'cabang_id' => $cabangJanus->cabang_id,
            'status' => SessionReport::STATUS_APPROVED,
            'submitted_by' => $crew->karyawan_id,
            'approved_by' => $admin->karyawan_id,
            'approved_at' => now(),
            'total_sessions' => 10,
            'grand_total_amount' => 200000,
        ]);

        SessionReport::create([
            'report_date' => $lastMonthDate,
            'cabang_id' => $cabangGolio->cabang_id,
            'status' => SessionReport::STATUS_APPROVED,
            'submitted_by' => $crew->karyawan_id,
            'approved_by' => $admin->karyawan_id,
            'approved_at' => now(),
            'total_sessions' => 15,
            'grand_total_amount' => 300000,
        ]);

        SessionReport::create([
            'report_date' => $lastMonthDate,
            'cabang_id' => $cabangExpress->cabang_id,
            'status' => SessionReport::STATUS_APPROVED,
            'submitted_by' => $crew->karyawan_id,
            'approved_by' => $admin->karyawan_id,
            'approved_at' => now(),
            'total_sessions' => 25,
            'grand_total_amount' => 500000,
        ]);

        $this->createBoothIncomeTransaction(200000, $lastMonthDate, $cabangJanus->cabang_id, 'QRIS', 'session_report', 'chart-janus');
        $this->createBoothIncomeTransaction(300000, $lastMonthDate, $cabangGolio->cabang_id, 'QRIS', 'session_report', 'chart-golio');
        $this->createBoothIncomeTransaction(500000, $lastMonthDate, $cabangExpress->cabang_id, 'QRIS', 'session_report', 'chart-express');

        // Verifikasi chart data bulan lalu bernilai Rp 1.000.000 (gabungan seluruh cabang)
        $chartComponent = \Livewire\Livewire::test(\App\Filament\Widgets\DashboardRevenueChart::class);
        $chartData = (new \ReflectionMethod(\App\Filament\Widgets\DashboardRevenueChart::class, 'getData'))
            ->invoke($chartComponent->instance());

        $this->assertCount(6, $chartData['datasets'][0]['data']);
        // Data indeks ke-4 (bulan lalu, dari 0..5 di mana 5 adalah bulan ini)
        $lastMonthRevenue = $chartData['datasets'][0]['data'][4];
        $this->assertSame(1000000, $lastMonthRevenue);
    }

    public function test_dashboard_pendapatan_konsisten_dengan_dashboard_keuangan_semua_cabang(): void
    {
        $perusahaan = Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        $admin = $this->makeUser('K0001', 'Admin Photomate', 'R01', 'admin@photomate.id');
        $crew = $this->makeUser('K0002', 'Crew', 'R07', 'crew@photomate.id');

        $cabangJanus = Cabang::create([
            'cabang_id' => 'C0001',
            'perusahaan_id' => $perusahaan->perusahaan_id,
            'nama_cabang' => 'Newspaper Janus',
            'alamat' => 'Janus Coffee',
        ]);

        $cabangGolio = Cabang::create([
            'cabang_id' => 'C0002',
            'perusahaan_id' => $perusahaan->perusahaan_id,
            'nama_cabang' => 'Golio',
            'alamat' => 'Golio Booth',
        ]);

        // Rekap sesi dengan nominal Rp 9.999.999 (TIDAK boleh langsung diambil sebagai pendapatan)
        SessionReport::create([
            'report_date' => now()->toDateString(),
            'cabang_id' => $cabangJanus->cabang_id,
            'status' => SessionReport::STATUS_APPROVED,
            'submitted_by' => $crew->karyawan_id,
            'approved_by' => $admin->karyawan_id,
            'approved_at' => now(),
            'total_sessions' => 12,
            'grand_total_amount' => 9999999,
        ]);

        // Transaksi keuangan Pendapatan Booth bulan ini:
        // Janus: Rp 500.000 (QRIS)
        // Golio: Rp 750.000 (Tunai)
        $this->createBoothIncomeTransaction(500000, now()->toDateString(), $cabangJanus->cabang_id, 'QRIS');
        $this->createBoothIncomeTransaction(750000, now()->toDateString(), $cabangGolio->cabang_id, 'Cash');

        // Tambahkan transaksi pengeluaran (tidak boleh masuk ke Total Pendapatan Booth)
        $parentExpense = FinancialParentCategory::firstOrCreate(['name' => 'Persediaan'], ['is_active' => true]);
        $catExpense = FinancialCategory::firstOrCreate(['name' => 'Kertas', 'transaction_type' => 'expense'], ['parent_category_id' => $parentExpense->id, 'is_active' => true]);
        FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 200000,
            'category_id' => $catExpense->id,
            'parent_category_id' => $parentExpense->id,
            'cabang_id' => $cabangJanus->cabang_id,
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => now()->toDateString(),
            'description' => 'Beli kertas',
        ]);

        // 1. Ambil metrics dari Dashboard Utama
        $dashboardPage = new \App\Filament\Pages\Dashboard();
        $metrics = $dashboardPage->getDashboardMetrics();

        // 2. Ambil summary dari KeuanganService dengan filter Semua Cabang ($cabangId = null)
        $keuanganService = app(KeuanganService::class);
        $keuanganSummary = $keuanganService->summary(now()->startOfMonth(), now()->endOfMonth(), null);

        // Pendapatan Dashboard Utama harus tepat Rp 1.250.000 (konsisten dengan Keuangan)
        $this->assertSame(1250000, $metrics['revenue']);
        $this->assertSame(1250000, $keuanganSummary['income']);
        // Pastikan nominal rekap sesi (9.999.999) TIDAK dipakai
        $this->assertNotSame(9999999, $metrics['revenue']);

        // Sesi foto diambil dari rekap sesi approved (12 sesi)
        $this->assertSame(12, $metrics['sessions']);

        // Ringkasan Pembayaran mencerminkan metode dari transaksi keuangan
        $this->assertSame(500000, $metrics['qris_revenue']);
        $this->assertSame(750000, $metrics['cash_revenue']);
        $this->assertSame(0, $metrics['other_revenue']);
        $this->assertSame(2, $metrics['transactions']);
    }

    private function makeUser(string $id, string $name, string $roleId, string $email): Karyawan
    {
        return Karyawan::create([
            'karyawan_id' => $id,
            'role_id' => $roleId,
            'perusahaan_id' => 'P0001',
            'nik' => str_pad((string) crc32($id), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);
    }

    private function createBoothIncomeTransaction(
        int $amount,
        string $date,
        string $cabangId,
        ?string $paymentMethodName = null,
        ?string $sourceType = null,
        ?string $sourceId = null
    ): FinancialTransaction {
        $parent = FinancialParentCategory::firstOrCreate(
            ['name' => FinancialParentCategory::NAME_INCOME],
            ['is_active' => true]
        );

        $category = FinancialCategory::firstOrCreate(
            ['name' => 'Pendapatan Booth', 'transaction_type' => 'income'],
            ['parent_category_id' => $parent->id, 'is_active' => true]
        );

        $paymentMethodId = null;
        if ($paymentMethodName) {
            $paymentMethod = FinancialPaymentMethod::firstOrCreate(
                ['name' => $paymentMethodName],
                ['is_active' => true]
            );
            $paymentMethodId = $paymentMethod->id;
        }

        return FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => $amount,
            'category_id' => $category->id,
            'parent_category_id' => $parent->id,
            'cabang_id' => $cabangId,
            'payment_method_id' => $paymentMethodId,
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => $date,
            'period_year' => (int) \Illuminate\Support\Carbon::parse($date)->year,
            'period_month' => (int) \Illuminate\Support\Carbon::parse($date)->month,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'description' => 'Pendapatan booth',
        ]);
    }
}
