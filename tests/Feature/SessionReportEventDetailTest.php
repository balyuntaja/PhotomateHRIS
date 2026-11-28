<?php

namespace Tests\Feature;

use App\Filament\Resources\ExpressEventResource\Pages\CreateExpressEvent;
use App\Filament\Resources\ExpressEventResource\Pages\EditExpressEvent;
use App\Filament\Resources\SessionHistoryResource\Pages\ListSessionHistories;
use App\Livewire\SessionReportEventDetail;
use App\Models\ApprovalLog;
use App\Models\Cabang;
use App\Models\ExpressEvent;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialParentCategory;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\PricingSetting;
use App\Models\Role;
use App\Models\SessionReport;
use App\Models\SessionTransaction;
use App\Services\ExpressEventAllocationService;
use App\Services\ExpressEventHppService;
use App\Services\HppDashboardService;
use App\Services\RekapPerbulanService;
use App\Services\SessionReportFinancialService;
use App\Services\SessionWorkflowService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification as MailNotification;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class SessionReportEventDetailTest extends TestCase
{
    use RefreshDatabase;

    protected Perusahaan $perusahaan;
    protected Cabang $cabangExpress;
    protected Cabang $cabangExpressSewa;
    protected Cabang $cabangExpressSelfRun;
    protected Cabang $cabangExpressSharingProfit;
    protected Cabang $cabangWedding;
    protected Cabang $cabangJanus;
    protected Cabang $cabangGolio;

    protected Karyawan $superAdmin;
    protected Karyawan $superAdminRoleUser;
    protected Karyawan $supervisor;
    protected Karyawan $managerFinance;
    protected Karyawan $managerHrd;
    protected Karyawan $ceo;
    protected Karyawan $crew;

    protected function setUp(): void
    {
        parent::setUp();

        PricingSetting::firstOrCreate(
            ['is_active' => true],
            [
                'name' => 'Standard Pricing (Photomate)',
                'base_price' => 30000,
                'additional_session_price' => 15000,
                'effective_from' => now()->startOfYear(),
                'is_active' => true,
            ]
        );

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
        ]);
        $this->cabangExpressSewa = Cabang::create([
            'cabang_id' => 'C0007',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Express Sewa',
        ]);
        $this->cabangExpressSelfRun = Cabang::create([
            'cabang_id' => 'C0008',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Express Self Run',
        ]);
        $this->cabangExpressSharingProfit = Cabang::create([
            'cabang_id' => 'C0009',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Express Sharing Profit',
        ]);
        $this->cabangWedding = Cabang::create([
            'cabang_id' => 'C0006',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Wedding Photomate',
        ]);
        $this->cabangJanus = Cabang::create([
            'cabang_id' => 'C0004',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Newspaper Janus',
        ]);
        $this->cabangGolio = Cabang::create([
            'cabang_id' => 'C0003',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Golio',
        ]);

        $roleAdmin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web'], ['role_id' => 'R01']);
        $roleSuperAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'], ['role_id' => 'R09']);
        $roleSupervisor = Role::firstOrCreate(['name' => 'Supervisor', 'guard_name' => 'web'], ['role_id' => 'R08']);
        $roleFinance = Role::firstOrCreate(['name' => 'Manager Finance', 'guard_name' => 'web'], ['role_id' => 'R04']);
        $roleHrd = Role::firstOrCreate(['name' => 'Manager HRD', 'guard_name' => 'web'], ['role_id' => 'R03']);
        $roleCeo = Role::firstOrCreate(['name' => 'CEO', 'guard_name' => 'web'], ['role_id' => 'R06']);
        $roleCrew = Role::firstOrCreate(['name' => 'Karyawan', 'guard_name' => 'web'], ['role_id' => 'R07']);

        $this->superAdmin = $this->makeKaryawan('ADM01', 'Super Admin Utama', $roleAdmin, 'admin.super@photomate.id', '910000000001');
        $this->superAdminRoleUser = $this->makeKaryawan('ADM02', 'Super Admin Spatie', $roleSuperAdmin, 'superadmin@photomate.id', '910000000002');
        $this->supervisor = $this->makeKaryawan('SPV01', 'Supervisor Lapangan', $roleSupervisor, 'spv@photomate.id', '910000000003');
        $this->managerFinance = $this->makeKaryawan('FIN01', 'Manager Finance', $roleFinance, 'finance@photomate.id', '910000000004');
        $this->managerHrd = $this->makeKaryawan('HRD01', 'Manager HRD', $roleHrd, 'hrd@photomate.id', '910000000005');
        $this->ceo = $this->makeKaryawan('CEO01', 'CEO Photomate', $roleCeo, 'ceo@photomate.id', '910000000006');
        $this->crew = $this->makeKaryawan('CRW01', 'Crew Lapangan', $roleCrew, 'crew@photomate.id', '910000000007');

        FinancialAccount::firstOrCreate(['name' => 'Photomate Express'], ['is_active' => true, 'sort_order' => 1]);
        $parentIncome = FinancialParentCategory::firstOrCreate(['name' => FinancialParentCategory::NAME_INCOME], ['is_active' => true]);
        FinancialCategory::firstOrCreate(
            ['name' => SessionReportFinancialService::CATEGORY_NAME, 'transaction_type' => FinancialCategory::TYPE_INCOME],
            ['parent_category_id' => $parentIncome->id, 'is_active' => true]
        );
    }

    protected function makeKaryawan(string $id, string $name, Role $role, string $email, string $nik): Karyawan
    {
        $user = Karyawan::create([
            'karyawan_id' => $id,
            'role_id' => $role->role_id,
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nik' => $nik,
            'nama_lengkap' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1992-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);

        $user->assignRole($role);

        return $user->fresh();
    }

    protected function createHistoricalApprovedReport(array $overrides = []): SessionReport
    {
        $reportDate = $overrides['report_date'] ?? '2025-11-10';
        $submittedAt = $overrides['submitted_at'] ?? Carbon::parse($reportDate)->setTime(21, 0, 0);
        $approvedAt = $overrides['approved_at'] ?? Carbon::parse($reportDate)->addDay()->setTime(9, 30, 0);

        $report = SessionReport::create(array_merge([
            'report_number' => 'SR-HIST-' . str_pad((string) (SessionReport::count() + 1), 3, '0', STR_PAD_LEFT),
            'report_date' => $reportDate,
            'status' => SessionReport::STATUS_APPROVED,
            'cabang_id' => $this->cabangExpress->cabang_id,
            'event_note' => null,
            'event_date' => null,
            'total_sessions' => 12,
            'total_transactions' => 6,
            'total_cash_amount' => 150000,
            'total_cash_sessions' => 6,
            'total_cash_transactions' => 3,
            'total_qris_amount' => 195000,
            'total_qris_sessions' => 6,
            'total_qris_transactions' => 3,
            'grand_total_amount' => 345000,
            'bonus_amount' => 0,
            'validation_status' => 'VALID',
            'submitted_by' => $this->crew->karyawan_id,
            'submitted_at' => $submittedAt,
            'approved_by' => $this->supervisor->karyawan_id,
            'approved_at' => $approvedAt,
        ], $overrides));

        $report->crews()->attach([$this->crew->karyawan_id]);

        if (! $report->usesManualSessionInput() && $report->transactions()->count() === 0) {
            SessionTransaction::create([
                'session_report_id' => $report->id,
                'payment_method' => 'CASH',
                'session_count' => 6,
                'amount' => 150000,
                'notes' => 'Cash sesi historis',
            ]);
            SessionTransaction::create([
                'session_report_id' => $report->id,
                'payment_method' => 'QRIS',
                'session_count' => 6,
                'amount' => 195000,
                'notes' => 'QRIS sesi historis',
            ]);
        }

        return $report->fresh(['cabang', 'crews', 'transactions', 'invoice']);
    }

    public function test_only_super_admin_is_authorized_and_sees_edit_detail_event_button(): void
    {
        $report = $this->createHistoricalApprovedReport();

        // Super Admin users (role R01 & role 'Super Admin')
        foreach ([$this->superAdmin, $this->superAdminRoleUser] as $admin) {
            $this->actingAs($admin);

            $this->assertTrue(Gate::allows('updateEventDetail', $report));
            // Full EditSessionReport remains locked on APPROVED reports
            $this->assertFalse(Gate::allows('update', $report));

            Livewire::test(SessionReportEventDetail::class, ['reportId' => $report->id])
                ->assertSee('Belum Ada Data Event')
                ->assertSee('Edit Detail Event');

            Livewire::test(ListSessionHistories::class)
                ->mountTableAction('detail', $report->id)
                ->assertSee('Edit Detail Event')
                ->assertSee('Belum Ada Data Event');
        }

        // Non-Super-Admin roles must NOT have access and must NOT see the button
        $nonAdmins = [
            $this->supervisor,
            $this->managerFinance,
            $this->managerHrd,
            $this->ceo,
            $this->crew,
        ];

        foreach ($nonAdmins as $user) {
            $this->actingAs($user);

            $this->assertFalse(Gate::allows('updateEventDetail', $report));

            Livewire::test(SessionReportEventDetail::class, ['reportId' => $report->id])
                ->assertDontSee('Edit Detail Event')
                ->call('openEdit')
                ->assertSet('isEditing', false)
                ->assertNotified('Akses ditolak');
        }
    }

    public function test_non_super_admin_is_blocked_from_saving_event_detail_via_livewire_and_service(): void
    {
        $report = $this->createHistoricalApprovedReport();

        foreach ([$this->supervisor, $this->managerFinance, $this->crew] as $user) {
            $this->actingAs($user);

            Livewire::test(SessionReportEventDetail::class, ['reportId' => $report->id])
                ->set('data.event_note', 'Percobaan Ubah Ilegal')
                ->set('data.event_date', '2025-11-10')
                ->call('save')
                ->assertNotified('Akses ditolak');

            $this->assertNull($report->fresh()->event_note);
            $this->assertNull($report->fresh()->event_date);

            try {
                app(SessionWorkflowService::class)->updateEventDetail(
                    $report,
                    $user,
                    'Percobaan Service Ilegal',
                    '2025-11-10'
                );
                $this->fail('Non-Super-Admin should not be allowed to call updateEventDetail.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Super Admin', $e->getMessage());
            }
        }
    }

    public function test_super_admin_can_fill_missing_event_details_with_past_date_on_approved_report(): void
    {
        MailNotification::fake();

        $report = $this->createHistoricalApprovedReport([
            'report_date' => '2025-05-10',
            'event_note' => null,
            'event_date' => null,
        ]);

        $originalReportCount = SessionReport::count();
        $originalSubmittedAt = $report->submitted_at->toDateTimeString();
        $originalApprovedAt = $report->approved_at->toDateTimeString();
        $originalApprovedBy = $report->approved_by;

        $this->actingAs($this->superAdmin);

        $component = Livewire::test(SessionReportEventDetail::class, ['reportId' => $report->id])
            ->assertSee('Belum Ada Data Event')
            ->assertSee('Belum diisi')
            ->call('openEdit')
            ->assertSet('isEditing', true)
            ->assertSet('data.event_note', null)
            ->assertSet('data.event_date', null)
            ->assertSee('Simpan')
            ->assertSee('Batal');

        // Fill with past date (2025-05-10) — must NOT reject past dates
        $component->fillForm([
            'event_note' => 'Event Wisuda Universitas Brawijaya 2025',
            'event_date' => '2025-05-10',
        ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('isEditing', false)
            ->assertNotified('Detail event berhasil diperbarui.')
            ->assertSee('Event Wisuda Universitas Brawijaya 2025')
            ->assertSee('Data Event Lengkap')
            ->assertDontSee('Belum Ada Data Event');

        // Must update existing record in place without creating a new record
        $this->assertSame($originalReportCount, SessionReport::count());

        $updated = $report->fresh(['crews', 'transactions', 'approvalLogs']);

        // Only event_note and event_date changed
        $this->assertSame('Event Wisuda Universitas Brawijaya 2025', $updated->event_note);
        $this->assertSame('2025-05-10', $updated->event_date?->toDateString());

        // Protected fields must remain strictly untouched
        $this->assertSame('2025-05-10', $updated->report_date?->toDateString());
        $this->assertSame($originalSubmittedAt, $updated->submitted_at?->toDateTimeString());
        $this->assertSame(SessionReport::STATUS_APPROVED, $updated->status);
        $this->assertSame($originalApprovedBy, $updated->approved_by);
        $this->assertSame($originalApprovedAt, $updated->approved_at?->toDateTimeString());
        $this->assertSame(12, $updated->total_sessions);
        $this->assertSame(6, $updated->total_transactions);
        $this->assertSame(150000, $updated->total_cash_amount);
        $this->assertSame(195000, $updated->total_qris_amount);
        $this->assertSame(345000, $updated->grand_total_amount);
        $this->assertSame([$this->crew->karyawan_id], $updated->crews->pluck('karyawan_id')->all());
        $this->assertCount(2, $updated->transactions);

        // No re-approval emails triggered
        MailNotification::assertNothingSent();

        // Audit trail recorded
        $this->assertDatabaseHas('approval_logs', [
            'session_report_id' => $report->id,
            'user_id' => $this->superAdmin->karyawan_id,
            'action' => 'EVENT_DETAIL_UPDATED',
        ]);

        // Cancel button test when editing existing event detail
        $component->call('openEdit')
            ->assertSet('isEditing', true)
            ->assertSet('data.event_note', 'Event Wisuda Universitas Brawijaya 2025')
            ->assertSet('data.event_date', fn ($value): bool => str_starts_with((string) $value, '2025-05-10'))
            ->fillForm([
                'event_note' => 'Perubahan Yang Dibatalkan',
                'event_date' => '2024-01-01',
            ])
            ->call('cancelEdit')
            ->assertSet('isEditing', false)
            ->assertSee('Event Wisuda Universitas Brawijaya 2025');

        $this->assertSame('Event Wisuda Universitas Brawijaya 2025', $report->fresh()->event_note);
        $this->assertSame('2025-05-10', $report->fresh()->event_date?->toDateString());
    }

    public function test_super_admin_can_update_existing_event_detail_on_all_statuses_and_express_branches(): void
    {
        $this->actingAs($this->superAdmin);

        $cabangExpressLiteral = Cabang::create([
            'cabang_id' => 'C0010',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Express',
        ]);

        $expressBranches = [
            $cabangExpressLiteral,
            $this->cabangExpress,
            $this->cabangExpressSewa,
            $this->cabangExpressSelfRun,
            $this->cabangExpressSharingProfit,
        ];

        $statuses = [
            SessionReport::STATUS_DRAFT,
            SessionReport::STATUS_WAITING_APPROVAL,
            SessionReport::STATUS_REVISION,
            SessionReport::STATUS_APPROVED,
            SessionReport::STATUS_REJECTED,
        ];

        foreach ($expressBranches as $index => $cabang) {
            $status = $statuses[$index % count($statuses)];
            $isManual = in_array($cabang->cabang_id, [
                $this->cabangExpressSewa->cabang_id,
                $this->cabangExpressSelfRun->cabang_id,
            ], true);

            $report = $this->createHistoricalApprovedReport([
                'report_date' => '2025-08-20',
                'status' => $status,
                'cabang_id' => $cabang->cabang_id,
                'event_note' => 'Event Lama ' . $cabang->nama_cabang,
                'event_date' => null,
                'wedding_total_sessions' => $isManual ? 40 : null,
                'wedding_total_sheets' => $isManual ? 80 : null,
                'total_transactions' => $isManual ? 40 : 6,
                'total_sessions' => $isManual ? 80 : 12,
                'grand_total_amount' => $isManual ? 0 : 345000,
                'approved_by' => $status === SessionReport::STATUS_APPROVED ? $this->supervisor->karyawan_id : null,
                'approved_at' => $status === SessionReport::STATUS_APPROVED ? now()->subMonths(6) : null,
            ]);

            $this->assertTrue(Gate::allows('updateEventDetail', $report));
            $this->assertContains($cabang->cabang_id, SessionReport::expressEventCabangIds());

            Livewire::test(SessionReportEventDetail::class, ['reportId' => $report->id])
                ->assertSee('Informasi Event')
                ->assertSee('Data Event Belum Lengkap')
                ->assertSee('Edit Detail Event')
                ->call('openEdit')
                ->assertSet('data.event_note', 'Event Lama ' . $cabang->nama_cabang)
                ->fillForm([
                    'event_note' => 'Event Terbarui ' . $cabang->nama_cabang,
                    'event_date' => '2025-08-19',
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $fresh = $report->fresh();
            $this->assertSame('Event Terbarui ' . $cabang->nama_cabang, $fresh->event_note);
            $this->assertSame('2025-08-19', $fresh->event_date?->toDateString());
            $this->assertSame($status, $fresh->status);
            $this->assertSame('2025-08-20', $fresh->report_date?->toDateString());
        }
    }

    public function test_model_immutability_still_blocks_other_fields_on_approved_report_even_for_super_admin(): void
    {
        $report = $this->createHistoricalApprovedReport();

        $this->actingAs($this->superAdmin);

        // Allowed: updating event_note / event_date directly as Super Admin
        $report->update([
            'event_note' => 'Event Valid Super Admin',
            'event_date' => '2025-09-01',
        ]);
        $this->assertSame('Event Valid Super Admin', $report->fresh()->event_note);

        // Blocked: trying to modify financial or session date fields on APPROVED report
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APPROVED');

        $report->update([
            'grand_total_amount' => 9999999,
        ]);
    }

    public function test_historical_approved_report_completed_by_super_admin_integrates_with_manajemen_hpp_end_to_end(): void
    {
        $this->actingAs($this->superAdmin);

        // 1. Rekap lama berstatus APPROVED tanpa informasi event (2025-11-10)
        $historicalReport = $this->createHistoricalApprovedReport([
            'report_number' => 'SR-OLD-2025-001',
            'report_date' => '2025-11-10',
            'cabang_id' => $this->cabangExpress->cabang_id,
            'event_note' => null,
            'event_date' => null,
            'total_transactions' => 55,
            'total_sessions' => 64,
            'grand_total_amount' => 2199000,
        ]);

        $allocationService = app(ExpressEventAllocationService::class);

        // Before completing event detail: falls back to "Rekap SR-OLD-2025-001 (Photomate Express)"
        $namesBefore = $allocationService->getAvailableEventNames();
        $this->assertArrayHasKey('Rekap SR-OLD-2025-001 (Photomate Express)', $namesBefore);

        // 2. Super Admin melengkapi Nama Event & Tanggal Event (past date) di Riwayat Rekap Sesi
        Livewire::test(SessionReportEventDetail::class, ['reportId' => $historicalReport->id])
            ->call('openEdit')
            ->fillForm([
                'event_note' => 'Gathering Tahunan Siegwerk 2025',
                'event_date' => '2025-11-09',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $historicalReport->refresh();
        $this->assertSame(SessionReport::STATUS_APPROVED, $historicalReport->status);
        $this->assertSame(1, SessionReport::count());

        // 3. Manajemen HPP dropdown now shows the proper event name instead of fallback
        $namesAfter = $allocationService->getAvailableEventNames();
        $this->assertArrayHasKey('Gathering Tahunan Siegwerk 2025', $namesAfter);
        $this->assertArrayNotHasKey('Rekap SR-OLD-2025-001 (Photomate Express)', $namesAfter);

        $eligible = $allocationService->getEligibleRecapsForEventName('Gathering Tahunan Siegwerk 2025');
        $this->assertCount(1, $eligible);
        $this->assertSame($historicalReport->id, $eligible->first()['id']);
        $this->assertSame(55, $eligible->first()['source_sessions']);
        $this->assertSame(64, $eligible->first()['source_sheets']);
        $this->assertSame(2199000, $eligible->first()['source_revenue']);
        $this->assertSame('2025-11-09', $eligible->first()['raw_date']);

        // 4. Buat Event Express di Manajemen HPP & konfirmasi HPP
        Livewire::test(CreateExpressEvent::class)
            ->set('data.event_name', 'Gathering Tahunan Siegwerk 2025')
            ->assertSet('data.start_date', '2025-11-09')
            ->assertSet('data.end_date', '2025-11-09')
            ->assertSet('data.allocations_data.0.session_report_id', $historicalReport->id)
            ->assertSet('data.allocations_data.0.allocated_sessions', 55)
            ->assertSet('data.allocations_data.0.allocated_sheets', 64)
            ->assertSet('data.allocations_data.0.allocated_revenue', 2199000)
            ->set('data.hpp_rate_per_sheet', 5500)
            ->call('create')
            ->assertHasNoFormErrors();

        $event = ExpressEvent::where('event_name', 'Gathering Tahunan Siegwerk 2025')->firstOrFail();
        $this->assertSame(1, $event->recaps()->count());
        $this->assertSame(55, $event->total_allocated_sessions);
        $this->assertSame(64, $event->total_allocated_sheets);
        $this->assertSame(2199000, $event->total_allocated_revenue);
        $this->assertSame(352000, $event->calculated_hpp);

        app(ExpressEventHppService::class)->confirmHpp($event, $this->superAdmin);

        // 5. Verifikasi HppDashboardService & RekapPerbulanService membaca tepat 1x (tanpa duplikasi)
        $start = Carbon::parse('2025-11-01');
        $end = Carbon::parse('2025-11-30');

        $dashboardSummary = app(HppDashboardService::class)->getSummary($start, $end);
        $this->assertSame(352000, $dashboardSummary['total_hpp_express']);
        $this->assertSame(64, $dashboardSummary['branches']['Express']['total_sheets']);
        $this->assertSame(2199000, $dashboardSummary['branches']['Express']['total_revenue']);

        $rekapBulanan = app(RekapPerbulanService::class)->getReportData(2025, 11);
        $expressRow = $rekapBulanan['booth_table']['rows']['Express'];
        $this->assertSame(2199000, $expressRow['income']);
        $this->assertSame(352000, $expressRow['hpp']);
        $this->assertFalse($expressRow['hpp_pending']);

        // 6. Report cannot be duplicated or re-allocated elsewhere
        $this->assertEmpty($allocationService->getEligibleRecapsForEventName('Gathering Tahunan Siegwerk 2025'));
    }

    public function test_completed_historical_report_can_be_attached_to_existing_express_event_without_duplication(): void
    {
        $this->actingAs($this->superAdmin);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-HIST-001',
            'client_name' => 'PT Nusantara',
            'event_name' => 'Expo Nusantara 2025',
            'event_location' => 'Surabaya',
            'person_in_charge' => 'Andi',
            'event_date' => '2025-10-12',
            'event_end_date' => '2025-10-12',
            'invoice_date' => '2025-10-01',
            'due_date' => '2025-10-10',
            'subtotal' => 4000000,
            'discount' => 0,
            'total' => 4000000,
            'down_payment' => 0,
            'status' => 'Lunas',
            'cabang_id' => $this->cabangExpressSewa->cabang_id,
        ]);

        // Existing ExpressEvent created from Invoice before historical session recap was tagged
        $event = ExpressEvent::create([
            'event_name' => 'Expo Nusantara 2025',
            'start_date' => '2025-10-12',
            'end_date' => '2025-10-12',
            'hpp_rate_per_sheet' => 5000,
            'created_by' => $this->superAdmin->karyawan_id,
        ]);

        // Historical APPROVED Express Sewa report without invoice_id, event_note, or event_date
        $oldSewaReport = $this->createHistoricalApprovedReport([
            'report_number' => 'SR-SEWA-OLD-01',
            'report_date' => '2025-10-12',
            'cabang_id' => $this->cabangExpressSewa->cabang_id,
            'invoice_id' => null,
            'event_note' => null,
            'event_date' => null,
            'wedding_total_sessions' => 80,
            'wedding_total_sheets' => 160,
            'total_transactions' => 80,
            'total_sessions' => 160,
            'grand_total_amount' => 4000000,
        ]);

        // Super Admin fills event details on the historical recap
        app(SessionWorkflowService::class)->updateEventDetail(
            $oldSewaReport,
            $this->superAdmin,
            'Expo Nusantara 2025',
            '2025-10-12'
        );

        // Now EditExpressEvent sees and links the historical recap cleanly
        Livewire::test(EditExpressEvent::class, ['record' => $event->id])
            ->set('data.allocations_data', [
                [
                    'session_report_id' => $oldSewaReport->id,
                    'allocated_sessions' => 80,
                    'allocated_sheets' => 160,
                    'allocated_revenue' => 4000000,
                    'notes' => 'Rekap historis dilengkapi',
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $event->refresh();
        $this->assertSame(1, $event->recaps()->count());
        $this->assertSame(80, $event->total_allocated_sessions);
        $this->assertSame(160, $event->total_allocated_sheets);
        $this->assertSame(4000000, $event->total_allocated_revenue);
        $this->assertSame(800000, $event->calculated_hpp);
    }

    public function test_session_history_table_shows_event_column_and_updates_when_event_detail_saved(): void
    {
        $report = $this->createHistoricalApprovedReport([
            'event_note' => null,
            'event_date' => null,
        ]);

        $this->actingAs($this->superAdmin);

        Livewire::test(ListSessionHistories::class)
            ->assertSuccessful()
            ->assertSee('Belum Ada Data Event');

        app(SessionWorkflowService::class)->updateEventDetail(
            $report,
            $this->superAdmin,
            'Event Historis Terisi',
            '2025-07-07'
        );

        Livewire::test(ListSessionHistories::class)
            ->assertSuccessful()
            ->assertSee('Event Historis Terisi');
    }

    public function test_history_detail_modal_hides_edit_button_from_supervisor_and_crew(): void
    {
        $report = $this->createHistoricalApprovedReport([
            'event_note' => null,
            'event_date' => null,
        ]);

        foreach ([$this->supervisor, $this->crew] as $user) {
            $this->actingAs($user);

            Livewire::test(ListSessionHistories::class)
                ->mountTableAction('detail', $report->id)
                ->assertSee('Belum Ada Data Event')
                ->assertDontSee('Edit Detail Event');
        }
    }

    public function test_multiple_historical_reports_completed_with_same_event_name_group_cleanly_in_manajemen_hpp(): void
    {
        $this->actingAs($this->superAdmin);

        $day1 = $this->createHistoricalApprovedReport([
            'report_number' => 'SR-MULTI-001',
            'report_date' => '2025-10-18',
            'cabang_id' => $this->cabangExpressSelfRun->cabang_id,
            'wedding_total_sessions' => 30,
            'wedding_total_sheets' => 45,
            'total_transactions' => 30,
            'total_sessions' => 45,
            'grand_total_amount' => 1500000,
        ]);

        $day2 = $this->createHistoricalApprovedReport([
            'report_number' => 'SR-MULTI-002',
            'report_date' => '2025-10-19',
            'cabang_id' => $this->cabangExpressSharingProfit->cabang_id,
            'total_transactions' => 20,
            'total_sessions' => 25,
            'grand_total_amount' => 1000000,
        ]);

        $workflow = app(SessionWorkflowService::class);
        $workflow->updateEventDetail($day1, $this->superAdmin, 'Festival Raya 2025', '2025-10-18');
        $workflow->updateEventDetail($day2, $this->superAdmin, 'Festival Raya 2025', '2025-10-19');

        $allocationService = app(ExpressEventAllocationService::class);
        $names = $allocationService->getAvailableEventNames();

        // Grouped under one unique event name
        $this->assertArrayHasKey('Festival Raya 2025', $names);
        $this->assertSame(1, collect($names)->filter(fn ($n) => $n === 'Festival Raya 2025')->count());

        $eligible = $allocationService->getEligibleRecapsForEventName('Festival Raya 2025');
        $this->assertCount(2, $eligible);

        Livewire::test(CreateExpressEvent::class)
            ->set('data.event_name', 'Festival Raya 2025')
            ->assertSet('data.start_date', '2025-10-18')
            ->assertSet('data.end_date', '2025-10-19')
            ->set('data.hpp_rate_per_sheet', 4000)
            ->call('create')
            ->assertHasNoFormErrors();

        $event = ExpressEvent::where('event_name', 'Festival Raya 2025')->firstOrFail();
        $this->assertSame(2, $event->recaps()->count());
        $this->assertSame(50, $event->total_allocated_sessions);
        $this->assertSame(70, $event->total_allocated_sheets);
        $this->assertSame(2500000, $event->total_allocated_revenue);
        $this->assertSame(280000, $event->calculated_hpp);
    }

    public function test_super_admin_edited_event_name_on_invoice_linked_report_is_honored_in_modal_and_hpp(): void
    {
        $this->actingAs($this->superAdmin);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-OVRD-001',
            'client_name' => 'PT Maju',
            'event_name' => 'Nama Awal Invoice',
            'event_location' => 'Malang',
            'person_in_charge' => 'Rani',
            'event_date' => '2025-09-14',
            'event_end_date' => '2025-09-14',
            'invoice_date' => '2025-09-01',
            'due_date' => '2025-09-10',
            'subtotal' => 2000000,
            'discount' => 0,
            'total' => 2000000,
            'down_payment' => 0,
            'status' => 'Lunas',
            'cabang_id' => $this->cabangExpressSelfRun->cabang_id,
        ]);

        $report = $this->createHistoricalApprovedReport([
            'report_number' => 'SR-INV-OVRD',
            'report_date' => '2025-09-14',
            'cabang_id' => $this->cabangExpressSelfRun->cabang_id,
            'invoice_id' => $invoice->id,
            'event_note' => 'Nama Awal Invoice',
            'event_date' => null,
            'wedding_total_sessions' => 25,
            'wedding_total_sheets' => 40,
            'total_transactions' => 25,
            'total_sessions' => 40,
            'grand_total_amount' => 2000000,
        ]);

        Livewire::test(SessionReportEventDetail::class, ['reportId' => $report->id])
            ->assertSee('Data Event Belum Lengkap')
            ->call('openEdit')
            ->fillForm([
                'event_note' => 'Nama Event Final Terkoreksi',
                'event_date' => '2025-09-14',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSee('Nama Event Final Terkoreksi')
            ->assertSee('Data Event Lengkap');

        $allocationService = app(ExpressEventAllocationService::class);
        $this->assertSame('Nama Event Final Terkoreksi', $allocationService->resolveReportEventName($report->fresh(['cabang', 'invoice'])));
        $this->assertArrayHasKey('Nama Event Final Terkoreksi', $allocationService->getAvailableEventNames());
    }

    public function test_golio_and_newspaper_janus_hide_event_section_and_block_backend_updates(): void
    {
        $nonExpressBranches = [
            $this->cabangGolio,
            $this->cabangJanus,
            $this->cabangWedding,
        ];

        foreach ($nonExpressBranches as $cabang) {
            $isManual = $cabang->cabang_id === $this->cabangWedding->cabang_id;

            $report = $this->createHistoricalApprovedReport([
                'report_date' => '2025-10-15',
                'status' => SessionReport::STATUS_APPROVED,
                'cabang_id' => $cabang->cabang_id,
                'event_note' => null,
                'event_date' => null,
                'wedding_total_sessions' => $isManual ? 20 : null,
                'wedding_total_sheets' => $isManual ? 40 : null,
                'total_transactions' => $isManual ? 20 : 6,
                'total_sessions' => $isManual ? 40 : 12,
                'grand_total_amount' => $isManual ? 0 : 345000,
            ]);

            $this->assertFalse($report->supportsEventDetail());
            $this->assertNotContains($cabang->cabang_id, SessionReport::expressEventCabangIds());

            foreach ([$this->superAdmin, $this->superAdminRoleUser, $this->supervisor, $this->crew] as $user) {
                $this->actingAs($user);

                $this->assertFalse(Gate::allows('updateEventDetail', $report));

                // Livewire detail component hides Informasi Event, badge, and Edit button completely
                Livewire::test(SessionReportEventDetail::class, ['reportId' => $report->id])
                    ->assertDontSee('Informasi Event')
                    ->assertDontSee('Belum Ada Data Event')
                    ->assertDontSee('Data Event Belum Lengkap')
                    ->assertDontSee('Data Event Lengkap')
                    ->assertDontSee('Edit Detail Event')
                    ->call('openEdit')
                    ->assertSet('isEditing', false)
                    ->assertNotified('Fitur tidak tersedia')
                    ->set('data.event_note', 'Percobaan Event Non Express')
                    ->set('data.event_date', '2025-10-15')
                    ->call('save')
                    ->assertNotified('Fitur tidak tersedia');

                // Riwayat Rekap Sesi detail modal hides Informasi Event section & badges completely
                Livewire::test(ListSessionHistories::class)
                    ->assertDontSee('Belum Ada Data Event')
                    ->mountTableAction('detail', $report->id)
                    ->assertDontSee('Informasi Event')
                    ->assertDontSee('Belum Ada Data Event')
                    ->assertDontSee('Data Event Belum Lengkap')
                    ->assertDontSee('Data Event Lengkap')
                    ->assertDontSee('Edit Detail Event');
            }

            // Direct backend workflow service call is rejected even for Super Admin
            $this->actingAs($this->superAdmin);
            try {
                app(SessionWorkflowService::class)->updateEventDetail(
                    $report,
                    $this->superAdmin,
                    'Event Paksa ' . $cabang->nama_cabang,
                    '2025-10-15'
                );
                $this->fail("Expected RuntimeException when updating event detail for {$cabang->nama_cabang}.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Express', $e->getMessage());
            }

            // Model immutability guard also blocks event field updates on APPROVED non-Express reports
            try {
                $report->fresh()->update([
                    'event_note' => 'Bypass Model ' . $cabang->nama_cabang,
                    'event_date' => '2025-10-15',
                ]);
                $this->fail("Expected model immutability exception on APPROVED {$cabang->nama_cabang} report.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('APPROVED', $e->getMessage());
            }

            $fresh = $report->fresh();
            $this->assertNull($fresh->event_note);
            $this->assertNull($fresh->event_date);
            $this->assertSame(SessionReport::STATUS_APPROVED, $fresh->status);
            $this->assertDatabaseMissing('approval_logs', [
                'session_report_id' => $report->id,
                'action' => 'EVENT_DETAIL_UPDATED',
            ]);
        }

        // Golio & Newspaper Janus Booth HPP sync remains completely unaffected
        \App\Models\BoothSessionHppSetting::updateOrCreate(['booth_type' => 'Janus'], ['default_rate_per_sheet' => 2500]);
        \App\Models\BoothSessionHppSetting::updateOrCreate(['booth_type' => 'Golio'], ['default_rate_per_sheet' => 3000]);
        $sync = app(\App\Services\BoothHppService::class)->syncApprovedRecaps();
        $this->assertSame(2, $sync['created']);
    }

    public function test_express_and_express_sharing_profit_detail_modal_shows_event_section_even_when_branch_soft_deleted(): void
    {
        $this->actingAs($this->superAdmin);

        $expressReport = $this->createHistoricalApprovedReport([
            'report_number' => 'SR-EXP-001',
            'cabang_id' => $this->cabangExpress->cabang_id,
            'event_note' => null,
            'event_date' => null,
        ]);

        $sharingProfitReport = $this->createHistoricalApprovedReport([
            'report_number' => 'SR-SHP-001',
            'cabang_id' => $this->cabangExpressSharingProfit->cabang_id,
            'event_note' => 'Sharing Profit Festival',
            'event_date' => '2025-06-14',
        ]);

        // Historical soft-deleted Express branch record must still be recognized via cabang_id lookup
        $this->cabangExpress->delete();

        $this->assertTrue($expressReport->fresh()->supportsEventDetail());
        $this->assertTrue($sharingProfitReport->fresh()->supportsEventDetail());

        Livewire::test(ListSessionHistories::class)
            ->mountTableAction('detail', $expressReport->id)
            ->assertSee('Informasi Event')
            ->assertSee('Belum Ada Data Event')
            ->assertSee('Edit Detail Event');

        Livewire::test(ListSessionHistories::class)
            ->mountTableAction('detail', $sharingProfitReport->id)
            ->assertSee('Informasi Event')
            ->assertSee('Sharing Profit Festival')
            ->assertSee('Data Event Lengkap')
            ->assertSee('Edit Detail Event');
    }
}
