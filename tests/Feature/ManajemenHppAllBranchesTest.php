<?php

namespace Tests\Feature;

use App\Filament\Resources\ExpressEventResource\Pages\ListExpressEvents;
use App\Models\BoothHppAuditLog;
use App\Models\BoothSessionHpp;
use App\Models\BoothSessionHppSetting;
use App\Models\Cabang;
use App\Models\ExpressEvent;
use App\Models\ExpressEventRecap;
use App\Models\ExpressEventRecapAllocation;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialParentCategory;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Models\Permission;
use App\Models\Perusahaan;
use App\Models\Role;
use App\Models\SessionReport;
use App\Services\BoothHppAccountingService;
use App\Services\BoothHppService;
use App\Services\HppDashboardService;
use App\Services\RekapPerbulanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ManajemenHppAllBranchesTest extends TestCase
{
    use RefreshDatabase;

    protected Perusahaan $perusahaan;
    protected Cabang $cabangExpress;
    protected Cabang $cabangJanus;
    protected Cabang $cabangGolio;

    protected Karyawan $superAdmin;
    protected Karyawan $financeUser;
    protected Karyawan $crewUser;

    protected FinancialParentCategory $persediaanCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->perusahaan = Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        $this->cabangExpress = Cabang::create([
            'cabang_id' => 'C0005',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Photomate Express',
            'alamat' => 'Express Station',
        ]);

        $this->cabangJanus = Cabang::create([
            'cabang_id' => 'C0004',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Newspaper Janus',
            'alamat' => 'Janus Coffee Malang',
        ]);

        $this->cabangGolio = Cabang::create([
            'cabang_id' => 'C0006',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Golio',
            'alamat' => 'Golio Booth',
        ]);

        $this->persediaanCategory = FinancialParentCategory::firstOrCreate(
            ['name' => 'Persediaan'],
            ['is_active' => true]
        );

        FinancialAccount::firstOrCreate(['name' => 'Photomate Express'], ['is_active' => true]);
        FinancialAccount::firstOrCreate(['name' => 'Photomate Janus'], ['is_active' => true]);
        FinancialAccount::firstOrCreate(['name' => 'Photomate Golio'], ['is_active' => true]);

        // Setup users & permissions
        $this->superAdmin = $this->createUser('K0001', 'Super Admin', 'R01', ['super_admin']);
        $this->financeUser = $this->createUser('K0002', 'Finance Manager', 'R02', [
            'view_express_events', 'create_express_events', 'update_express_events', 'delete_express_events',
            'allocate_express_recaps', 'confirm_express_hpp', 'manage_express_hpp', 'reopen_express_events',
            'view_booth_hpp', 'manage_booth_hpp', 'confirm_booth_hpp', 'post_booth_hpp', 'reopen_booth_hpp',
            'view_keuangan_dashboard', 'view_keuangan_report',
        ]);
        $this->crewUser = $this->createUser('K0003', 'Staff Regular', 'R03', [
            'view_any_karyawan',
        ]);

        // Default settings
        BoothSessionHppSetting::updateOrCreate(['booth_type' => 'Janus'], ['default_rate_per_sheet' => 2500]);
        BoothSessionHppSetting::updateOrCreate(['booth_type' => 'Golio'], ['default_rate_per_sheet' => 3000]);
    }

    protected function createUser(string $id, string $name, string $roleId, array $permissions): Karyawan
    {
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['role_id' => $roleId]);
        $role->syncPermissions($permissions);

        $user = Karyawan::create([
            'karyawan_id' => $id,
            'role_id' => $roleId,
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nik' => str_pad((string) crc32($id), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);

        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->refresh();
    }

    protected function createApprovedReport(Cabang $cabang, string $date, int $sheets, int $sessions, int $omzet): SessionReport
    {
        return SessionReport::create([
            'report_number' => 'SR-' . strtoupper(substr($cabang->nama_cabang, 0, 3)) . '-' . rand(1000, 9999),
            'report_date' => $date,
            'status' => SessionReport::STATUS_APPROVED,
            'cabang_id' => $cabang->cabang_id,
            'total_sessions' => $sheets,
            'total_transactions' => $sessions,
            'grand_total_amount' => $omzet,
            'approved_at' => now(),
            'approved_by' => $this->superAdmin->karyawan_id,
        ]);
    }

    // =========================================================================
    // 1. SINKRONISASI & PERHITUNGAN LEMBAR DARI REKAP SESI APPROVED
    // =========================================================================

    public function test_1_janus_recap_sheets_calculated_accurately_from_approved_session_report(): void
    {
        $report = $this->createApprovedReport($this->cabangJanus, '2026-11-15', 80, 60, 2000000);

        $service = app(BoothHppService::class);
        $sync = $service->syncApprovedRecaps('Janus');

        $this->assertGreaterThanOrEqual(1, $sync['created']);

        $hppRecord = BoothSessionHpp::where('session_report_id', $report->id)->first();
        $this->assertNotNull($hppRecord);
        $this->assertSame('Janus', $hppRecord->booth_type);
        $this->assertSame(80, $hppRecord->total_sheets);
        $this->assertSame(60, $hppRecord->total_sessions);
        $this->assertSame(2000000.0, (float) $hppRecord->revenue);
        // Default rate Janus adalah 2500 -> total HPP = 80 * 2500 = 200.000
        $this->assertSame(2500.0, (float) $hppRecord->hpp_rate_per_sheet);
        $this->assertSame(200000.0, (float) $hppRecord->total_hpp);
        $this->assertSame(BoothSessionHpp::STATUS_CALCULATED, $hppRecord->status);
    }

    public function test_2_golio_recap_sheets_calculated_accurately_from_approved_session_report(): void
    {
        $report = $this->createApprovedReport($this->cabangGolio, '2026-11-16', 50, 45, 1500000);

        $service = app(BoothHppService::class);
        $service->syncApprovedRecaps('Golio');

        $hppRecord = BoothSessionHpp::where('session_report_id', $report->id)->first();
        $this->assertNotNull($hppRecord);
        $this->assertSame('Golio', $hppRecord->booth_type);
        $this->assertSame(50, $hppRecord->total_sheets);
        $this->assertSame(45, $hppRecord->total_sessions);
        // Default rate Golio adalah 3000 -> total HPP = 50 * 3000 = 150.000
        $this->assertSame(3000.0, (float) $hppRecord->hpp_rate_per_sheet);
        $this->assertSame(150000.0, (float) $hppRecord->total_hpp);
    }

    public function test_3_unapproved_janus_and_golio_recaps_are_not_synced(): void
    {
        $draftReport = SessionReport::create([
            'report_number' => 'SR-JAN-DRAFT',
            'report_date' => '2026-11-15',
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'cabang_id' => $this->cabangJanus->cabang_id,
            'total_sessions' => 100,
            'total_transactions' => 70,
            'grand_total_amount' => 2500000,
        ]);

        $service = app(BoothHppService::class);
        $service->syncApprovedRecaps();

        $this->assertDatabaseMissing('booth_session_hpp', [
            'session_report_id' => $draftReport->id,
        ]);
    }

    // =========================================================================
    // 2. PENENTUAN TARIF HPP & FORMULA (JUMLAH LEMBAR × TARIF PER LEMBAR)
    // =========================================================================

    public function test_4_admin_can_set_tariff_for_janus_and_computes_exact_hpp(): void
    {
        $report = $this->createApprovedReport($this->cabangJanus, '2026-11-17', 120, 90, 3600000);
        $service = app(BoothHppService::class);
        $service->syncApprovedRecaps('Janus');

        $record = BoothSessionHpp::where('session_report_id', $report->id)->firstOrFail();

        // Admin mengubah tarif menjadi Rp 2.800
        $service->setTariff($record, 2800, $this->financeUser, 'Kenaikan tarif kertas foto');

        $record->refresh();
        $this->assertSame(2800.0, (float) $record->hpp_rate_per_sheet);
        // 120 lembar * 2800 = 336.000
        $this->assertSame(336000.0, (float) $record->total_hpp);
        $this->assertSame(BoothSessionHpp::STATUS_CALCULATED, $record->status);

        // Verifikasi audit log
        $this->assertDatabaseHas('booth_hpp_audit_logs', [
            'booth_session_hpp_id' => $record->id,
            'action' => 'rate_updated',
            'user_id' => $this->financeUser->karyawan_id,
        ]);
    }

    public function test_5_admin_can_set_tariff_for_golio_and_computes_exact_hpp(): void
    {
        $report = $this->createApprovedReport($this->cabangGolio, '2026-11-18', 64, 55, 2199000);
        $service = app(BoothHppService::class);
        $service->syncApprovedRecaps('Golio');

        $record = BoothSessionHpp::where('session_report_id', $report->id)->firstOrFail();

        // 64 lembar * 5500 = 352.000
        $service->setTariff($record, 5500, $this->financeUser);

        $record->refresh();
        $this->assertSame(5500.0, (float) $record->hpp_rate_per_sheet);
        $this->assertSame(352000.0, (float) $record->total_hpp);
    }

    public function test_6_negative_tariff_is_rejected(): void
    {
        $report = $this->createApprovedReport($this->cabangJanus, '2026-11-19', 40, 30, 1000000);
        $service = app(BoothHppService::class);
        $service->syncApprovedRecaps('Janus');

        $record = BoothSessionHpp::where('session_report_id', $report->id)->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        $service->setTariff($record, -500, $this->financeUser);
    }

    // =========================================================================
    // 3. KONFIRMASI HPP
    // =========================================================================

    public function test_7_admin_can_confirm_janus_hpp_with_audit_trail(): void
    {
        $report = $this->createApprovedReport($this->cabangJanus, '2026-11-20', 70, 50, 2100000);
        $service = app(BoothHppService::class);
        $service->syncApprovedRecaps('Janus');

        $record = BoothSessionHpp::where('session_report_id', $report->id)->firstOrFail();
        $service->setTariff($record, 2500, $this->financeUser);

        $service->confirmHpp($record, $this->financeUser);

        $record->refresh();
        $this->assertSame(BoothSessionHpp::STATUS_CONFIRMED, $record->status);
        $this->assertSame($this->financeUser->karyawan_id, $record->confirmed_by);
        $this->assertNotNull($record->confirmed_at);

        $this->assertDatabaseHas('booth_hpp_audit_logs', [
            'booth_session_hpp_id' => $record->id,
            'action' => 'confirmed',
            'user_id' => $this->financeUser->karyawan_id,
        ]);
    }

    public function test_8_admin_can_confirm_golio_hpp_with_audit_trail(): void
    {
        $report = $this->createApprovedReport($this->cabangGolio, '2026-11-21', 90, 80, 2700000);
        $service = app(BoothHppService::class);
        $service->syncApprovedRecaps('Golio');

        $record = BoothSessionHpp::where('session_report_id', $report->id)->firstOrFail();
        $service->confirmHpp($record, $this->financeUser);

        $record->refresh();
        $this->assertTrue($record->isConfirmed());
    }

    // =========================================================================
    // 4. INTEGRASI ACCOUNTING & POSTING KE FINANCIAL_TRANSACTIONS
    // =========================================================================

    public function test_9_unconfirmed_hpp_cannot_be_posted_to_accounting(): void
    {
        $report = $this->createApprovedReport($this->cabangJanus, '2026-11-22', 50, 40, 1500000);
        $service = app(BoothHppService::class);
        $service->syncApprovedRecaps('Janus');

        $record = BoothSessionHpp::where('session_report_id', $report->id)->firstOrFail();
        // Record status is calculated, not confirmed

        $accountingService = app(BoothHppAccountingService::class);

        $this->expectException(\RuntimeException::class);
        $accountingService->postHpp($record, $this->financeUser);
    }

    public function test_10_post_janus_hpp_creates_expense_in_persediaan_idempotently(): void
    {
        $report = $this->createApprovedReport($this->cabangJanus, '2026-11-23', 100, 80, 3000000);
        $service = app(BoothHppService::class);
        $service->syncApprovedRecaps('Janus');

        $record = BoothSessionHpp::where('session_report_id', $report->id)->firstOrFail();
        $service->setTariff($record, 2500, $this->financeUser);
        $service->confirmHpp($record, $this->financeUser);

        $accountingService = app(BoothHppAccountingService::class);
        $tx = $accountingService->postHpp($record, $this->financeUser);

        $record->refresh();
        $this->assertSame(BoothSessionHpp::STATUS_POSTED, $record->status);
        $this->assertSame($tx->id, $record->financial_transaction_id);

        $this->assertDatabaseHas('financial_transactions', [
            'id' => $tx->id,
            'source_type' => 'booth_session_hpp',
            'source_id' => $record->id,
            'amount' => 250000, // 100 * 2500
        ]);
        $this->assertSame('2026-11-23', Carbon::parse($tx->transaction_date)->toDateString());

        // Verifikasi kategori parent Persediaan
        $this->assertSame($this->persediaanCategory->id, $tx->category->parent_category_id);
        $this->assertSame('HPP Janus', $tx->category->name);

        // Test Idempotency: posting ulang tidak membuat transaksi baru ganda
        $tx2 = $accountingService->postHpp($record, $this->financeUser);
        $this->assertSame($tx->id, $tx2->id);
        $this->assertSame(1, FinancialTransaction::where('source_type', 'booth_session_hpp')->where('source_id', $record->id)->count());
    }

    public function test_11_post_golio_hpp_creates_expense_in_persediaan_idempotently(): void
    {
        $report = $this->createApprovedReport($this->cabangGolio, '2026-11-24', 80, 70, 2400000);
        $service = app(BoothHppService::class);
        $service->syncApprovedRecaps('Golio');

        $record = BoothSessionHpp::where('session_report_id', $report->id)->firstOrFail();
        $service->setTariff($record, 3000, $this->financeUser);
        $service->confirmHpp($record, $this->financeUser);

        $accountingService = app(BoothHppAccountingService::class);
        $tx = $accountingService->postHpp($record, $this->financeUser);

        $this->assertDatabaseHas('financial_transactions', [
            'id' => $tx->id,
            'source_type' => 'booth_session_hpp',
            'source_id' => $record->id,
            'amount' => 240000, // 80 * 3000
        ]);
        $this->assertSame('2026-11-24', Carbon::parse($tx->transaction_date)->toDateString());
        $this->assertSame('HPP Golio', $tx->category->name);
    }

    public function test_12_reopening_janus_hpp_reverses_accounting_transaction_with_reason(): void
    {
        $report = $this->createApprovedReport($this->cabangJanus, '2026-11-25', 100, 80, 3000000);
        $service = app(BoothHppService::class);
        $service->syncApprovedRecaps('Janus');

        $record = BoothSessionHpp::where('session_report_id', $report->id)->firstOrFail();
        $service->confirmHpp($record, $this->financeUser);

        $accountingService = app(BoothHppAccountingService::class);
        $tx = $accountingService->postHpp($record, $this->financeUser);

        // Reopen dengan alasan
        $service->reopenHpp($record, 'Revisi karena selisih stock kertas', $this->financeUser);

        $record->refresh();
        $this->assertSame(BoothSessionHpp::STATUS_CALCULATED, $record->status);
        $this->assertNull($record->confirmed_by);
        $this->assertNull($record->financial_transaction_id);

        // Transaksi di FinancialTransaction dibatalkan (soft deleted)
        $this->assertSame(0, FinancialTransaction::where('source_type', 'booth_session_hpp')->where('source_id', $record->id)->count());
        $this->assertSame(1, FinancialTransaction::withTrashed()->where('source_type', 'booth_session_hpp')->where('source_id', $record->id)->count());

        // Jejak audit mencatat alasan buka kembali
        $this->assertDatabaseHas('booth_hpp_audit_logs', [
            'booth_session_hpp_id' => $record->id,
            'action' => 'reopened',
            'reason' => 'Buka kembali HPP dengan alasan: Revisi karena selisih stock kertas',
        ]);
    }

    // =========================================================================
    // 5. SETTING TARIF STANDAR & BULK CONFIRM
    // =========================================================================

    public function test_13_setting_standard_rate_updates_settings_and_unconfirmed_records(): void
    {
        $report1 = $this->createApprovedReport($this->cabangJanus, '2026-11-26', 100, 80, 3000000);
        $report2 = $this->createApprovedReport($this->cabangJanus, '2026-11-27', 200, 160, 6000000);

        $service = app(BoothHppService::class);
        $service->syncApprovedRecaps('Janus');

        // Ubah tarif standar Janus menjadi 2700 dan terapkan ke seluruh unconfirmed
        $service->setStandardRate('Janus', 2700, true, $this->financeUser);

        $this->assertSame('2700.00', BoothSessionHppSetting::where('booth_type', 'Janus')->value('default_rate_per_sheet'));

        $rec1 = BoothSessionHpp::where('session_report_id', $report1->id)->first();
        $rec2 = BoothSessionHpp::where('session_report_id', $report2->id)->first();

        $this->assertSame(2700.0, (float) $rec1->hpp_rate_per_sheet);
        $this->assertSame(270000.0, (float) $rec1->total_hpp); // 100 * 2700

        $this->assertSame(2700.0, (float) $rec2->hpp_rate_per_sheet);
        $this->assertSame(540000.0, (float) $rec2->total_hpp); // 200 * 2700
    }

    public function test_14_bulk_confirm_confirms_all_calculated_recaps(): void
    {
        $r1 = $this->createApprovedReport($this->cabangGolio, '2026-11-28', 50, 40, 1500000);
        $r2 = $this->createApprovedReport($this->cabangGolio, '2026-11-29', 60, 50, 1800000);

        $service = app(BoothHppService::class);
        $service->syncApprovedRecaps('Golio');

        $count = $service->bulkConfirm('Golio', Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), $this->financeUser);

        $this->assertSame(2, $count);

        $this->assertTrue(BoothSessionHpp::where('session_report_id', $r1->id)->first()->isConfirmed());
        $this->assertTrue(BoothSessionHpp::where('session_report_id', $r2->id)->first()->isConfirmed());
    }

    // =========================================================================
    // 6. DASHBOARD SUMMARY TERINTEGRASI (SELURUH CABANG)
    // =========================================================================

    public function test_15_dashboard_summary_aggregates_all_branches_accurately(): void
    {
        // 1. Setup Express Event (64 sheets @ 5500 = 352.000)
        $expressReport = SessionReport::create([
            'report_number' => 'SR-EXP-001',
            'report_date' => '2026-11-10',
            'status' => SessionReport::STATUS_APPROVED,
            'cabang_id' => $this->cabangExpress->cabang_id,
            'total_sessions' => 64,
            'total_transactions' => 55,
            'grand_total_amount' => 2199000,
        ]);

        $event = ExpressEvent::create([
            'event_name' => 'Gathering Siegwerk',
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-10',
            'hpp_rate_per_sheet' => 5500,
            'hpp_status' => ExpressEvent::HPP_CONFIRMED,
            'reconciliation_status' => ExpressEvent::RECON_RECONCILED,
        ]);

        $link = ExpressEventRecap::create([
            'express_event_id' => $event->id,
            'session_report_id' => $expressReport->id,
        ]);

        ExpressEventRecapAllocation::create([
            'express_event_recap_id' => $link->id,
            'allocated_sessions' => 55,
            'allocated_sheets' => 64,
            'allocated_revenue' => 2199000,
        ]);

        // 2. Setup Janus Recap (80 sheets @ 2500 = 200.000, Confirmed)
        $janusReport = $this->createApprovedReport($this->cabangJanus, '2026-11-12', 80, 60, 2000000);
        $service = app(BoothHppService::class);
        $service->syncApprovedRecaps('Janus');
        $janusRecord = BoothSessionHpp::where('session_report_id', $janusReport->id)->first();
        $service->confirmHpp($janusRecord, $this->financeUser);

        // 3. Setup Golio Recap (50 sheets @ 3000 = 150.000, Confirmed)
        $golioReport = $this->createApprovedReport($this->cabangGolio, '2026-11-14', 50, 40, 1500000);
        $service->syncApprovedRecaps('Golio');
        $golioRecord = BoothSessionHpp::where('session_report_id', $golioReport->id)->first();
        $service->confirmHpp($golioRecord, $this->financeUser);

        $dashboardService = app(HppDashboardService::class);
        $summary = $dashboardService->getSummary(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

        $this->assertSame(352000, $summary['total_hpp_express']);
        $this->assertSame(200000, $summary['total_hpp_janus']);
        $this->assertSame(150000, $summary['total_hpp_golio']);
        // Total Semua Cabang = 352.000 + 200.000 + 150.000 = 702.000
        $this->assertSame(702000, $summary['total_hpp_all']);
        $this->assertSame(3, $summary['confirmed_count']);
    }

    // =========================================================================
    // 7. REKAP PERBULAN INTEGRATION
    // =========================================================================

    public function test_16_rekap_perbulan_aggregates_janus_and_golio_confirmed_hpp(): void
    {
        $janusReport = $this->createApprovedReport($this->cabangJanus, '2026-11-10', 100, 80, 3000000);
        $service = app(BoothHppService::class);
        $service->syncApprovedRecaps('Janus');
        $rec = BoothSessionHpp::where('session_report_id', $janusReport->id)->first();
        $service->confirmHpp($rec, $this->financeUser); // 100 * 2500 = 250.000

        $rekapService = app(RekapPerbulanService::class);
        $data = $rekapService->getReportData(2026, 11);

        $janusRow = $data['booth_table']['rows']['Janus'];
        $this->assertSame(250000, $janusRow['hpp']);
        $this->assertFalse($janusRow['is_pending']);
    }

    public function test_17_rekap_perbulan_shows_pending_indicator_when_booth_has_unconfirmed_hpp(): void
    {
        $golioReport = $this->createApprovedReport($this->cabangGolio, '2026-11-10', 60, 50, 1800000);
        $service = app(BoothHppService::class);
        $service->syncApprovedRecaps('Golio');

        // Belum dikonfirmasi (status calculated / pending)
        $rekapService = app(RekapPerbulanService::class);
        $data = $rekapService->getReportData(2026, 11);

        $golioRow = $data['booth_table']['rows']['Golio'];
        $this->assertTrue($golioRow['is_pending']);
        $this->assertTrue($golioRow['hpp_pending']);
    }

    // =========================================================================
    // 8. OTORISASI / PERMISSIONS
    // =========================================================================

    public function test_18_karyawan_cannot_manage_or_confirm_booth_hpp(): void
    {
        $this->assertFalse($this->crewUser->can('view_booth_hpp'));
        $this->assertFalse($this->crewUser->can('manage_booth_hpp'));
        $this->assertFalse($this->crewUser->can('confirm_booth_hpp'));

        $this->assertTrue($this->financeUser->can('view_booth_hpp'));
        $this->assertTrue($this->financeUser->can('manage_booth_hpp'));
        $this->assertTrue($this->financeUser->can('confirm_booth_hpp'));
    }

    // =========================================================================
    // 9. LIVEWIRE RENDERING SEMUA TAB
    // =========================================================================

    public function test_19_livewire_list_hpp_renders_all_tabs_correctly(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(ListExpressEvents::class)
            ->assertSuccessful()
            ->assertSee('Semua Cabang')
            ->assertSee('Express')
            ->assertSee('Janus')
            ->assertSee('Golio')
            ->call('setTab', 'janus')
            ->assertSet('activeTab', 'janus')
            ->assertSee('Tarif Standar Janus')
            ->call('setTab', 'golio')
            ->assertSet('activeTab', 'golio')
            ->assertSee('Tarif Standar Golio')
            ->call('setTab', 'express')
            ->assertSet('activeTab', 'express')
            ->assertSee('Daftar Event Express');
    }

    public function test_20_livewire_actions_set_tariff_and_confirm_in_tab(): void
    {
        $this->actingAs($this->superAdmin);

        $report = $this->createApprovedReport($this->cabangJanus, '2026-11-10', 80, 60, 2000000);
        app(BoothHppService::class)->syncApprovedRecaps('Janus');
        $record = BoothSessionHpp::where('session_report_id', $report->id)->firstOrFail();

        Livewire::test(ListExpressEvents::class)
            ->call('setTab', 'janus')
            ->call('openTariffModal', $record->id)
            ->assertSet('isTariffModalOpen', true)
            ->set('modalRate', 2600)
            ->call('saveTariff')
            ->assertSet('isTariffModalOpen', false);

        $record->refresh();
        $this->assertSame(2600.0, (float) $record->hpp_rate_per_sheet);
        $this->assertSame(208000.0, (float) $record->total_hpp); // 80 * 2600

        Livewire::test(ListExpressEvents::class)
            ->call('confirmBoothHpp', $record->id);

        $record->refresh();
        $this->assertTrue($record->isConfirmed());
    }
}
