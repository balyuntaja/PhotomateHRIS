<?php

namespace Tests\Feature;

use App\Models\ApprovalLog;
use App\Models\Karyawan;
use App\Models\PricingSetting;
use App\Models\Role;
use App\Models\SessionReport;
use App\Models\SessionTransaction;
use App\Services\PricingService;
use App\Services\SessionWorkflowService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected PricingService $pricingService;
    protected SessionWorkflowService $workflowService;
    protected Karyawan $crewKia;
    protected Karyawan $crewShasha;
    protected Karyawan $supervisor;
    protected Karyawan $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pricingService = app(PricingService::class);
        $this->workflowService = app(SessionWorkflowService::class);

        // Ensure default pricing rule exists
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

        // Ensure roles exist
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web'], ['role_id' => 'R01']);
        Role::firstOrCreate(['name' => 'Karyawan', 'guard_name' => 'web'], ['role_id' => 'R07']);
        Role::firstOrCreate(['name' => 'Supervisor', 'guard_name' => 'web'], ['role_id' => 'R08']);

        // Ensure perusahaan exists
        \App\Models\Perusahaan::firstOrCreate(
            ['perusahaan_id' => 'P01'],
            [
                'nama_perusahaan' => 'Photomate Indonesia',
                'email' => 'info@photomate.id',
                'nomor_telepon' => '08123456789',
                'jam_masuk' => '08:00',
                'jam_pulang' => '17:00',
            ]
        );

        // Create test Super Admin
        $this->superAdmin = Karyawan::firstOrCreate(
            ['karyawan_id' => 'ADM01'],
            [
                'role_id' => 'R01',
                'perusahaan_id' => 'P01',
                'nik' => '1234567890123450',
                'nama_lengkap' => 'Admin Super',
                'email' => 'admin.super@photomate.id',
                'password' => bcrypt('password'),
                'tanggal_lahir' => '1990-01-01',
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Jakarta',
            ]
        );

        // Create test crew Kia
        $this->crewKia = Karyawan::firstOrCreate(
            ['karyawan_id' => 'KIA01'],
            [
                'role_id' => 'R07',
                'perusahaan_id' => 'P01',
                'nik' => '1234567890123451',
                'nama_lengkap' => 'Kia',
                'email' => 'kia@photomate.id',
                'password' => bcrypt('password'),
                'tanggal_lahir' => '2000-01-01',
                'jenis_kelamin' => 'Perempuan',
                'alamat' => 'Malang',
            ]
        );

        // Create test crew Shasha
        $this->crewShasha = Karyawan::firstOrCreate(
            ['karyawan_id' => 'SHA01'],
            [
                'role_id' => 'R07',
                'perusahaan_id' => 'P01',
                'nik' => '1234567890123452',
                'nama_lengkap' => 'Shasha',
                'email' => 'shasha@photomate.id',
                'password' => bcrypt('password'),
                'tanggal_lahir' => '2001-02-02',
                'jenis_kelamin' => 'Perempuan',
                'alamat' => 'Malang',
            ]
        );

        // Create test Supervisor
        $this->supervisor = Karyawan::firstOrCreate(
            ['karyawan_id' => 'SPV01'],
            [
                'role_id' => 'R08',
                'perusahaan_id' => 'P01',
                'nik' => '1234567890123453',
                'nama_lengkap' => 'Budi Supervisor',
                'email' => 'budi.supervisor@photomate.id',
                'password' => bcrypt('password'),
                'tanggal_lahir' => '1995-05-05',
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Malang',
            ]
        );
    }

    /**
     * Test 1: Pricing Calculation Rule Formula
     */
    public function test_pricing_calculation_formula(): void
    {
        // Price -> Session mapping according to business logic:
        // Rp 30.000 -> 1 sesi
        $this->assertEquals(1, $this->pricingService->calculateSessionsFromPrice(30000));

        // Rp 45.000 -> 2 sesi
        $this->assertEquals(2, $this->pricingService->calculateSessionsFromPrice(45000));

        // Rp 67.500 / Rp 70.000 -> 3 sesi
        $this->assertEquals(3, $this->pricingService->calculateSessionsFromPrice(67500));
        $this->assertEquals(3, $this->pricingService->calculateSessionsFromPrice(70000));

        // +22.500 per session pattern after 45.000:
        // Rp 90.000 -> 4 sesi
        $this->assertEquals(4, $this->pricingService->calculateSessionsFromPrice(90000));

        // Rp 112.500 -> 5 sesi
        $this->assertEquals(5, $this->pricingService->calculateSessionsFromPrice(112500));

        // Rp 135.000 -> 6 sesi
        $this->assertEquals(6, $this->pricingService->calculateSessionsFromPrice(135000));

        // Rp 157.500 -> 7 sesi
        $this->assertEquals(7, $this->pricingService->calculateSessionsFromPrice(157500));

        // Rp 180.000 -> 8 sesi
        $this->assertEquals(8, $this->pricingService->calculateSessionsFromPrice(180000));

        // Backward compatibility: calculateSessionPrice
        $this->assertEquals(30000, $this->pricingService->calculateSessionPrice(1));
        $this->assertEquals(45000, $this->pricingService->calculateSessionPrice(2));
        $this->assertEquals(67500, $this->pricingService->calculateSessionPrice(3));
        $this->assertEquals(90000, $this->pricingService->calculateSessionPrice(4));
        $this->assertEquals(180000, $this->pricingService->calculateSessionPrice(8));

        // Invalid session count (< 1) must throw InvalidArgumentException
        $this->expectException(\InvalidArgumentException::class);
        $this->pricingService->calculateSessionPrice(0);
    }

    /**
     * Test 2: Section 38 Example End-to-End Workflow
     */
    public function test_section_38_example_end_to_end_workflow(): void
    {
        // Step 1: Create session report
        // Tanggal: 23 Mei 2026
        // Crew: Kia, Shasha
        $report = SessionReport::create([
            'report_date' => '2026-05-23',
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crewKia->karyawan_id,
            'event_note' => 'Event Photobooth Wedding Test',
        ]);

        $report->crews()->attach([$this->crewKia->karyawan_id, $this->crewShasha->karyawan_id]);

        // Transactions with inputted prices:
        // 1. Tunai - Rp 30.000 -> 1 sesi
        // 2. Tunai - Rp 45.000 -> 2 sesi
        // 3. QRIS - Rp 70.000 -> 3 sesi (promo / alternative price)
        // 4. QRIS - Rp 90.000 -> 4 sesi
        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'CASH',
            'amount' => 30000,
            'session_count' => 0, // Should be calculated by backend
        ]);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'CASH',
            'amount' => 45000,
            'session_count' => 0,
        ]);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'QRIS',
            'amount' => 70000,
            'session_count' => 0,
        ]);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'QRIS',
            'amount' => 90000,
            'session_count' => 0,
        ]);

        // Recalculate via PricingService
        $this->pricingService->recalculateReport($report);
        $report->refresh();

        // Verify Expected Totals:
        // Total Sesi: 1 + 2 + 3 + 4 = 10
        // Total Tunai: 3 sesi, Rp 75.000
        // Total QRIS: 7 sesi, Rp 160.000
        // Grand Total: Rp 235.000
        $this->assertEquals(10, $report->total_sessions);
        $this->assertEquals(4, $report->total_transactions);
        $this->assertEquals(3, $report->total_cash_sessions);
        $this->assertEquals(2, $report->total_cash_transactions);
        $this->assertEquals(75000, $report->total_cash_amount);
        $this->assertEquals(7, $report->total_qris_sessions);
        $this->assertEquals(2, $report->total_qris_transactions);
        $this->assertEquals(160000, $report->total_qris_amount);
        $this->assertEquals(235000, $report->grand_total_amount);
        $this->assertEquals('VALID', $report->validation_status);

        // Step 2: Crew submit
        // Expected status: WAITING_APPROVAL
        $this->workflowService->submit($report, $this->crewKia);
        $report->refresh();

        $this->assertEquals(SessionReport::STATUS_WAITING_APPROVAL, $report->status);
        $this->assertEquals($this->crewKia->karyawan_id, $report->submitted_by);
        $this->assertNotNull($report->submitted_at);

        // Step 3: Supervisor request revision
        // Note: "Pastikan transaksi QRIS terakhir sudah sesuai."
        // Expected status: REVISION
        $this->workflowService->requestRevision($report, $this->supervisor, 'Pastikan transaksi QRIS terakhir sudah sesuai.');
        $report->refresh();

        $this->assertEquals(SessionReport::STATUS_REVISION, $report->status);
        $this->assertEquals('Pastikan transaksi QRIS terakhir sudah sesuai.', $report->revision_note);

        // Step 4: Crew edits & submits again (resubmit)
        // Expected status: WAITING_APPROVAL
        $this->workflowService->submit($report, $this->crewKia, 'Data QRIS telah dicek ulang dan diverifikasi.');
        $report->refresh();

        $this->assertEquals(SessionReport::STATUS_WAITING_APPROVAL, $report->status);

        // Step 5: Supervisor approve
        // Expected status: APPROVED
        $this->workflowService->approve($report, $this->supervisor, 'Validated.');
        $report->refresh();

        $this->assertEquals(SessionReport::STATUS_APPROVED, $report->status);
        $this->assertEquals($this->supervisor->karyawan_id, $report->approved_by);
        $this->assertNotNull($report->approved_at);

        // Step 6: Verify Immutability (Approved data cannot be edited or deleted)
        $this->expectException(\RuntimeException::class);
        $report->event_note = 'Attempt to edit after approved';
        $report->save();
    }

    /**
     * Test 3: Audit Trail Sequence
     */
    public function test_audit_logs_record_complete_trail(): void
    {
        $report = SessionReport::create([
            'report_date' => '2026-05-23',
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crewKia->karyawan_id,
        ]);
        $report->crews()->attach([$this->crewKia->karyawan_id]);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'CASH',
            'session_count' => 1,
            'amount' => 30000,
        ]);

        $this->pricingService->recalculateReport($report);

        // Workflow transitions
        $this->workflowService->submit($report, $this->crewKia);
        $this->workflowService->requestRevision($report, $this->supervisor, 'Catatan revisi.');
        $this->workflowService->submit($report, $this->crewKia, 'Resubmitted note.');
        $this->workflowService->approve($report, $this->supervisor, 'Validated.');

        $logs = ApprovalLog::where('session_report_id', $report->id)
            ->orderBy('id', 'asc')
            ->get();

        $this->assertCount(4, $logs);
        $this->assertEquals('SUBMITTED', $logs[0]->action);
        $this->assertEquals('REVISION_REQUESTED', $logs[1]->action);
        $this->assertEquals('RESUBMITTED', $logs[2]->action);
        $this->assertEquals('APPROVED', $logs[3]->action);
    }

    /**
     * Test 4: Crew Unauthorized Approval Attempt Rejected
     */
    public function test_crew_cannot_approve_report(): void
    {
        $report = SessionReport::create([
            'report_date' => '2026-05-23',
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'submitted_by' => $this->crewKia->karyawan_id,
        ]);
        $report->crews()->attach([$this->crewKia->karyawan_id]);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'CASH',
            'session_count' => 1,
            'amount' => 30000,
        ]);

        // Attempting to approve as crew should throw RuntimeException
        $this->expectException(\RuntimeException::class);
        $this->workflowService->approve($report, $this->crewKia);
    }

    /**
     * Test 5: Backend Recalculates Session Count from Inputted Amount Source of Truth
     */
    public function test_backend_recalculates_client_amounts_source_of_truth(): void
    {
        $report = SessionReport::create([
            'report_date' => '2026-05-23',
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crewKia->karyawan_id,
        ]);
        $report->crews()->attach([$this->crewKia->karyawan_id]);

        // Client attempts to input 70.000 with session_count 1 (should be 3)
        // and 180.000 with session_count 2 (should be 8)
        $trx1 = SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'CASH',
            'session_count' => 1,
            'amount' => 70000,
        ]);

        $trx2 = SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'QRIS',
            'session_count' => 2,
            'amount' => 180000,
        ]);

        $this->pricingService->recalculateReport($report);
        $trx1->refresh();
        $trx2->refresh();
        $report->refresh();

        // Must be mapped by backend to 3 sessions for 70.000 and 8 sessions for 180.000
        $this->assertEquals(3, $trx1->session_count);
        $this->assertEquals(8, $trx2->session_count);
        $this->assertEquals(11, $report->total_sessions);
        $this->assertEquals(70000, $report->total_cash_amount);
        $this->assertEquals(180000, $report->total_qris_amount);
        $this->assertEquals(250000, $report->grand_total_amount);
    }

    /**
     * Test 6: Deleting Approved Report Rejected by Immutability Guard
     */
    public function test_cannot_delete_approved_report(): void
    {
        $report = SessionReport::create([
            'report_date' => '2026-05-23',
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'submitted_by' => $this->crewKia->karyawan_id,
        ]);
        $report->crews()->attach([$this->crewKia->karyawan_id]);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'CASH',
            'session_count' => 1,
            'amount' => 30000,
        ]);

        $this->workflowService->approve($report, $this->supervisor);

        $this->expectException(\RuntimeException::class);
        $report->delete();
    }

    public function test_super_admin_can_delete_approved_report(): void
    {
        $report = SessionReport::create([
            'report_date' => '2026-05-23',
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'submitted_by' => $this->crewKia->karyawan_id,
        ]);
        $report->crews()->attach([$this->crewKia->karyawan_id]);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'CASH',
            'session_count' => 1,
            'amount' => 30000,
        ]);

        $this->workflowService->approve($report, $this->supervisor);

        $this->actingAs($this->superAdmin);
        $this->assertTrue($this->superAdmin->isSuperAdmin());

        $reportId = $report->id;
        $report->delete();

        $this->assertDatabaseMissing('session_reports', ['id' => $reportId]);
        $this->assertDatabaseMissing('session_transactions', ['session_report_id' => $reportId]);
    }

    public function test_delete_action_visibility_in_rekap_sesi_and_history(): void
    {
        $report = SessionReport::create([
            'report_date' => '2026-05-23',
            'status' => SessionReport::STATUS_APPROVED,
            'submitted_by' => $this->crewKia->karyawan_id,
        ]);
        $report->crews()->attach([$this->crewKia->karyawan_id]);

        // Regular crew should NOT see delete action on approved report in rekap sesi or history
        $this->actingAs($this->crewKia);
        \Livewire\Livewire::test(\App\Filament\Resources\SessionReportResource\Pages\ListSessionReports::class)
            ->assertTableActionHidden('delete', $report);

        \Livewire\Livewire::test(\App\Filament\Resources\SessionHistoryResource\Pages\ListSessionHistories::class)
            ->assertTableActionHidden('delete', $report);

        // Super Admin SHOULD see delete action on both rekap sesi and history
        $this->actingAs($this->superAdmin);
        \Livewire\Livewire::test(\App\Filament\Resources\SessionReportResource\Pages\ListSessionReports::class)
            ->assertTableActionVisible('delete', $report);

        \Livewire\Livewire::test(\App\Filament\Resources\SessionHistoryResource\Pages\ListSessionHistories::class)
            ->assertTableActionVisible('delete', $report);
    }

    /**
     * Test 7: Multi-Crew Assignment and Crew Names Formatting
     */
    public function test_multiple_crews_assigned_to_single_report(): void
    {
        $report = SessionReport::create([
            'report_date' => '2026-05-23',
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crewKia->karyawan_id,
        ]);

        $report->crews()->attach([$this->crewKia->karyawan_id, $this->crewShasha->karyawan_id]);

        $this->assertCount(2, $report->crews);
        $this->assertStringContainsString('Kia', $report->crew_names);
        $this->assertStringContainsString('Shasha', $report->crew_names);
    }

    /**
     * Test 8: Dashboard Widget Calculation
     */
    public function test_session_overview_widget_data(): void
    {
        // Create an approved report
        $report1 = SessionReport::create([
            'report_date' => Carbon::today(),
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crewKia->karyawan_id,
        ]);
        $report1->crews()->attach([$this->crewKia->karyawan_id]);
        SessionTransaction::create([
            'session_report_id' => $report1->id,
            'payment_method' => 'CASH',
            'session_count' => 2, // 45.000
            'amount' => 45000,
        ]);
        $this->workflowService->submit($report1, $this->crewKia);
        $this->workflowService->approve($report1, $this->supervisor);

        // Create a waiting approval report
        $report2 = SessionReport::create([
            'report_date' => Carbon::today(),
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crewShasha->karyawan_id,
        ]);
        $report2->crews()->attach([$this->crewShasha->karyawan_id]);
        SessionTransaction::create([
            'session_report_id' => $report2->id,
            'payment_method' => 'QRIS',
            'session_count' => 1, // 30.000
            'amount' => 30000,
        ]);
        $this->workflowService->submit($report2, $this->crewShasha);

        $widget = new \App\Filament\Widgets\SessionOverviewWidget();
        $widget->filter = 'today';
        $data = $widget->getViewData();

        $this->assertEquals(2, $data['totalSessions']);
        $this->assertEquals(45000, $data['grandTotalRevenue']);
        $this->assertEquals(45000, $data['cashRevenue']);
        $this->assertEquals(1, $data['waitingApprovalCount']);
        $this->assertEquals(1, $data['approvedCount']);
    }

    /**
     * Test 9: Cabang Selection and Relationship on Session Report
     */
    public function test_cabang_relationship_on_session_report(): void
    {
        $cabang = \App\Models\Cabang::firstOrCreate(
            ['cabang_id' => 'C0099'],
            [
                'perusahaan_id' => 'P01',
                'nama_cabang' => 'Goliohub',
                'alamat' => 'Malang',
                'latitude' => -7.982,
                'longitude' => 112.631,
                'radius_lokasi' => 100,
            ]
        );

        $report = SessionReport::create([
            'report_date' => Carbon::today(),
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crewKia->karyawan_id,
            'cabang_id' => $cabang->cabang_id,
        ]);

        $this->assertNotNull($report->cabang);
        $this->assertEquals('Goliohub', $report->cabang->nama_cabang);
    }

    public function test_filament_create_and_edit_session_report_form_renders_without_error(): void
    {
        $this->actingAs($this->crewKia);

        \Livewire\Livewire::test(\App\Filament\Resources\SessionReportResource\Pages\CreateSessionReport::class)
            ->assertSuccessful()
            ->assertSee('Sesi')
            ->assertSee('Metode Pembayaran');

        $report = SessionReport::create([
            'report_date' => Carbon::today(),
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crewKia->karyawan_id,
        ]);
        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'CASH',
            'session_count' => 2,
            'amount' => 45000,
        ]);

        \Livewire\Livewire::test(\App\Filament\Resources\SessionReportResource\Pages\EditSessionReport::class, [
            'record' => $report->getKey(),
        ])
            ->assertSuccessful()
            ->assertSee('Sesi')
            ->assertSee('Metode Pembayaran');
    }

    public function test_create_session_report_immediately_submits_to_supervisor(): void
    {
        $this->actingAs($this->crewKia);

        \Livewire\Livewire::test(\App\Filament\Resources\SessionReportResource\Pages\CreateSessionReport::class)
            ->fillForm([
                'report_date' => Carbon::today()->format('Y-m-d'),
                'crews' => [$this->crewKia->karyawan_id],
                'transactions' => [
                    [
                        'payment_method' => 'CASH',
                        'amount' => 30000,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(\App\Filament\Resources\SessionReportResource::getUrl('index'));

        $latestReport = SessionReport::latest('id')->first();
        $this->assertNotNull($latestReport);
        $this->assertEquals(SessionReport::STATUS_WAITING_APPROVAL, $latestReport->status);
        $this->assertEquals($this->crewKia->karyawan_id, $latestReport->submitted_by);
        $this->assertNotNull($latestReport->submitted_at);

        $this->assertDatabaseHas('approval_logs', [
            'session_report_id' => $latestReport->id,
            'action' => 'SUBMITTED',
            'user_id' => $this->crewKia->karyawan_id,
        ]);
    }

    public function test_review_modal_and_view_session_report_render_cleanly(): void
    {
        $this->actingAs($this->crewKia);

        $report = SessionReport::create([
            'report_date' => Carbon::today(),
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'submitted_by' => $this->crewKia->karyawan_id,
            'submitted_at' => now(),
            'validation_status' => 'VALID',
        ]);

        $report->crews()->attach([$this->crewKia->karyawan_id, $this->crewShasha->karyawan_id]);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'CASH',
            'session_count' => 1,
            'amount' => 30000,
        ]);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'QRIS',
            'session_count' => 2,
            'amount' => 45000,
        ]);

        $this->pricingService->recalculateReport($report);

        // Add audit logs
        ApprovalLog::create([
            'session_report_id' => $report->id,
            'user_id' => $this->crewKia->karyawan_id,
            'action' => 'DRAFT_CREATED',
            'note' => 'Draft rekap sesi dibuat.',
            'created_at' => now()->subMinutes(15),
        ]);

        ApprovalLog::create([
            'session_report_id' => $report->id,
            'user_id' => $this->crewKia->karyawan_id,
            'action' => 'DRAFT_UPDATED',
            'note' => 'Perubahan pada draft rekap sesi disimpan.',
            'created_at' => now()->subMinutes(10),
        ]);

        ApprovalLog::create([
            'session_report_id' => $report->id,
            'user_id' => $this->crewKia->karyawan_id,
            'action' => 'SUBMITTED',
            'note' => null,
            'created_at' => now()->subMinutes(5),
        ]);

        // Test blade rendering directly
        $view = $this->view('filament.resources.session-reports.review-modal', ['record' => $report]);
        $view->assertSee('Validasi Otomatis Sistem');
        $view->assertSee('STATUS VALID');
        $view->assertSee('1 Lembar tunai · 1 transaksi', false);
        $view->assertSee('2 Lembar non-tunai · 1 transaksi', false);
        $view->assertSee('Metode Pembayaran');
        $view->assertSee('Jumlah Sesi');
        $view->assertSee('Pricing Rule');
        $view->assertSee('Audit Trail & Riwayat Approval', false);
        $view->assertSee('Draft Dibuat');
        $view->assertSee('Draft Diperbarui');
        $view->assertSee('Diajukan (Submitted)');
        $view->assertSee('Perubahan pada draft rekap sesi disimpan.');

        // Test ViewSessionReport page
        \Livewire\Livewire::test(\App\Filament\Resources\SessionReportResource\Pages\ViewSessionReport::class, [
            'record' => $report->getKey(),
        ])
            ->assertSuccessful()
            ->assertSee('Validasi Otomatis Sistem')
            ->assertSee('Audit Trail');
    }

    /**
     * Test 12: Detailed mapping verification for user requested price -> sessions
     */
    public function test_rekap_sesi_price_to_session_mapping(): void
    {
        $testCases = [
            30000 => 1,
            45000 => 2,
            67500 => 3,
            70000 => 3,
            90000 => 4,
            112500 => 5,
            135000 => 6,
            157500 => 7,
            180000 => 8,
            202500 => 9,
            225000 => 10,
        ];

        foreach ($testCases as $price => $expectedSessions) {
            $this->assertEquals(
                $expectedSessions,
                $this->pricingService->calculateSessionsFromPrice($price),
                "Mapping failed for price Rp " . number_format($price, 0, ',', '.')
            );
        }

        // Test with a SessionReport containing multiple transactions with various prices
        $report = SessionReport::create([
            'report_date' => Carbon::today(),
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crewKia->karyawan_id,
        ]);
        $report->crews()->attach([$this->crewKia->karyawan_id]);

        $prices = [30000, 45000, 67500, 70000, 90000, 112500, 135000, 157500, 180000];
        foreach ($prices as $p) {
            SessionTransaction::create([
                'session_report_id' => $report->id,
                'payment_method' => 'CASH',
                'amount' => $p,
            ]);
        }

        $this->pricingService->recalculateReport($report);
        $report->refresh();

        // Total sessions: 1 + 2 + 3 + 3 + 4 + 5 + 6 + 7 + 8 = 39 sessions
        $this->assertEquals(39, $report->total_sessions);
        $this->assertEquals(count($prices), $report->total_transactions);
        $this->assertEquals(array_sum($prices), $report->grand_total_amount);
        $this->assertEquals('VALID', $report->validation_status);

        // Verify each individual transaction session count
        $transactions = $report->transactions()->orderBy('id')->get();
        $expectedSessionCounts = [1, 2, 3, 3, 4, 5, 6, 7, 8];
        foreach ($transactions as $index => $trx) {
            $this->assertEquals($expectedSessionCounts[$index], $trx->session_count);
            $this->assertEquals($prices[$index], (int) $trx->amount);
        }
    }

    /**
     * Test 13: Newspaper Janus Bonus Rules & Crew Distribution
     */
    public function test_newspaper_janus_bonus_rules_and_crew_distribution(): void
    {
        $bonusService = app(\App\Services\BranchBonusService::class);

        $cabangJanus = \App\Models\Cabang::create([
            'cabang_id' => 'C-NJ',
            'perusahaan_id' => 'P01',
            'nama_cabang' => 'Newspaper Janus',
        ]);

        $this->assertTrue($bonusService->isEligibleBranch($cabangJanus));

        // 1. Below 30 sessions (29 sessions) -> Bonus = 0
        $belowTarget = $bonusService->calculateDailyBonus(29, 2, $cabangJanus);
        $this->assertFalse($belowTarget['target_reached']);
        $this->assertEquals(0, $belowTarget['total_bonus']);
        $this->assertEquals(0, $belowTarget['bonus_per_crew']);
        $this->assertEquals(1, $belowTarget['remaining_sessions']);

        // 2. Exact 30 sessions with 1 crew -> Bonus = Rp 50.000, 1 crew gets Rp 50.000
        $exactTarget1Crew = $bonusService->calculateDailyBonus(30, 1, $cabangJanus);
        $this->assertTrue($exactTarget1Crew['target_reached']);
        $this->assertEquals(50000, $exactTarget1Crew['total_bonus']);
        $this->assertEquals(50000, $exactTarget1Crew['bonus_per_crew']);

        // 3. Exact 30 sessions with 2 crews -> Bonus = Rp 50.000, each gets Rp 25.000
        $exactTarget2Crews = $bonusService->calculateDailyBonus(30, 2, $cabangJanus);
        $this->assertTrue($exactTarget2Crews['target_reached']);
        $this->assertEquals(50000, $exactTarget2Crews['total_bonus']);
        $this->assertEquals(25000, $exactTarget2Crews['bonus_per_crew']);

        // 4. Above 30 sessions (31 sessions & 40 sessions) -> Bonus remains flat Rp 50.000
        $aboveTarget31 = $bonusService->calculateDailyBonus(31, 2, $cabangJanus);
        $this->assertEquals(50000, $aboveTarget31['total_bonus']);
        $this->assertEquals(25000, $aboveTarget31['bonus_per_crew']);

        $aboveTarget40 = $bonusService->calculateDailyBonus(40, 2, $cabangJanus);
        $this->assertEquals(50000, $aboveTarget40['total_bonus']);
        $this->assertEquals(25000, $aboveTarget40['bonus_per_crew']);

        // 5. 3 crews with >= 30 sessions -> Rp 50.000 / 3
        $threeCrews = $bonusService->calculateDailyBonus(35, 3, $cabangJanus);
        $this->assertEquals(50000, $threeCrews['total_bonus']);
        $this->assertEquals((int) round(50000 / 3), $threeCrews['bonus_per_crew']);

        // Test with SessionReport model and recalculateReport
        $report = SessionReport::create([
            'report_date' => Carbon::today(),
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crewKia->karyawan_id,
            'cabang_id' => $cabangJanus->cabang_id,
        ]);
        $report->crews()->attach([$this->crewKia->karyawan_id, $this->crewShasha->karyawan_id]);

        // Add transactions totaling 30 sessions
        // 30 sessions @ 30.000 = 1 session each x 30
        for ($i = 0; $i < 30; $i++) {
            SessionTransaction::create([
                'session_report_id' => $report->id,
                'payment_method' => 'CASH',
                'amount' => 30000,
            ]);
        }

        $this->pricingService->recalculateReport($report);
        $report->refresh();

        $this->assertEquals(30, $report->total_sessions);
        $this->assertEquals(50000, $report->bonus_amount);
        $this->assertTrue($report->isNewspaperJanus());

        $bonusDetails = $report->getBonusDetails();
        $this->assertTrue($bonusDetails['target_reached']);
        $this->assertEquals(50000, $bonusDetails['total_bonus']);
        $this->assertEquals(25000, $bonusDetails['bonus_per_crew']);
        $this->assertCount(2, $bonusDetails['crew_breakdown']);
        $this->assertEquals(25000, $bonusDetails['crew_breakdown'][0]['bonus']);
        $this->assertEquals(25000, $bonusDetails['crew_breakdown'][1]['bonus']);
    }

    /**
     * Test 14: Photomate Express is explicitly NOT eligible for the bonus
     */
    public function test_photomate_express_is_not_eligible_for_bonus(): void
    {
        $bonusService = app(\App\Services\BranchBonusService::class);

        $cabangExpress = \App\Models\Cabang::create([
            'cabang_id' => 'C-PE',
            'perusahaan_id' => 'P01',
            'nama_cabang' => 'Photomate Express',
        ]);

        $this->assertFalse($bonusService->isEligibleBranch($cabangExpress));

        // Even with 40 sessions and 2 crews, bonus must remain 0
        $result = $bonusService->calculateDailyBonus(40, 2, $cabangExpress);
        $this->assertFalse($result['is_eligible_branch']);
        $this->assertFalse($result['target_reached']);
        $this->assertEquals(0, $result['total_bonus']);
        $this->assertEquals(0, $result['bonus_per_crew']);

        // Test with SessionReport model
        $report = SessionReport::create([
            'report_date' => Carbon::today(),
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crewKia->karyawan_id,
            'cabang_id' => $cabangExpress->cabang_id,
        ]);
        $report->crews()->attach([$this->crewKia->karyawan_id, $this->crewShasha->karyawan_id]);

        for ($i = 0; $i < 35; $i++) {
            SessionTransaction::create([
                'session_report_id' => $report->id,
                'payment_method' => 'CASH',
                'amount' => 30000,
            ]);
        }

        $this->pricingService->recalculateReport($report);
        $report->refresh();

        $this->assertEquals(35, $report->total_sessions);
        $this->assertEquals(0, $report->bonus_amount);
        $this->assertFalse($report->isNewspaperJanus());

        $details = $report->getBonusDetails();
        $this->assertFalse($details['is_eligible_branch']);
        $this->assertEquals(0, $details['total_bonus']);
    }

    /**
     * Test 15: Review Modal renders Newspaper Janus bonus details cleanly
     */
    public function test_newspaper_janus_bonus_display_in_review_modal(): void
    {
        $this->actingAs($this->crewKia);

        $cabangJanus = \App\Models\Cabang::create([
            'cabang_id' => 'C-JAN',
            'perusahaan_id' => 'P01',
            'nama_cabang' => 'Newspaper Janus',
        ]);

        $report = SessionReport::create([
            'report_date' => Carbon::today(),
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crewKia->karyawan_id,
            'cabang_id' => $cabangJanus->cabang_id,
        ]);
        $report->crews()->attach([$this->crewKia->karyawan_id, $this->crewShasha->karyawan_id]);

        // 30 sessions
        for ($i = 0; $i < 30; $i++) {
            SessionTransaction::create([
                'session_report_id' => $report->id,
                'payment_method' => 'CASH',
                'amount' => 30000,
            ]);
        }

        $this->pricingService->recalculateReport($report);
        $report->refresh();

        $view = $this->view('filament.resources.session-reports.review-modal', ['record' => $report]);
        $view->assertSee('Bonus Crew Cabang Newspaper Janus');
        $view->assertSee('TARGET HARIAN TERCAPAI (≥ 30 SESI)');
        $view->assertSee('Rp 50.000');
        $view->assertSee('Rp 25.000');
        $view->assertSee('Kia');
        $view->assertSee('Shasha');
    }

    /**
     * Helper: cabang Wedding Photomate (mode input manual Jumlah Sesi + Jumlah Lembar)
     */
    protected function weddingCabang(): \App\Models\Cabang
    {
        return \App\Models\Cabang::firstOrCreate(
            ['cabang_id' => 'C-WED'],
            [
                'perusahaan_id' => 'P01',
                'nama_cabang' => 'Wedding Photomate',
                'alamat' => 'Wedding Photomate Studio',
            ]
        );
    }

    protected function janusCabang(): \App\Models\Cabang
    {
        return \App\Models\Cabang::firstOrCreate(
            ['cabang_id' => 'C-JAN'],
            [
                'perusahaan_id' => 'P01',
                'nama_cabang' => 'Newspaper Janus',
            ]
        );
    }

    protected function createWeddingReport(array $attributes = []): SessionReport
    {
        return SessionReport::create(array_merge([
            'report_date' => Carbon::today(),
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crewKia->karyawan_id,
            'cabang_id' => $this->weddingCabang()->cabang_id,
            'wedding_total_sessions' => 150,
            'wedding_total_sheets' => 300,
        ], $attributes));
    }

    /**
     * Test 16: Wedding Photomate memakai input manual Jumlah Sesi & Jumlah Lembar
     */
    public function test_wedding_photomate_report_uses_manual_session_and_sheet_totals(): void
    {
        $this->actingAs($this->crewKia);

        $report = $this->createWeddingReport();
        $report->crews()->attach($this->crewKia->karyawan_id);

        $this->pricingService->recalculateReport($report);
        $report->refresh();

        $this->assertTrue($report->usesManualSessionInput());
        $this->assertFalse($report->isNewspaperJanus());
        $this->assertEquals(150, $report->total_transactions);
        $this->assertEquals(300, $report->total_sessions);
        $this->assertEquals(0, $report->total_cash_transactions);
        $this->assertEquals(0, $report->total_qris_transactions);
        $this->assertEquals(0, $report->total_cash_amount);
        $this->assertEquals(0, $report->total_qris_amount);
        $this->assertEquals(0, $report->grand_total_amount);
        $this->assertEquals(0, $report->bonus_amount);
        $this->assertEquals('VALID', $report->validation_status);
    }

    /**
     * Test 17: Rekap Wedding Photomate bisa disubmit tanpa detail transaksi
     */
    public function test_wedding_photomate_report_submits_without_transactions(): void
    {
        $this->actingAs($this->crewKia);

        $report = $this->createWeddingReport();
        $report->crews()->attach($this->crewKia->karyawan_id);

        $this->workflowService->submit($report, $this->crewKia);
        $report->refresh();

        $this->assertEquals(SessionReport::STATUS_WAITING_APPROVAL, $report->status);
        $this->assertEquals(0, $report->transactions()->count());
        $this->assertEquals(150, $report->total_transactions);
        $this->assertEquals(300, $report->total_sessions);

        $this->assertDatabaseHas('approval_logs', [
            'session_report_id' => $report->id,
            'action' => 'SUBMITTED',
            'user_id' => $this->crewKia->karyawan_id,
        ]);
    }

    /**
     * Test 18: Jumlah Sesi & Jumlah Lembar wajib diisi minimal 1 (Wedding Photomate)
     */
    public function test_wedding_photomate_requires_jumlah_sesi_and_jumlah_lembar(): void
    {
        $this->actingAs($this->crewKia);

        $report = $this->createWeddingReport([
            'wedding_total_sessions' => null,
            'wedding_total_sheets' => null,
        ]);
        $report->crews()->attach($this->crewKia->karyawan_id);

        $this->pricingService->recalculateReport($report);
        $report->refresh();

        $this->assertEquals('HAS_ISSUE', $report->validation_status);
        $this->assertContains('Jumlah Sesi wajib diisi minimal 1.', $report->validation_issues);
        $this->assertContains('Jumlah Lembar wajib diisi minimal 1.', $report->validation_issues);

        $this->expectException(\InvalidArgumentException::class);
        $this->workflowService->submit($report, $this->crewKia);
    }

    /**
     * Test 19: Cabang non-Wedding tetap memakai flow lama & membersihkan nilai manual Wedding
     */
    public function test_non_wedding_branch_ignores_and_clears_wedding_values(): void
    {
        $this->actingAs($this->crewKia);

        $report = SessionReport::create([
            'report_date' => Carbon::today(),
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crewKia->karyawan_id,
            'cabang_id' => $this->janusCabang()->cabang_id,
            'wedding_total_sessions' => 99,
            'wedding_total_sheets' => 99,
        ]);
        $report->crews()->attach($this->crewKia->karyawan_id);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'CASH',
            'amount' => 30000,
        ]);

        $this->pricingService->recalculateReport($report);
        $report->refresh();

        $this->assertFalse($report->usesManualSessionInput());
        $this->assertNull($report->wedding_total_sessions);
        $this->assertNull($report->wedding_total_sheets);
        $this->assertEquals(1, $report->total_transactions);
        $this->assertEquals(1, $report->total_sessions);
        $this->assertEquals(30000, $report->grand_total_amount);
        $this->assertEquals('VALID', $report->validation_status);
    }

    /**
     * Test 20: Evidence disimpan sebagai array path & diekspos sebagai item siap render
     */
    public function test_evidence_files_are_exposed_as_items(): void
    {
        $this->actingAs($this->crewKia);

        $report = $this->createWeddingReport([
            'evidence_files' => [
                'session-reports/evidence/foto-sesi.jpg',
                'session-reports/evidence/bukti-transfer.pdf',
            ],
        ]);
        $report->refresh();

        $this->assertCount(2, $report->evidence_files);

        $items = $report->evidence_items;
        $this->assertCount(2, $items);

        $this->assertEquals('foto-sesi.jpg', $items[0]['name']);
        $this->assertTrue($items[0]['is_image']);
        $this->assertEquals(asset('storage/session-reports/evidence/foto-sesi.jpg'), $items[0]['url']);

        $this->assertEquals('bukti-transfer.pdf', $items[1]['name']);
        $this->assertFalse($items[1]['is_image']);
    }

    /**
     * Test 21: Evidence opsional — rekap tetap bisa disimpan & disubmit tanpa evidence
     */
    public function test_evidence_is_optional(): void
    {
        $this->actingAs($this->crewKia);

        $report = $this->createWeddingReport();
        $report->crews()->attach($this->crewKia->karyawan_id);
        $report->refresh();

        $this->assertNull($report->evidence_files);
        $this->assertSame([], $report->evidence_items);

        $this->workflowService->submit($report, $this->crewKia);
        $report->refresh();

        $this->assertEquals(SessionReport::STATUS_WAITING_APPROVAL, $report->status);
    }

    /**
     * Test 22: Form Rekap Sesi menampilkan input Wedding hanya untuk cabang Wedding Photomate
     */
    public function test_filament_form_shows_wedding_inputs_only_for_wedding_branch(): void
    {
        $this->actingAs($this->crewKia);

        \Livewire\Livewire::test(\App\Filament\Resources\SessionReportResource\Pages\CreateSessionReport::class)
            ->fillForm(['cabang_id' => $this->weddingCabang()->cabang_id])
            ->assertFormFieldIsVisible('wedding_total_sessions')
            ->assertFormFieldIsVisible('wedding_total_sheets')
            ->assertFormFieldIsHidden('transactions')
            ->assertSee('Upload Evidence');

        \Livewire\Livewire::test(\App\Filament\Resources\SessionReportResource\Pages\CreateSessionReport::class)
            ->fillForm(['cabang_id' => $this->janusCabang()->cabang_id])
            ->assertFormFieldIsHidden('wedding_total_sessions')
            ->assertFormFieldIsHidden('wedding_total_sheets')
            ->assertFormFieldIsVisible('transactions')
            ->assertSee('Upload Evidence');
    }

    /**
     * Test 23: Review modal menampilkan ringkasan Wedding + evidence
     */
    public function test_review_modal_renders_wedding_summary_and_evidence(): void
    {
        $this->actingAs($this->crewKia);

        $report = $this->createWeddingReport([
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'submitted_at' => now(),
            'validation_status' => 'VALID',
            'evidence_files' => ['session-reports/evidence/foto-sesi.jpg'],
        ]);
        $report->crews()->attach($this->crewKia->karyawan_id);

        $this->pricingService->recalculateReport($report);
        $report->refresh();

        $view = $this->view('filament.resources.session-reports.review-modal', ['record' => $report]);
        $view->assertSee('Ringkasan Sesi Foto (Wedding Photomate)');
        $view->assertSee('Jumlah Sesi');
        $view->assertSee('150');
        $view->assertSee('Jumlah Lembar');
        $view->assertSee('300');
        $view->assertSee('Evidence / Bukti Pendukung (1)');
        $view->assertSee('foto-sesi.jpg');
        $view->assertSee('STATUS VALID');
        $view->assertDontSee('Detail Transaksi');
    }

    /**
     * Test 24: Create Rekap Sesi cabang Wedding Photomate end-to-end lewat form Filament
     */
    public function test_create_wedding_report_through_filament_form(): void
    {
        $this->actingAs($this->crewKia);

        $weddingCabangId = $this->weddingCabang()->cabang_id;

        \Livewire\Livewire::test(\App\Filament\Resources\SessionReportResource\Pages\CreateSessionReport::class)
            ->fillForm([
                'report_date' => Carbon::today()->format('Y-m-d'),
                'cabang_id' => $weddingCabangId,
                'crews' => [$this->crewKia->karyawan_id],
            ])
            ->fillForm([
                'wedding_total_sessions' => 150,
                'wedding_total_sheets' => 300,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $report = SessionReport::latest('id')->first();

        $this->assertNotNull($report);
        $this->assertEquals($weddingCabangId, $report->cabang_id);
        $this->assertEquals(150, $report->wedding_total_sessions);
        $this->assertEquals(300, $report->wedding_total_sheets);
        $this->assertEquals(150, $report->total_transactions);
        $this->assertEquals(300, $report->total_sessions);
        $this->assertEquals(0, $report->transactions()->count());
        $this->assertEquals(SessionReport::STATUS_WAITING_APPROVAL, $report->status);

        $this->assertDatabaseHas('approval_logs', [
            'session_report_id' => $report->id,
            'action' => 'SUBMITTED',
        ]);
    }

    /**
     * Test 25: Form Wedding Photomate menolak submit bila Jumlah Sesi / Jumlah Lembar kosong
     */
    public function test_wedding_form_requires_jumlah_sesi_and_jumlah_lembar(): void
    {
        $this->actingAs($this->crewKia);

        \Livewire\Livewire::test(\App\Filament\Resources\SessionReportResource\Pages\CreateSessionReport::class)
            ->fillForm([
                'report_date' => Carbon::today()->format('Y-m-d'),
                'cabang_id' => $this->weddingCabang()->cabang_id,
                'crews' => [$this->crewKia->karyawan_id],
            ])
            ->call('create')
            ->assertHasFormErrors(['wedding_total_sessions', 'wedding_total_sheets']);

        $this->assertEquals(0, SessionReport::count());
    }

    /**
     * Helper: cabang Express Sewa (mode input manual Jumlah Sesi + Jumlah Lembar)
     */
    protected function expressSewaCabang(): \App\Models\Cabang
    {
        return \App\Models\Cabang::firstOrCreate(
            ['cabang_id' => 'C-EXP-SEWA'],
            [
                'perusahaan_id' => 'P01',
                'nama_cabang' => 'Express Sewa',
                'alamat' => 'Express Sewa Outlet',
            ]
        );
    }

    /**
     * Helper: cabang Express Self Run (mode input manual Jumlah Sesi + Jumlah Lembar)
     */
    protected function expressSelfRunCabang(): \App\Models\Cabang
    {
        return \App\Models\Cabang::firstOrCreate(
            ['cabang_id' => 'C-EXP-SELF'],
            [
                'perusahaan_id' => 'P01',
                'nama_cabang' => 'Express Self Run',
                'alamat' => 'Express Self Run Outlet',
            ]
        );
    }

    /**
     * Helper: cabang Photomate Express (tetap memakai detail transaksi per sesi)
     */
    protected function photomateExpressCabang(): \App\Models\Cabang
    {
        return \App\Models\Cabang::firstOrCreate(
            ['cabang_id' => 'C-EXP-DETAIL'],
            [
                'perusahaan_id' => 'P01',
                'nama_cabang' => 'Photomate Express',
            ]
        );
    }

    /**
     * Test 26: Deteksi cabang mode input manual (Wedding Photomate, Express Sewa, Express Self Run)
     */
    public function test_manual_input_mode_detection_for_branches(): void
    {
        $service = app(\App\Services\BranchInputModeService::class);

        $this->assertTrue($service->usesManualSessionInput($this->weddingCabang()));
        $this->assertTrue($service->usesManualSessionInput($this->expressSewaCabang()));
        $this->assertTrue($service->usesManualSessionInput($this->expressSelfRunCabang()));
        $this->assertFalse($service->usesManualSessionInput($this->photomateExpressCabang()));
        $this->assertFalse($service->usesManualSessionInput($this->janusCabang()));
        $this->assertFalse($service->usesManualSessionInput(null));
    }

    /**
     * Test 27: Express Sewa & Express Self Run memakai input manual Jumlah Sesi & Jumlah Lembar
     */
    public function test_express_branches_use_manual_session_and_sheet_totals(): void
    {
        $this->actingAs($this->crewKia);

        foreach ([$this->expressSewaCabang(), $this->expressSelfRunCabang()] as $cabang) {
            $report = SessionReport::create([
                'report_date' => Carbon::today(),
                'status' => SessionReport::STATUS_DRAFT,
                'submitted_by' => $this->crewKia->karyawan_id,
                'cabang_id' => $cabang->cabang_id,
                'wedding_total_sessions' => 120,
                'wedding_total_sheets' => 240,
            ]);
            $report->crews()->attach($this->crewKia->karyawan_id);

            $this->pricingService->recalculateReport($report);
            $report->refresh();

            $this->assertTrue($report->usesManualSessionInput());
            $this->assertFalse($report->isNewspaperJanus());
            $this->assertEquals(120, $report->total_transactions);
            $this->assertEquals(240, $report->total_sessions);
            $this->assertEquals(0, $report->total_cash_transactions);
            $this->assertEquals(0, $report->total_qris_transactions);
            $this->assertEquals(0, $report->total_cash_amount);
            $this->assertEquals(0, $report->total_qris_amount);
            $this->assertEquals(0, $report->grand_total_amount);
            $this->assertEquals(0, $report->bonus_amount);
            $this->assertEquals('VALID', $report->validation_status);

            // Rekap bisa disubmit tanpa detail transaksi
            $this->workflowService->submit($report, $this->crewKia);
            $report->refresh();

            $this->assertEquals(SessionReport::STATUS_WAITING_APPROVAL, $report->status);
            $this->assertEquals(0, $report->transactions()->count());

            // Review modal menampilkan ringkasan manual dengan nama cabang
            $view = $this->view('filament.resources.session-reports.review-modal', ['record' => $report]);
            $view->assertSee('Ringkasan Sesi Foto (' . $cabang->nama_cabang . ')');
            $view->assertSee('Jumlah Sesi');
            $view->assertSee('Jumlah Lembar');
            $view->assertDontSee('Detail Transaksi');
        }
    }

    /**
     * Test 28: Form Rekap Sesi menampilkan input manual untuk Express Sewa & Express Self Run
     */
    public function test_filament_form_shows_manual_inputs_for_express_branches(): void
    {
        $this->actingAs($this->crewKia);

        foreach ([$this->expressSewaCabang()->cabang_id, $this->expressSelfRunCabang()->cabang_id] as $cabangId) {
            \Livewire\Livewire::test(\App\Filament\Resources\SessionReportResource\Pages\CreateSessionReport::class)
                ->fillForm(['cabang_id' => $cabangId])
                ->assertFormFieldIsVisible('wedding_total_sessions')
                ->assertFormFieldIsVisible('wedding_total_sheets')
                ->assertFormFieldIsHidden('transactions')
                ->assertSee('Upload Evidence');
        }
    }

    /**
     * Test 29: Create Rekap Sesi Express Sewa end-to-end lewat form Filament
     */
    public function test_create_express_sewa_report_through_filament_form(): void
    {
        $this->actingAs($this->crewKia);

        $cabangId = $this->expressSewaCabang()->cabang_id;

        \Livewire\Livewire::test(\App\Filament\Resources\SessionReportResource\Pages\CreateSessionReport::class)
            ->fillForm([
                'report_date' => Carbon::today()->format('Y-m-d'),
                'cabang_id' => $cabangId,
                'crews' => [$this->crewKia->karyawan_id],
            ])
            ->fillForm([
                'wedding_total_sessions' => 90,
                'wedding_total_sheets' => 180,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $report = SessionReport::latest('id')->first();

        $this->assertNotNull($report);
        $this->assertEquals($cabangId, $report->cabang_id);
        $this->assertEquals(90, $report->wedding_total_sessions);
        $this->assertEquals(180, $report->wedding_total_sheets);
        $this->assertEquals(90, $report->total_transactions);
        $this->assertEquals(180, $report->total_sessions);
        $this->assertEquals(0, $report->transactions()->count());
        $this->assertEquals(SessionReport::STATUS_WAITING_APPROVAL, $report->status);
    }

    /**
     * Test 30: Photomate Express tetap memakai detail transaksi (bukan mode manual)
     */
    public function test_photomate_express_keeps_detailed_transaction_mode(): void
    {
        $this->actingAs($this->crewKia);

        $report = SessionReport::create([
            'report_date' => Carbon::today(),
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crewKia->karyawan_id,
            'cabang_id' => $this->photomateExpressCabang()->cabang_id,
            'wedding_total_sessions' => 99,
            'wedding_total_sheets' => 99,
        ]);
        $report->crews()->attach($this->crewKia->karyawan_id);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'CASH',
            'amount' => 30000,
        ]);

        $this->pricingService->recalculateReport($report);
        $report->refresh();

        $this->assertFalse($report->usesManualSessionInput());
        $this->assertNull($report->wedding_total_sessions);
        $this->assertNull($report->wedding_total_sheets);
        $this->assertEquals(1, $report->total_transactions);
        $this->assertEquals(1, $report->total_sessions);
        $this->assertEquals(30000, $report->grand_total_amount);
    }
}
