<?php

namespace Tests\Feature;

use App\Models\Absensi;
use App\Models\Cabang;
use App\Models\Cuti;
use App\Models\EmployeeSchedule;
use App\Models\Izin;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Role;
use App\Models\SessionReport;
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
}
