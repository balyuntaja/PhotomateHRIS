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
        // 1 session = Rp 30.000
        $this->assertEquals(30000, $this->pricingService->calculateSessionPrice(1));

        // 2 sessions = Rp 45.000
        $this->assertEquals(45000, $this->pricingService->calculateSessionPrice(2));

        // 3 sessions = Rp 60.000
        $this->assertEquals(60000, $this->pricingService->calculateSessionPrice(3));

        // 4 sessions = Rp 75.000
        $this->assertEquals(75000, $this->pricingService->calculateSessionPrice(4));

        // 8 sessions = Rp 135.000
        $this->assertEquals(135000, $this->pricingService->calculateSessionPrice(8));

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

        // Transactions:
        // 1. Tunai - 1 sesi - Rp 30.000
        // 2. Tunai - 2 sesi - Rp 45.000
        // 3. QRIS - 3 sesi - Rp 60.000
        // 4. QRIS - 4 sesi - Rp 75.000
        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'CASH',
            'session_count' => 1,
            'amount' => 0, // Should be recalculated by backend
        ]);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'CASH',
            'session_count' => 2,
            'amount' => 0,
        ]);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'QRIS',
            'session_count' => 3,
            'amount' => 0,
        ]);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'QRIS',
            'session_count' => 4,
            'amount' => 0,
        ]);

        // Recalculate via PricingService
        $this->pricingService->recalculateReport($report);
        $report->refresh();

        // Verify Expected Totals from Section 38:
        // Total Sesi: 10
        // Total Tunai: 3 sesi, Rp 75.000
        // Total QRIS: 7 sesi, Rp 135.000
        // Grand Total: Rp 210.000
        $this->assertEquals(10, $report->total_sessions);
        $this->assertEquals(4, $report->total_transactions);
        $this->assertEquals(3, $report->total_cash_sessions);
        $this->assertEquals(75000, $report->total_cash_amount);
        $this->assertEquals(7, $report->total_qris_sessions);
        $this->assertEquals(135000, $report->total_qris_amount);
        $this->assertEquals(210000, $report->grand_total_amount);
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
     * Test 5: Backend Amount Recalculation (Client Input Tampering Guard)
     * Backend MUST recalculate amount and NEVER trust client-provided numbers.
     */
    public function test_backend_recalculates_client_amounts_source_of_truth(): void
    {
        $report = SessionReport::create([
            'report_date' => '2026-05-23',
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crewKia->karyawan_id,
        ]);
        $report->crews()->attach([$this->crewKia->karyawan_id]);

        // Client maliciously attempts to set 8 sessions as Rp 5.000 instead of Rp 135.000
        $trx = SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'QRIS',
            'session_count' => 8,
            'amount' => 5000,
        ]);

        $this->pricingService->recalculateReport($report);
        $trx->refresh();
        $report->refresh();

        // Must be corrected by backend to 135.000 (30000 + 7 * 15000)
        $this->assertEquals(135000, $trx->amount);
        $this->assertEquals(135000, $report->total_qris_amount);
        $this->assertEquals(135000, $report->grand_total_amount);
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
            ->assertSuccessful();

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
            ->assertSuccessful();
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
}
