<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\SessionReportResource;
use App\Filament\Resources\SessionReportResource\Pages\ListSessionReports;
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
use App\Models\PricingSetting;
use App\Models\Role;
use App\Models\SessionReport;
use App\Services\EmployeeDashboardService;
use App\Utils\RupiahHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Perusahaan $perusahaan;
    protected Cabang $cabangJanus;
    protected Cabang $cabangWedding;
    protected Karyawan $admin;
    protected Karyawan $supervisor;
    protected Karyawan $employee;
    protected Karyawan $otherEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        PricingSetting::firstOrCreate(
            ['is_active' => true],
            [
                'name' => 'Standard Pricing',
                'base_price' => 30000,
                'additional_session_price' => 15000,
                'effective_from' => now()->startOfYear(),
                'is_active' => true,
            ]
        );

        Role::firstOrCreate(['role_id' => 'R01'], ['name' => 'Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['role_id' => 'R07'], ['name' => 'Karyawan', 'guard_name' => 'web']);
        Role::firstOrCreate(['role_id' => 'R08'], ['name' => 'Supervisor', 'guard_name' => 'web']);

        $this->perusahaan = Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        $this->cabangJanus = Cabang::create([
            'cabang_id' => 'C0001',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Newspaper Janus',
        ]);

        $this->cabangWedding = Cabang::create([
            'cabang_id' => 'C0002',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Wedding Photomate',
        ]);

        $this->admin = $this->makeUser('ADM01', 'Admin Photomate', 'R01', 'admin@photomate.id');
        $this->supervisor = $this->makeUser('SPV01', 'Budi Supervisor', 'R08', 'supervisor@photomate.id');
        $this->employee = $this->makeUser('K0002', 'Kia Crew', 'R07', 'kia@photomate.id');
        $this->otherEmployee = $this->makeUser('K0003', 'Shasha Lain', 'R07', 'shasha@photomate.id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_employee_dashboard_hides_business_summary_while_admin_still_sees_it(): void
    {
        $this->createBoothIncomeTransaction(750000, now()->toDateString(), $this->cabangJanus->cabang_id, 'QRIS');
        $this->createBoothIncomeTransaction(250000, now()->toDateString(), $this->cabangJanus->cabang_id, 'Cash');

        // Employee view hides business summary & company-wide totals
        $employeeResponse = $this->actingAs($this->employee)
            ->get('/admin')
            ->assertOk();

        $employeeResponse
            ->assertSee('Workspace Karyawan')
            ->assertSee('Status Operasional')
            ->assertSee('Perlu Perhatian')
            ->assertSee('Informasi Deadline Rekap')
            ->assertSee('Rekap Sesi Terbaru')
            ->assertDontSee('Aktivitas Hari Ini')
            ->assertDontSee('Jadwal Karyawan')
            ->assertDontSee('Karyawan Cuti')
            ->assertDontSee('Karyawan Izin')
            ->assertDontSee('karyawan sudah absen')
            ->assertDontSee('Ringkasan Bisnis')
            ->assertDontSee('Total Pendapatan')
            ->assertDontSee('Tren Pendapatan')
            ->assertDontSee('Ringkasan Pembayaran')
            ->assertDontSee(RupiahHelper::format(1000000));

        // Admin view preserves business summary untouched and also hides Aktivitas Hari Ini
        $this->flushSession();
        $adminResponse = $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk();

        $adminResponse
            ->assertSee('Ringkasan Bisnis')
            ->assertSee('Total Pendapatan')
            ->assertSee('Ringkasan Pembayaran')
            ->assertSee(RupiahHelper::format(1000000))
            ->assertDontSee('Aktivitas Hari Ini')
            ->assertDontSee('Jadwal Karyawan')
            ->assertDontSee('Karyawan Cuti')
            ->assertDontSee('Karyawan Izin')
            ->assertDontSee('karyawan sudah absen');

        // Supervisor view also hides Aktivitas Hari Ini
        $this->flushSession();
        $this->actingAs($this->supervisor)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Ringkasan Bisnis')
            ->assertDontSee('Aktivitas Hari Ini')
            ->assertDontSee('Jadwal Karyawan')
            ->assertDontSee('Karyawan Cuti')
            ->assertDontSee('Karyawan Izin')
            ->assertDontSee('karyawan sudah absen');

        $this->assertFalse(app(EmployeeDashboardService::class)->isEmployeeDashboard($this->supervisor));
    }

    public function test_operational_status_counts_only_own_reports_and_links_with_status_filter(): void
    {
        // Own reports: 1 WAITING_APPROVAL, 2 REVISION, 1 APPROVED, 1 DRAFT
        $this->makeReport($this->employee, SessionReport::STATUS_WAITING_APPROVAL, now()->toDateString(), 'SR-OWN-WAIT');
        $rev1 = $this->makeReport($this->employee, SessionReport::STATUS_REVISION, now()->toDateString(), 'SR-OWN-REV1', 'Perbaiki QRIS.');
        $this->makeReport($this->employee, SessionReport::STATUS_REVISION, now()->subDay()->toDateString(), 'SR-OWN-REV2', 'Lengkapi foto.');
        $this->makeReport($this->employee, SessionReport::STATUS_APPROVED, now()->toDateString(), 'SR-OWN-APP');
        $this->makeReport($this->employee, SessionReport::STATUS_DRAFT, now()->toDateString(), 'SR-OWN-DRF');

        // Other employee reports must NOT be counted
        $this->makeReport($this->otherEmployee, SessionReport::STATUS_WAITING_APPROVAL, now()->toDateString(), 'SR-OTH-WAIT');
        $this->makeReport($this->otherEmployee, SessionReport::STATUS_REVISION, now()->toDateString(), 'SR-OTH-REV');
        $this->makeReport($this->otherEmployee, SessionReport::STATUS_APPROVED, now()->toDateString(), 'SR-OTH-APP');
        $this->makeReport($this->otherEmployee, SessionReport::STATUS_DRAFT, now()->toDateString(), 'SR-OTH-DRF');

        $data = app(EmployeeDashboardService::class)->buildDashboardData($this->employee);
        $statuses = collect($data['operational_statuses'])->keyBy('key');

        $this->assertSame(1, $statuses[SessionReport::STATUS_WAITING_APPROVAL]['count']);
        $this->assertSame(2, $statuses[SessionReport::STATUS_REVISION]['count']);
        $this->assertSame(1, $statuses[SessionReport::STATUS_APPROVED]['count']);
        $this->assertSame(1, $statuses[SessionReport::STATUS_DRAFT]['count']);
        $this->assertTrue($statuses[SessionReport::STATUS_REVISION]['emphasis']);

        // Dashboard renders emphasis class and filtered URLs
        $response = $this->actingAs($this->employee)->get('/admin')->assertOk();
        $response
            ->assertSee('pm-status-card--revision-alert', false)
            ->assertSee('tableFilters%5Bstatus%5D%5Bvalue%5D=REVISION', false)
            ->assertSee('tableFilters%5Bstatus%5D%5Bvalue%5D=WAITING_APPROVAL', false)
            ->assertSee('tableFilters%5Bstatus%5D%5Bvalue%5D=APPROVED', false)
            ->assertSee('tableFilters%5Bstatus%5D%5Bvalue%5D=DRAFT', false);

        // Visiting filtered URL on Rekap Sesi filters properly for that status
        $this->actingAs($this->employee)
            ->get($statuses[SessionReport::STATUS_REVISION]['url'])
            ->assertOk()
            ->assertSee($rev1->report_number);

        // Also verify Livewire table filter directly applies REVISION filter
        Livewire::test(ListSessionReports::class, [
            'tableFilters' => ['status' => ['value' => SessionReport::STATUS_REVISION]],
        ])
            ->assertCanSeeTableRecords([$rev1]);
    }

    public function test_needs_attention_prioritizes_revision_then_approaching_or_overdue_drafts_then_open_drafts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-26 10:00:00', 'Asia/Jakarta')->setTimezone(config('app.timezone')));

        // 1. Open draft (report_date today -> deadline 2026-11-28 23:59:59, > 24h remaining)
        $openDraft = $this->makeReport($this->employee, SessionReport::STATUS_DRAFT, '2026-11-26', 'SR-DRF-OPEN');

        // 2. Approaching deadline draft (report_date 2026-11-24 -> deadline 2026-11-26 23:59:59, ~14h remaining)
        $approachingDraft = $this->makeReport($this->employee, SessionReport::STATUS_DRAFT, '2026-11-24', 'SR-DRF-SOON');

        // 3. Overdue draft (report_date 2026-11-22 -> deadline 2026-11-24 23:59:59, past deadline)
        $overdueDraft = $this->makeReport($this->employee, SessionReport::STATUS_DRAFT, '2026-11-22', 'SR-DRF-LATE');

        // 4. Revision report (highest priority)
        $revisionReport = $this->makeReport(
            $this->employee,
            SessionReport::STATUS_REVISION,
            '2026-11-21',
            'SR-REV-HIGH',
            'Jumlah sesi tunai perlu dicek ulang.'
        );

        // Unrelated employee report must never appear
        $otherRevision = $this->makeReport(
            $this->otherEmployee,
            SessionReport::STATUS_REVISION,
            '2026-11-26',
            'SR-OTH-HIDDEN',
            'Catatan rahasia orang lain.'
        );

        $data = app(EmployeeDashboardService::class)->buildDashboardData($this->employee);
        $items = $data['needs_attention']['items'];

        $this->assertCount(4, $items);
        $this->assertSame([
            $revisionReport->report_number,
            $approachingDraft->report_number,
            $overdueDraft->report_number,
            $openDraft->report_number,
        ], $items->pluck('number')->all());

        $this->assertSame('Perbaiki', $items[0]['action_label']);
        $this->assertSame('Jumlah sesi tunai perlu dicek ulang.', $items[0]['revision_note']);
        $this->assertSame('approaching', $items[1]['deadline_state']);
        $this->assertSame('overdue', $items[2]['deadline_state']);
        $this->assertSame('open', $items[3]['deadline_state']);

        // Render check: highlight class, deadline info, and no cross-employee leakage
        $response = $this->actingAs($this->employee)->get('/admin')->assertOk();
        $response
            ->assertSee('pm-attention-item--revision', false)
            ->assertSee('Perbaiki')
            ->assertSee('Jumlah sesi tunai perlu dicek ulang.')
            ->assertSee('Mendekati Deadline')
            ->assertSee('Melewati Batas')
            ->assertSee('Tanggal Rekap + 2 hari, pukul 23:59:59 WIB')
            ->assertDontSee($otherRevision->report_number)
            ->assertDontSee('Catatan rahasia orang lain.');
    }

    public function test_needs_attention_displays_informative_empty_state_when_no_action_needed(): void
    {
        $this->makeReport($this->employee, SessionReport::STATUS_APPROVED, now()->toDateString(), 'SR-APP-ONLY');

        $this->actingAs($this->employee)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Semua pekerjaan Anda sudah tertangani');
    }

    public function test_recent_reports_limits_to_5_own_reports_and_shows_required_fields(): void
    {
        $created = [];
        for ($i = 1; $i <= 6; $i++) {
            $date = now()->subDays(6 - $i)->toDateString();
            $created[$i] = $this->makeReport(
                $this->employee,
                SessionReport::STATUS_APPROVED,
                $date,
                'SR-REC-0' . $i,
                null,
                $i === 6 ? $this->cabangWedding : $this->cabangJanus,
                $i * 30000
            );
        }

        // Other employee's newer report must never show
        $otherNew = $this->makeReport($this->otherEmployee, SessionReport::STATUS_APPROVED, now()->toDateString(), 'SR-OTH-NEW');

        $response = $this->actingAs($this->employee)->get('/admin')->assertOk();

        $response
            ->assertSee('Rekap Sesi Terbaru')
            ->assertSee('Lihat Semua')
            ->assertSee('Lihat Detail')
            ->assertSee($created[6]->report_number)
            ->assertSee($created[2]->report_number)
            ->assertDontSee($created[1]->report_number)
            ->assertDontSee($otherNew->report_number);
    }

    public function test_today_activities_only_shows_own_data_without_view_any_permissions(): void
    {
        // Own records today
        EmployeeSchedule::create([
            'karyawan_id' => $this->employee->karyawan_id,
            'employee_name' => $this->employee->nama_lengkap,
            'shift_date' => now()->toDateString(),
            'shift_type' => '08:00 - 16:00',
            'booth_location' => 'Newspaper Booth',
        ]);

        Cuti::create([
            'cuti_id' => 'CT0001',
            'karyawan_id' => $this->employee->karyawan_id,
            'jenis_cuti' => 'Cuti Tahunan',
            'tanggal_mulai' => now()->toDateString(),
            'tanggal_selesai' => now()->toDateString(),
            'keterangan' => 'Acara keluarga pribadi',
            'status_cuti' => 'Disetujui',
        ]);

        Izin::create([
            'izin_id' => 'IZ0001',
            'karyawan_id' => $this->employee->karyawan_id,
            'jenis_izin' => 'Izin Datang Terlambat',
            'tanggal_mulai' => now()->toDateString(),
            'tanggal_selesai' => now()->toDateString(),
            'keterangan' => 'Kontrol dokter',
            'status_izin' => 'Disetujui',
        ]);

        Absensi::create([
            'absensi_id' => 'AB000001',
            'karyawan_id' => $this->employee->karyawan_id,
            'cabang_id' => $this->cabangJanus->cabang_id,
            'tanggal' => now()->toDateString(),
            'waktu_masuk' => now()->setTime(8, 15),
            'koordinat_masuk' => '-7.9,112.6',
            'foto_masuk' => 'absensi/kia.jpg',
            'status_absensi' => 'Hadir',
        ]);

        // Other employee records today — MUST NOT leak onto employee dashboard
        EmployeeSchedule::create([
            'karyawan_id' => $this->otherEmployee->karyawan_id,
            'employee_name' => $this->otherEmployee->nama_lengkap,
            'shift_date' => now()->toDateString(),
            'shift_type' => '16:00 - 24:00',
            'booth_location' => 'Event Express',
        ]);

        Cuti::create([
            'cuti_id' => 'CT0002',
            'karyawan_id' => $this->otherEmployee->karyawan_id,
            'jenis_cuti' => 'Cuti Sakit',
            'tanggal_mulai' => now()->toDateString(),
            'tanggal_selesai' => now()->toDateString(),
            'keterangan' => 'Sakit',
            'status_cuti' => 'Disetujui',
        ]);

        Izin::create([
            'izin_id' => 'IZ0002',
            'karyawan_id' => $this->otherEmployee->karyawan_id,
            'jenis_izin' => 'Izin Keperluan Keluarga',
            'tanggal_mulai' => now()->toDateString(),
            'tanggal_selesai' => now()->toDateString(),
            'keterangan' => 'Urusan keluarga lain',
            'status_izin' => 'Disetujui',
        ]);

        $response = $this->actingAs($this->employee)->get('/admin')->assertOk();

        // Section Aktivitas Hari Ini is temporarily hidden from the dashboard UI
        $response
            ->assertDontSee('Aktivitas Hari Ini')
            ->assertDontSee('Jadwal Karyawan')
            ->assertDontSee('Karyawan Cuti')
            ->assertDontSee('Karyawan Izin')
            ->assertDontSee('Sudah absen masuk pukul 08:15')
            ->assertDontSee('08:00 - 16:00')
            ->assertDontSee('Cuti Tahunan')
            ->assertDontSee('Izin Datang Terlambat')
            ->assertDontSee('karyawan sudah absen');

        // Backend data, scoping, models, and HR resources remain intact
        $activities = app(EmployeeDashboardService::class)->buildDashboardData($this->employee)['today_activities'];

        $this->assertSame('Sudah absen masuk pukul 08:15', $activities['attendance_label']);
        $this->assertSame(1, $activities['schedule_count']);
        $this->assertSame('08:00 - 16:00', $activities['schedules']->first()['shift']);
        $this->assertSame(1, $activities['leave_count']);
        $this->assertSame('Cuti Tahunan', $activities['leaves']->first()['type']);
        $this->assertSame(1, $activities['permission_count']);
        $this->assertSame('Izin Datang Terlambat', $activities['permissions']->first()['type']);
        $this->assertStringNotContainsString(
            $this->otherEmployee->nama_lengkap,
            json_encode($activities, JSON_THROW_ON_ERROR)
        );
        $this->assertSame(2, EmployeeSchedule::count());
        $this->assertSame(2, Cuti::count());
        $this->assertSame(2, Izin::count());
        $this->assertSame(1, Absensi::count());
    }

    private function makeUser(string $id, string $name, string $roleId, string $email): Karyawan
    {
        return Karyawan::create([
            'karyawan_id' => $id,
            'role_id' => $roleId,
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nik' => str_pad((string) crc32($id), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1998-01-01',
            'jenis_kelamin' => 'Perempuan',
            'alamat' => 'Malang',
        ]);
    }

    private function makeReport(
        Karyawan $owner,
        string $status,
        string $date,
        string $number,
        ?string $revisionNote = null,
        ?Cabang $cabang = null,
        int $amount = 60000
    ): SessionReport {
        $cabang ??= $this->cabangJanus;

        // Bypass model H+2 boot guard for seeding past-deadline draft test fixtures
        $report = SessionReport::withoutEvents(function () use ($owner, $status, $date, $number, $revisionNote, $cabang, $amount) {
            return SessionReport::create([
                'report_number' => $number,
                'report_date' => $date,
                'cabang_id' => $cabang->cabang_id,
                'status' => $status,
                'submitted_by' => $owner->karyawan_id,
                'revision_note' => $revisionNote,
                'total_transactions' => 2,
                'total_sessions' => 4,
                'total_cash_amount' => $amount,
                'grand_total_amount' => $amount,
                'validation_status' => 'VALID',
            ]);
        });

        $report->crews()->attach([$owner->karyawan_id]);

        return $report->fresh(['cabang', 'crews', 'submitter']);
    }

    private function createBoothIncomeTransaction(
        int $amount,
        string $date,
        string $cabangId,
        string $paymentMethodName
    ): void {
        $parent = FinancialParentCategory::firstOrCreate(
            ['name' => FinancialParentCategory::NAME_INCOME],
            ['is_active' => true]
        );

        $category = FinancialCategory::firstOrCreate(
            ['name' => 'Pendapatan Booth', 'transaction_type' => 'income'],
            ['parent_category_id' => $parent->id, 'is_active' => true]
        );

        $paymentMethod = FinancialPaymentMethod::firstOrCreate(
            ['name' => $paymentMethodName],
            ['is_active' => true]
        );

        FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_INCOME,
            'amount' => $amount,
            'category_id' => $category->id,
            'parent_category_id' => $parent->id,
            'cabang_id' => $cabangId,
            'payment_method_id' => $paymentMethod->id,
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'transaction_date' => $date,
            'period_year' => (int) Carbon::parse($date)->year,
            'period_month' => (int) Carbon::parse($date)->month,
            'description' => 'Pendapatan booth test',
        ]);
    }
}
