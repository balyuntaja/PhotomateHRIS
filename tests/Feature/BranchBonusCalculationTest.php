<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Role;
use App\Models\SessionReport;
use App\Models\SessionTransaction;
use App\Services\BranchBonusService;
use App\Services\PricingService;
use App\Services\SessionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchBonusCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected const DATE = '2026-10-06';

    protected Cabang $janus;

    protected Cabang $golio;

    protected Karyawan $kia;

    protected Karyawan $shasha;

    protected Karyawan $bima;

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

        $this->janus = $this->makeCabang('C0006', 'Newspaper Janus');
        $this->golio = $this->makeCabang('C0009', 'Golio');

        $this->kia = $this->makeKaryawan('K0002', 'Kia Uji');
        $this->shasha = $this->makeKaryawan('K0003', 'Shasha Uji');
        $this->bima = $this->makeKaryawan('K0004', 'Bima Uji');
    }

    public function test_skenario_1_total_19_sesi_tanpa_rekap_lain_bonus_nol(): void
    {
        $report = $this->makeReport($this->janus, SessionReport::STATUS_APPROVED, 19, [$this->kia, $this->shasha]);

        $details = app(BranchBonusService::class)->calculateBonusForReport($report);

        $this->assertSame(19, $details['day_total_sessions']);
        $this->assertFalse($details['target_reached']);
        $this->assertSame(0, $details['total_bonus']);
        $this->assertSame(0, $details['bonus_per_crew']);
        $this->assertSame(11, $details['remaining_sessions']);
    }

    public function test_skenario_2_total_29_sesi_belum_mencapai_target(): void
    {
        $report = $this->makeReport($this->janus, SessionReport::STATUS_APPROVED, 29, [$this->kia, $this->shasha]);

        $details = app(BranchBonusService::class)->calculateBonusForReport($report);

        $this->assertSame(29, $details['day_total_sessions']);
        $this->assertFalse($details['target_reached']);
        $this->assertSame(0, $details['total_bonus']);
        $this->assertSame(1, $details['remaining_sessions']);
    }

    public function test_skenario_3_total_tepat_30_sesi_bonus_50000(): void
    {
        $report = $this->makeReport($this->janus, SessionReport::STATUS_APPROVED, 30, [$this->kia]);

        $details = app(BranchBonusService::class)->calculateBonusForReport($report);

        $this->assertSame(30, $details['day_total_sessions']);
        $this->assertTrue($details['target_reached']);
        $this->assertSame(50000, $details['total_bonus']);
        $this->assertSame(50000, $details['bonus_per_crew']);
        $this->assertCount(1, $details['crew_breakdown']);
    }

    public function test_skenario_4_total_36_sesi_dari_beberapa_rekap_dibagi_ke_seluruh_crew(): void
    {
        $first = $this->makeReport($this->janus, SessionReport::STATUS_APPROVED, 20, [$this->kia, $this->shasha]);
        $second = $this->makeReport($this->janus, SessionReport::STATUS_APPROVED, 16, [$this->kia]);

        $service = app(BranchBonusService::class);

        foreach ([$first, $second->fresh()] as $report) {
            $details = $service->calculateBonusForReport($report);

            $this->assertSame(36, $details['day_total_sessions']);
            $this->assertTrue($details['target_reached']);
            $this->assertSame(50000, $details['total_bonus']);
            $this->assertSame(2, $details['crew_count']);
            $this->assertSame(25000, $details['bonus_per_crew']);
            $this->assertSame(50000, array_sum(array_column($details['crew_breakdown'], 'bonus')));
        }
    }

    public function test_skenario_5_rekap_yang_sama_tidak_dihitung_dua_kali(): void
    {
        $first = $this->makeReport($this->janus, SessionReport::STATUS_APPROVED, 20, [$this->kia]);
        $this->makeReport($this->janus, SessionReport::STATUS_APPROVED, 16, [$this->shasha]);

        $details = app(BranchBonusService::class)->calculateBonusForReport($first);

        // 20 + 16 = 36, bukan 20 + 20 + 16 = 56
        $this->assertSame(36, $details['day_total_sessions']);
        $this->assertSame(2, $details['day_report_count']);
        $this->assertCount(1, collect($details['day_reports'])->where('is_current', true));
    }

    public function test_skenario_6_rekap_cabang_lain_tidak_ikut_dihitung(): void
    {
        $janusReport = $this->makeReport($this->janus, SessionReport::STATUS_APPROVED, 19, [$this->kia]);
        $this->makeReport($this->golio, SessionReport::STATUS_APPROVED, 25, [$this->shasha]);

        $details = app(BranchBonusService::class)->calculateBonusForReport($janusReport);

        $this->assertSame(19, $details['day_total_sessions']);
        $this->assertSame(1, $details['day_report_count']);
        $this->assertSame(0, $details['total_bonus']);
    }

    /**
     * Regresi utama: rekap waiting approval / draft / revision tidak boleh
     * menambah total sesi harian (kasus 19 sesi tampil menjadi 36 sesi).
     */
    public function test_skenario_7_rekap_belum_disetujui_tidak_menambah_total_harian(): void
    {
        $approved = $this->makeReport($this->janus, SessionReport::STATUS_APPROVED, 19, [$this->kia, $this->shasha]);
        $this->makeReport($this->janus, SessionReport::STATUS_WAITING_APPROVAL, 17, [$this->bima]);
        $this->makeReport($this->janus, SessionReport::STATUS_DRAFT, 40, [$this->bima]);
        $this->makeReport($this->janus, SessionReport::STATUS_REVISION, 25, [$this->bima]);

        $details = app(BranchBonusService::class)->calculateBonusForReport($approved);

        $this->assertSame(19, $details['day_total_sessions'], 'Rekap yang belum disetujui tidak boleh menambah total harian.');
        $this->assertFalse($details['target_reached']);
        $this->assertSame(0, $details['total_bonus']);
        $this->assertSame(2, $details['crew_count']);
        $this->assertSame(0, $approved->fresh()->bonus_amount);
    }

    public function test_bonus_amount_selaras_pada_seluruh_rekap_hari_yang_sama(): void
    {
        $first = $this->makeReport($this->janus, SessionReport::STATUS_APPROVED, 19, [$this->kia]);
        $second = $this->makeReport($this->janus, SessionReport::STATUS_WAITING_APPROVAL, 17, [$this->shasha]);

        $service = app(BranchBonusService::class);

        $service->syncDailyBonusAmounts($first);
        $this->assertSame(0, $first->fresh()->bonus_amount);

        $second->status = SessionReport::STATUS_APPROVED;
        $second->saveQuietly();

        $service->syncDailyBonusAmounts($second);

        $this->assertSame(50000, $first->fresh()->bonus_amount);
        $this->assertSame(50000, $second->fresh()->bonus_amount);
    }

    public function test_approval_menyelaraskan_bonus_seluruh_rekap_hari_itu(): void
    {
        $supervisor = $this->makeSupervisor();

        $first = $this->makeReportWithTransactions($this->janus, SessionReport::STATUS_APPROVED, 19, [$this->kia]);
        $second = $this->makeReportWithTransactions($this->janus, SessionReport::STATUS_WAITING_APPROVAL, 17, [$this->shasha]);

        $this->assertSame(0, $first->fresh()->bonus_amount);

        app(SessionWorkflowService::class)->approve($second, $supervisor);

        $this->assertSame(SessionReport::STATUS_APPROVED, $second->fresh()->status);
        $this->assertSame(17, $second->fresh()->total_transactions);
        $this->assertSame(36, app(BranchBonusService::class)->calculateBonusForReport($second->fresh())['day_total_sessions']);
        $this->assertSame(50000, $second->fresh()->bonus_amount);
        $this->assertSame(50000, $first->fresh()->bonus_amount);
    }

    protected function makeReport(Cabang $cabang, string $status, int $sessions, array $crews = []): SessionReport
    {
        $report = SessionReport::create([
            'report_date' => self::DATE,
            'cabang_id' => $cabang->cabang_id,
            'status' => $status,
            'total_transactions' => $sessions,
            'total_sessions' => $sessions,
            'grand_total_amount' => $sessions * 30000,
        ]);

        if ($crews !== []) {
            $report->crews()->attach(collect($crews)->pluck('karyawan_id')->all());
        }

        return $report;
    }

    /**
     * @param  array<int, Karyawan>  $crews
     */
    protected function makeReportWithTransactions(Cabang $cabang, string $status, int $sessions, array $crews = []): SessionReport
    {
        $report = $this->makeReport($cabang, $status, 0, $crews);

        for ($i = 0; $i < $sessions; $i++) {
            SessionTransaction::create([
                'session_report_id' => $report->id,
                'payment_method' => 'CASH',
                'session_count' => 1,
                'amount' => 30000,
            ]);
        }

        app(PricingService::class)->recalculateReport($report);

        return $report->fresh();
    }

    protected function makeCabang(string $cabangId, string $nama): Cabang
    {
        return Cabang::create([
            'cabang_id' => $cabangId,
            'perusahaan_id' => 'P0001',
            'nama_cabang' => $nama,
            'alamat' => $nama . ' Outlet',
        ]);
    }

    protected function makeKaryawan(string $karyawanId, string $nama, string $roleId = 'R07'): Karyawan
    {
        Role::firstOrCreate(['role_id' => $roleId], ['name' => 'Crew ' . $roleId, 'guard_name' => 'web']);

        return Karyawan::create([
            'karyawan_id' => $karyawanId,
            'role_id' => $roleId,
            'perusahaan_id' => 'P0001',
            'nik' => str_pad((string) crc32($karyawanId), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => $nama,
            'email' => strtolower($karyawanId) . '@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);
    }

    protected function makeSupervisor(): Karyawan
    {
        return $this->makeKaryawan('K0001', 'Supervisor Uji', 'R08');
    }
}
