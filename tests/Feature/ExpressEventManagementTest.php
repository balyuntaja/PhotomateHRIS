<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\ExpressEvent;
use App\Models\ExpressEventHpp;
use App\Models\ExpressEventRecap;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialParentCategory;
use App\Models\FinancialPaymentMethod;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Role;
use App\Models\SessionReport;
use App\Services\ExpressEventAccountingService;
use App\Services\ExpressEventAllocationService;
use App\Services\ExpressEventHppService;
use App\Services\RekapPerbulanService;
use App\Services\SessionReportFinancialService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ExpressEventManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Perusahaan $perusahaan;
    protected Cabang $expressCabang;
    protected Cabang $janusCabang;
    protected Cabang $golioCabang;
    protected Karyawan $superAdmin;
    protected Karyawan $karyawanRegular;
    protected FinancialAccount $expressAccount;
    protected FinancialCategory $pendapatanBooth;

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

        $this->expressCabang = Cabang::create([
            'cabang_id' => 'C0005',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Photomate Express',
        ]);

        $this->janusCabang = Cabang::create([
            'cabang_id' => 'C0004',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Newspaper Janus',
        ]);

        $this->golioCabang = Cabang::create([
            'cabang_id' => 'C0003',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Golio',
        ]);

        $this->expressAccount = FinancialAccount::firstOrCreate(
            ['name' => 'Photomate Express'],
            ['is_active' => true, 'sort_order' => 1]
        );

        $parentIncome = FinancialParentCategory::firstOrCreate(
            ['name' => FinancialParentCategory::NAME_INCOME],
            ['is_active' => true]
        );

        $this->pendapatanBooth = FinancialCategory::firstOrCreate(
            ['name' => SessionReportFinancialService::CATEGORY_NAME, 'transaction_type' => FinancialCategory::TYPE_INCOME],
            ['parent_category_id' => $parentIncome->id, 'is_active' => true]
        );

        $adminRole = Role::firstOrCreate(
            ['name' => 'Admin', 'guard_name' => 'web'],
            ['role_id' => 'R01']
        );

        $karyawanRole = Role::firstOrCreate(
            ['name' => 'Karyawan', 'guard_name' => 'web'],
            ['role_id' => 'R07']
        );

        $this->superAdmin = Karyawan::create([
            'karyawan_id' => 'K0001',
            'role_id' => $adminRole->role_id,
            'perusahaan_id' => 'P0001',
            'nik' => '111111111111',
            'nama_lengkap' => 'Admin Express',
            'email' => 'admin@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);

        $this->karyawanRegular = Karyawan::create([
            'karyawan_id' => 'K0002',
            'role_id' => $karyawanRole->role_id,
            'perusahaan_id' => 'P0001',
            'nik' => '222222222222',
            'nama_lengkap' => 'Crew Regular',
            'email' => 'crew@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1995-05-05',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Surabaya',
        ]);
    }

    protected function createApprovedRecap(
        string $number,
        string $date,
        int $sessions,
        int $sheets,
        int $revenue,
        ?Cabang $cabang = null
    ): SessionReport {
        $cabang = $cabang ?? $this->expressCabang;
        $cash = (int) round($revenue / 2);
        $qris = $revenue - $cash;

        return SessionReport::create([
            'report_number' => $number,
            'report_date' => Carbon::parse($date),
            'cabang_id' => $cabang->cabang_id,
            'status' => SessionReport::STATUS_APPROVED,
            'total_sessions' => $sheets,
            'total_transactions' => $sessions,
            'total_cash_amount' => $cash,
            'total_cash_sessions' => $sessions,
            'total_cash_transactions' => $sessions,
            'total_qris_amount' => $qris,
            'total_qris_sessions' => $sessions,
            'total_qris_transactions' => $sessions,
            'grand_total_amount' => $revenue,
            'wedding_total_sessions' => $sessions,
            'wedding_total_sheets' => $sheets,
            'submitted_by' => $this->karyawanRegular->karyawan_id,
            'submitted_at' => now(),
            'approved_by' => $this->superAdmin->karyawan_id,
            'approved_at' => now(),
        ]);
    }

    // ==========================================
    // 1. EVENT MANAGEMENT TESTS
    // ==========================================

    public function test_1_admin_can_create_express_event(): void
    {
        $this->actingAs($this->superAdmin);

        $event = ExpressEvent::create([
            'event_name' => 'Gathering Siegwerk',
            'start_date' => '2026-11-07',
            'end_date' => '2026-11-07',
            'hpp_rate_per_sheet' => 5500,
            'created_by' => $this->superAdmin->karyawan_id,
        ]);

        $this->assertDatabaseHas('express_events', [
            'id' => $event->id,
            'event_name' => 'Gathering Siegwerk',
            'hpp_rate_per_sheet' => 5500,
            'reconciliation_status' => ExpressEvent::RECON_UNALLOCATED,
            'hpp_status' => ExpressEvent::HPP_PENDING,
        ]);
    }

    public function test_2_admin_can_edit_express_event(): void
    {
        $this->actingAs($this->superAdmin);

        $event = ExpressEvent::create([
            'event_name' => 'Event Awal',
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-10',
            'created_by' => $this->superAdmin->karyawan_id,
        ]);

        $event->update([
            'event_name' => 'Event Diedit',
            'notes' => 'Catatan revisi',
        ]);

        $this->assertSame('Event Diedit', $event->fresh()->event_name);
        $this->assertSame('Catatan revisi', $event->fresh()->notes);
    }

    public function test_3_admin_can_delete_unconfirmed_event(): void
    {
        $this->actingAs($this->superAdmin);

        $event = ExpressEvent::create([
            'event_name' => 'Event Draft',
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-10',
            'created_by' => $this->superAdmin->karyawan_id,
        ]);

        $policy = new \App\Policies\ExpressEventPolicy();
        $this->assertTrue($policy->delete($this->superAdmin, $event));

        $event->delete();
        $this->assertSoftDeleted('express_events', ['id' => $event->id]);
    }

    public function test_4_event_can_have_multiple_recaps_from_different_dates(): void
    {
        $this->actingAs($this->superAdmin);

        $recap1 = $this->createApprovedRecap('SR-20261107-001', '2026-11-07', 55, 64, 2199000);
        $recap2 = $this->createApprovedRecap('SR-20261108-001', '2026-11-08', 25, 27, 1000000);
        $recap3 = $this->createApprovedRecap('SR-20261109-001', '2026-11-09', 50, 56, 2000000);

        $event = ExpressEvent::create([
            'event_name' => 'Event Multi-Hari',
            'start_date' => '2026-11-07',
            'end_date' => '2026-11-09',
            'hpp_rate_per_sheet' => 5500,
            'created_by' => $this->superAdmin->karyawan_id,
        ]);

        $allocationService = app(ExpressEventAllocationService::class);
        $allocationService->syncAllocations($event, [
            ['session_report_id' => $recap1->id, 'allocated_sessions' => 55, 'allocated_sheets' => 64, 'allocated_revenue' => 2199000],
            ['session_report_id' => $recap2->id, 'allocated_sessions' => 25, 'allocated_sheets' => 27, 'allocated_revenue' => 1000000],
            ['session_report_id' => $recap3->id, 'allocated_sessions' => 50, 'allocated_sheets' => 56, 'allocated_revenue' => 2000000],
        ]);

        $this->assertSame(3, $event->recaps()->count());
        $this->assertSame(130, $event->total_allocated_sessions);
        $this->assertSame(147, $event->total_allocated_sheets);
        $this->assertSame(5199000, $event->total_allocated_revenue);

        // 147 sheets * 5500 = 808.500
        $this->assertSame(808500, $event->calculated_hpp);
    }

    public function test_5_multiple_events_on_the_same_date_with_different_rates(): void
    {
        $this->actingAs($this->superAdmin);

        $recapA = $this->createApprovedRecap('SR-20261121-001', '2026-11-21', 40, 50, 2000000);
        $recapB = $this->createApprovedRecap('SR-20261121-002', '2026-11-21', 60, 70, 3000000);
        $recapC = $this->createApprovedRecap('SR-20261121-003', '2026-11-21', 30, 35, 1500000);

        $eventA = ExpressEvent::create([
            'event_name' => 'Wedding Event',
            'start_date' => '2026-11-21',
            'end_date' => '2026-11-21',
            'hpp_rate_per_sheet' => 5500,
            'created_by' => $this->superAdmin->karyawan_id,
        ]);

        $eventB = ExpressEvent::create([
            'event_name' => 'Gathering Event',
            'start_date' => '2026-11-21',
            'end_date' => '2026-11-21',
            'hpp_rate_per_sheet' => 8000,
            'created_by' => $this->superAdmin->karyawan_id,
        ]);

        $eventC = ExpressEvent::create([
            'event_name' => 'Corporate Event',
            'start_date' => '2026-11-21',
            'end_date' => '2026-11-21',
            'hpp_rate_per_sheet' => 4000,
            'created_by' => $this->superAdmin->karyawan_id,
        ]);

        $allocService = app(ExpressEventAllocationService::class);
        $allocService->syncAllocations($eventA, [
            ['session_report_id' => $recapA->id, 'allocated_sessions' => 40, 'allocated_sheets' => 50, 'allocated_revenue' => 2000000],
        ]);
        $allocService->syncAllocations($eventB, [
            ['session_report_id' => $recapB->id, 'allocated_sessions' => 60, 'allocated_sheets' => 70, 'allocated_revenue' => 3000000],
        ]);
        $allocService->syncAllocations($eventC, [
            ['session_report_id' => $recapC->id, 'allocated_sessions' => 30, 'allocated_sheets' => 35, 'allocated_revenue' => 1500000],
        ]);

        // Event A: 50 * 5.500 = 275.000
        $this->assertSame(275000, $eventA->calculated_hpp);
        $this->assertSame(1725000, $eventA->gross_profit);

        // Event B: 70 * 8.000 = 560.000
        $this->assertSame(560000, $eventB->calculated_hpp);
        $this->assertSame(2440000, $eventB->gross_profit);

        // Event C: 35 * 4.000 = 140.000
        $this->assertSame(140000, $eventC->calculated_hpp);
        $this->assertSame(1360000, $eventC->gross_profit);
    }

    // ==========================================
    // 2. ALLOCATION TESTS (CRITICAL BUSINESS RULES)
    // ==========================================

    public function test_6_recap_cannot_be_allocated_to_multiple_events_and_rejects_reallocation(): void
    {
        $this->actingAs($this->superAdmin);

        // Sumber: 100 sesi, 120 lembar, 5.000.000 omzet
        $recap = $this->createApprovedRecap('SR-20261121-SPLIT', '2026-11-21', 100, 120, 5000000);

        $eventA = ExpressEvent::create([
            'event_name' => 'Event A',
            'start_date' => '2026-11-21',
            'end_date' => '2026-11-21',
            'hpp_rate_per_sheet' => 5500,
            'created_by' => $this->superAdmin->karyawan_id,
        ]);

        $eventB = ExpressEvent::create([
            'event_name' => 'Event B',
            'start_date' => '2026-11-21',
            'end_date' => '2026-11-21',
            'hpp_rate_per_sheet' => 8000,
            'created_by' => $this->superAdmin->karyawan_id,
        ]);

        $allocService = app(ExpressEventAllocationService::class);

        // Alokasi Event A: 40 sesi, 50 lembar, Rp 2.000.000
        $allocService->syncAllocations($eventA, [
            ['session_report_id' => $recap->id, 'allocated_sessions' => 40, 'allocated_sheets' => 50, 'allocated_revenue' => 2000000],
        ]);

        $this->assertSame(1, $eventA->recaps()->count());

        // Satu rekap sesi hanya boleh terhubung ke satu Event HPP: mencoba alokasi ke Event B harus gagal
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah dialokasikan ke Event HPP lain. Satu rekap sesi hanya boleh terhubung ke satu Event HPP.');

        $allocService->syncAllocations($eventB, [
            ['session_report_id' => $recap->id, 'allocated_sessions' => 60, 'allocated_sheets' => 70, 'allocated_revenue' => 3000000],
        ]);
    }

    public function test_7_allocation_validation_rejects_exceeding_source_sessions(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('melebihi sisa yang tersedia');

        $recap = $this->createApprovedRecap('SR-SESSION-MAX', '2026-11-21', 50, 60, 2000000);

        $event = ExpressEvent::create([
            'event_name' => 'Event Over Session',
            'start_date' => '2026-11-21',
            'end_date' => '2026-11-21',
        ]);

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            ['session_report_id' => $recap->id, 'allocated_sessions' => 51, 'allocated_sheets' => 60, 'allocated_revenue' => 2000000],
        ]);
    }

    public function test_8_allocation_validation_rejects_exceeding_source_sheets(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('melebihi sisa yang tersedia');

        $recap = $this->createApprovedRecap('SR-SHEET-MAX', '2026-11-21', 50, 60, 2000000);

        $event = ExpressEvent::create([
            'event_name' => 'Event Over Sheet',
            'start_date' => '2026-11-21',
            'end_date' => '2026-11-21',
        ]);

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            ['session_report_id' => $recap->id, 'allocated_sessions' => 50, 'allocated_sheets' => 61, 'allocated_revenue' => 2000000],
        ]);
    }

    public function test_9_allocation_validation_rejects_exceeding_source_revenue(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('melebihi sisa yang tersedia');

        $recap = $this->createApprovedRecap('SR-REV-MAX', '2026-11-21', 50, 60, 2000000);

        $event = ExpressEvent::create([
            'event_name' => 'Event Over Revenue',
            'start_date' => '2026-11-21',
            'end_date' => '2026-11-21',
        ]);

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            ['session_report_id' => $recap->id, 'allocated_sessions' => 50, 'allocated_sheets' => 60, 'allocated_revenue' => 2000001],
        ]);
    }

    public function test_10_allocation_rejects_negative_values(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak boleh bernilai negatif');

        $recap = $this->createApprovedRecap('SR-NEG', '2026-11-21', 50, 60, 2000000);

        $event = ExpressEvent::create([
            'event_name' => 'Event Negative',
            'start_date' => '2026-11-21',
            'end_date' => '2026-11-21',
        ]);

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            ['session_report_id' => $recap->id, 'allocated_sessions' => -5, 'allocated_sheets' => 60, 'allocated_revenue' => 2000000],
        ]);
    }

    public function test_11_unapproved_recap_cannot_be_allocated(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum berstatus APPROVED');

        $unapprovedRecap = SessionReport::create([
            'report_number' => 'SR-DRAFT-001',
            'report_date' => Carbon::parse('2026-11-21'),
            'cabang_id' => $this->expressCabang->cabang_id,
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'total_sessions' => 50,
            'total_transactions' => 50,
            'grand_total_amount' => 1000000,
        ]);

        $event = ExpressEvent::create([
            'event_name' => 'Event Waiting',
            'start_date' => '2026-11-21',
            'end_date' => '2026-11-21',
        ]);

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            ['session_report_id' => $unapprovedRecap->id, 'allocated_sessions' => 50, 'allocated_sheets' => 50, 'allocated_revenue' => 1000000],
        ]);
    }

    public function test_12_janus_and_golio_recaps_are_excluded_from_express_events(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bukan merupakan cabang Express');

        $janusRecap = $this->createApprovedRecap('SR-JANUS-001', '2026-11-21', 50, 50, 1000000, $this->janusCabang);

        $event = ExpressEvent::create([
            'event_name' => 'Event Reject Janus',
            'start_date' => '2026-11-21',
            'end_date' => '2026-11-21',
        ]);

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            ['session_report_id' => $janusRecap->id, 'allocated_sessions' => 50, 'allocated_sheets' => 50, 'allocated_revenue' => 1000000],
        ]);
    }

    // ==========================================
    // 3. HPP CALCULATION & LIFECYCLE TESTS
    // ==========================================

    public function test_13_exact_hpp_calculation_64_sheets_at_5500_yields_352000(): void
    {
        $recap = $this->createApprovedRecap('SR-SIEGWERK', '2026-11-07', 55, 64, 2199000);

        $event = ExpressEvent::create([
            'event_name' => 'Gathering Siegwerk',
            'start_date' => '2026-11-07',
            'end_date' => '2026-11-07',
            'hpp_rate_per_sheet' => 5500,
            'created_by' => $this->superAdmin->karyawan_id,
        ]);

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            ['session_report_id' => $recap->id, 'allocated_sessions' => 55, 'allocated_sheets' => 64, 'allocated_revenue' => 2199000],
        ]);

        $hppService = app(ExpressEventHppService::class);
        $preview = $hppService->calculatePreview($event);

        $this->assertSame(55, $preview['total_sessions']);
        $this->assertSame(64, $preview['total_sheets']);
        $this->assertSame(2199000, $preview['total_revenue']);
        $this->assertSame(352000, $preview['total_hpp']);
        $this->assertSame(1847000, $preview['gross_profit']);

        $snapshot = $hppService->confirmHpp($event, $this->superAdmin);
        $this->assertSame(352000, (int) $snapshot->total_hpp);
        $this->assertSame(1847000, (int) $snapshot->gross_profit);
        $this->assertSame(ExpressEvent::HPP_CONFIRMED, $event->fresh()->hpp_status);
    }

    public function test_14_cross_month_event_allocates_hpp_by_recap_operational_date(): void
    {
        // Event berlangsung 30 Nov - 2 Des
        $recapNov = $this->createApprovedRecap('SR-20261130', '2026-11-30', 50, 50, 2000000);
        $recapDec1 = $this->createApprovedRecap('SR-20261201', '2026-12-01', 80, 80, 3000000);
        $recapDec2 = $this->createApprovedRecap('SR-20261202', '2026-12-02', 70, 70, 2500000);

        $event = ExpressEvent::create([
            'event_name' => 'Cross Month Event',
            'start_date' => '2026-11-30',
            'end_date' => '2026-12-02',
            'hpp_rate_per_sheet' => 5500,
        ]);

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            ['session_report_id' => $recapNov->id, 'allocated_sessions' => 50, 'allocated_sheets' => 50, 'allocated_revenue' => 2000000],
            ['session_report_id' => $recapDec1->id, 'allocated_sessions' => 80, 'allocated_sheets' => 80, 'allocated_revenue' => 3000000],
            ['session_report_id' => $recapDec2->id, 'allocated_sessions' => 70, 'allocated_sheets' => 70, 'allocated_revenue' => 2500000],
        ]);

        $preview = app(ExpressEventHppService::class)->calculatePreview($event);

        // November: 50 * 5.500 = 275.000
        $this->assertSame(275000, $preview['period_breakdown']['2026-11']['hpp']);
        $this->assertSame(50, $preview['period_breakdown']['2026-11']['sheets']);

        // Desember: (80 + 70) = 150 sheets * 5.500 = 825.000
        $this->assertSame(825000, $preview['period_breakdown']['2026-12']['hpp']);
        $this->assertSame(150, $preview['period_breakdown']['2026-12']['sheets']);

        // Total Event: 200 * 5.500 = 1.100.000
        $this->assertSame(1100000, $preview['total_hpp']);
    }

    public function test_15_rate_zero_is_valid_when_confirmed_not_pending(): void
    {
        $recap = $this->createApprovedRecap('SR-RATE-ZERO', '2026-11-15', 50, 50, 1500000);

        $event = ExpressEvent::create([
            'event_name' => 'Free HPP Event',
            'start_date' => '2026-11-15',
            'end_date' => '2026-11-15',
            'hpp_rate_per_sheet' => 0, // Explicitly Rp 0
        ]);

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            ['session_report_id' => $recap->id, 'allocated_sessions' => 50, 'allocated_sheets' => 50, 'allocated_revenue' => 1500000],
        ]);

        $this->assertTrue($event->hasExplicitRate());
        $this->assertSame(0, $event->calculated_hpp);

        $snapshot = app(ExpressEventHppService::class)->confirmHpp($event, $this->superAdmin);
        $this->assertSame(0, (int) $snapshot->total_hpp);
        $this->assertSame(ExpressEvent::HPP_CONFIRMED, $event->fresh()->hpp_status);
    }

    public function test_16_reopen_event_reverts_status_and_logs_audit_with_reason(): void
    {
        $recap = $this->createApprovedRecap('SR-REOPEN', '2026-11-15', 50, 50, 1500000);

        $event = ExpressEvent::create([
            'event_name' => 'Event To Reopen',
            'start_date' => '2026-11-15',
            'end_date' => '2026-11-15',
            'hpp_rate_per_sheet' => 5000,
        ]);

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            ['session_report_id' => $recap->id, 'allocated_sessions' => 50, 'allocated_sheets' => 50, 'allocated_revenue' => 1500000],
        ]);

        app(ExpressEventHppService::class)->confirmHpp($event, $this->superAdmin);
        $this->assertSame(ExpressEvent::HPP_CONFIRMED, $event->fresh()->hpp_status);

        // Buka kembali
        app(ExpressEventHppService::class)->reopenEvent($event, 'Revisi tarif dari vendor', $this->superAdmin);

        $this->assertSame(ExpressEvent::HPP_CALCULATED, $event->fresh()->hpp_status);
        $this->assertNull($event->fresh()->confirmed_at);

        $this->assertDatabaseHas('express_event_audit_logs', [
            'express_event_id' => $event->id,
            'action' => 'REOPENED',
        ]);
    }

    // ==========================================
    // 4. ACCOUNTING INTEGRATION TESTS
    // ==========================================

    public function test_17_post_hpp_to_accounting_creates_expense_financial_transaction(): void
    {
        $recap = $this->createApprovedRecap('SR-ACC-POST', '2026-11-15', 50, 60, 2000000);

        $event = ExpressEvent::create([
            'event_name' => 'Accounting Event',
            'start_date' => '2026-11-15',
            'end_date' => '2026-11-15',
            'hpp_rate_per_sheet' => 5000,
        ]);

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            ['session_report_id' => $recap->id, 'allocated_sessions' => 50, 'allocated_sheets' => 60, 'allocated_revenue' => 2000000],
        ]);

        app(ExpressEventHppService::class)->confirmHpp($event, $this->superAdmin);

        $accountingService = app(ExpressEventAccountingService::class);
        $transactions = $accountingService->postHpp($event, $this->superAdmin);

        $this->assertCount(1, $transactions);
        $this->assertSame(ExpressEvent::HPP_POSTED, $event->fresh()->hpp_status);

        $trx = $transactions[0];
        $this->assertSame(FinancialTransaction::TYPE_EXPENSE, $trx->transaction_type);
        $this->assertSame(300000, (int) $trx->amount); // 60 sheets * 5000
        $this->assertSame(ExpressEventAccountingService::SOURCE_TYPE, $trx->source_type);
        $this->assertSame("{$event->id}-2026-11", $trx->source_id);
    }

    public function test_18_posting_hpp_is_idempotent_no_duplicate_transactions(): void
    {
        $recap = $this->createApprovedRecap('SR-IDEMPOTENT', '2026-11-15', 50, 50, 1500000);

        $event = ExpressEvent::create([
            'event_name' => 'Idempotent Event',
            'start_date' => '2026-11-15',
            'end_date' => '2026-11-15',
            'hpp_rate_per_sheet' => 4000,
        ]);

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            ['session_report_id' => $recap->id, 'allocated_sessions' => 50, 'allocated_sheets' => 50, 'allocated_revenue' => 1500000],
        ]);

        app(ExpressEventHppService::class)->confirmHpp($event, $this->superAdmin);

        $accountingService = app(ExpressEventAccountingService::class);

        // Post pertama
        $accountingService->postHpp($event, $this->superAdmin);
        $this->assertSame(1, FinancialTransaction::where('source_type', ExpressEventAccountingService::SOURCE_TYPE)->count());

        // Post kedua (idempotent)
        $accountingService->postHpp($event, $this->superAdmin);
        $this->assertSame(1, FinancialTransaction::where('source_type', ExpressEventAccountingService::SOURCE_TYPE)->count());
    }

    public function test_19_reopening_reverses_posted_accounting_transactions(): void
    {
        $recap = $this->createApprovedRecap('SR-REVERSE', '2026-11-15', 50, 50, 1500000);

        $event = ExpressEvent::create([
            'event_name' => 'Reversal Event',
            'start_date' => '2026-11-15',
            'end_date' => '2026-11-15',
            'hpp_rate_per_sheet' => 4000,
        ]);

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            ['session_report_id' => $recap->id, 'allocated_sessions' => 50, 'allocated_sheets' => 50, 'allocated_revenue' => 1500000],
        ]);

        app(ExpressEventHppService::class)->confirmHpp($event, $this->superAdmin);
        app(ExpressEventAccountingService::class)->postHpp($event, $this->superAdmin);

        $this->assertSame(1, FinancialTransaction::where('source_type', ExpressEventAccountingService::SOURCE_TYPE)->count());

        // Reopen event
        app(ExpressEventHppService::class)->reopenEvent($event, 'Revisi HPP', $this->superAdmin);

        // Transaksi aktif harus 0 (di-soft-delete)
        $this->assertSame(0, FinancialTransaction::where('source_type', ExpressEventAccountingService::SOURCE_TYPE)->count());
        $this->assertSame(1, FinancialTransaction::withTrashed()->where('source_type', ExpressEventAccountingService::SOURCE_TYPE)->count());
    }

    // ==========================================
    // 5. DASHBOARD & REKAP PERBULAN INTEGRATION
    // ==========================================

    public function test_20_rekap_perbulan_aggregates_express_hpp_accurately_from_confirmed_events(): void
    {
        $recap = $this->createApprovedRecap('SR-DASH-CONFIRMED', '2026-11-10', 50, 60, 2000000);

        $event = ExpressEvent::create([
            'event_name' => 'Dashboard Confirmed Event',
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-10',
            'hpp_rate_per_sheet' => 5000,
        ]);

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            ['session_report_id' => $recap->id, 'allocated_sessions' => 50, 'allocated_sheets' => 60, 'allocated_revenue' => 2000000],
        ]);

        app(ExpressEventHppService::class)->confirmHpp($event, $this->superAdmin);

        $service = app(RekapPerbulanService::class);
        $data = $service->getReportData(2026, 11);

        $expressRow = $data['booth_table']['rows']['Express'];
        $this->assertSame(2000000, $expressRow['income']);
        $this->assertSame(300000, $expressRow['hpp']); // 60 * 5000
        $this->assertSame(1700000, $expressRow['nett_profit']);
        $this->assertFalse($expressRow['hpp_pending']);
    }

    public function test_21_rekap_perbulan_shows_pending_indicator_for_unconfirmed_event(): void
    {
        $recap = $this->createApprovedRecap('SR-DASH-PENDING', '2026-11-10', 50, 60, 2000000);

        $event = ExpressEvent::create([
            'event_name' => 'Dashboard Pending Event',
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-10',
            'hpp_rate_per_sheet' => 5000,
            // NOT CONFIRMED
        ]);

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            ['session_report_id' => $recap->id, 'allocated_sessions' => 50, 'allocated_sheets' => 60, 'allocated_revenue' => 2000000],
        ]);

        $service = app(RekapPerbulanService::class);
        $data = $service->getReportData(2026, 11);

        $expressRow = $data['booth_table']['rows']['Express'];
        $this->assertTrue($expressRow['hpp_pending']);
    }

    // ==========================================
    // 6. SECURITY & AUTHORIZATION TESTS
    // ==========================================

    public function test_22_karyawan_cannot_manage_or_confirm_hpp(): void
    {
        $policy = new \App\Policies\ExpressEventPolicy();

        $event = ExpressEvent::create([
            'event_name' => 'Security Test Event',
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-10',
        ]);

        $this->assertFalse($policy->viewAny($this->karyawanRegular));
        $this->assertFalse($policy->create($this->karyawanRegular));
        $this->assertFalse($policy->confirmHpp($this->karyawanRegular, $event));
        $this->assertFalse($policy->reopen($this->karyawanRegular, $event));
    }

    public function test_23_admin_has_full_management_access(): void
    {
        $policy = new \App\Policies\ExpressEventPolicy();

        $event = ExpressEvent::create([
            'event_name' => 'Admin Test Event',
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-10',
        ]);

        $this->assertTrue($policy->viewAny($this->superAdmin));
        $this->assertTrue($policy->create($this->superAdmin));
        $this->assertTrue($policy->confirmHpp($this->superAdmin, $event));
        $this->assertTrue($policy->reopen($this->superAdmin, $event));
    }

    // ==========================================
    // 7. FILAMENT LIVEWIRE UI RENDERING TESTS
    // ==========================================

    public function test_24_livewire_list_express_events_renders_for_admin(): void
    {
        $this->actingAs($this->superAdmin);

        ExpressEvent::create([
            'event_name' => 'UI List Event',
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-10',
        ]);

        \Livewire\Livewire::test(\App\Filament\Resources\ExpressEventResource\Pages\ListExpressEvents::class)
            ->assertSuccessful()
            ->assertSee('UI List Event');
    }

    public function test_25_livewire_view_express_event_renders_details(): void
    {
        $this->actingAs($this->superAdmin);

        $event = ExpressEvent::create([
            'event_name' => 'UI View Event',
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-10',
            'hpp_rate_per_sheet' => 5000,
        ]);

        \Livewire\Livewire::test(\App\Filament\Resources\ExpressEventResource\Pages\ViewExpressEvent::class, [
            'record' => $event->getRouteKey(),
        ])
            ->assertSuccessful()
            ->assertSee('UI View Event');
    }
}
