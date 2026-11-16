<?php

namespace Tests\Feature;

use App\Filament\Resources\SessionApprovalResource\Pages\ListSessionApprovals;
use App\Filament\Resources\SessionHistoryResource\Pages\ListSessionHistories;
use App\Livewire\SessionReportApproval;
use App\Models\Karyawan;
use App\Models\PricingSetting;
use App\Models\Role;
use App\Models\SessionReport;
use App\Models\SessionTransaction;
use App\Services\SessionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SessionReportApprovalButtonTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $crew;
    protected Karyawan $supervisor;
    protected Karyawan $admin;

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

        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web'], ['role_id' => 'R01']);
        Role::firstOrCreate(['name' => 'Karyawan', 'guard_name' => 'web'], ['role_id' => 'R07']);
        Role::firstOrCreate(['name' => 'Supervisor', 'guard_name' => 'web'], ['role_id' => 'R08']);

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

        $this->crew = Karyawan::create([
            'karyawan_id' => 'KIA01',
            'role_id' => 'R07',
            'perusahaan_id' => 'P01',
            'nik' => '1234567890123451',
            'nama_lengkap' => 'Kia',
            'email' => 'kia@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '2000-01-01',
            'jenis_kelamin' => 'Perempuan',
            'alamat' => 'Malang',
        ]);

        $this->supervisor = Karyawan::create([
            'karyawan_id' => 'SPV01',
            'role_id' => 'R08',
            'perusahaan_id' => 'P01',
            'nik' => '1234567890123453',
            'nama_lengkap' => 'Budi Supervisor',
            'email' => 'budi.supervisor@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1995-05-05',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);

        $this->admin = Karyawan::create([
            'karyawan_id' => 'ADM01',
            'role_id' => 'R01',
            'perusahaan_id' => 'P01',
            'nik' => '1234567890123450',
            'nama_lengkap' => 'Admin Super',
            'email' => 'admin.super@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jakarta',
        ]);
    }

    protected function makeReport(string $status = SessionReport::STATUS_WAITING_APPROVAL): SessionReport
    {
        $report = SessionReport::create([
            'report_date' => now()->toDateString(),
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->crew->karyawan_id,
        ]);

        $report->crews()->attach([$this->crew->karyawan_id]);

        if ($status === SessionReport::STATUS_WAITING_APPROVAL) {
            SessionTransaction::create([
                'session_report_id' => $report->id,
                'payment_method' => 'CASH',
                'amount' => 30000,
            ]);

            app(SessionWorkflowService::class)->submit($report, $this->crew);
        } else {
            $report->update(['status' => $status]);
        }

        return $report->fresh();
    }

    public function test_approve_button_is_shown_to_supervisor_for_waiting_approval_report(): void
    {
        $report = $this->makeReport();
        $this->actingAs($this->supervisor);

        Livewire::test(SessionReportApproval::class, ['reportId' => $report->id])
            ->assertSee('Waiting Approval')
            ->assertSeeHtml('wire:click="approve"');
    }

    public function test_approve_button_is_shown_to_admin_for_waiting_approval_report(): void
    {
        $report = $this->makeReport();
        $this->actingAs($this->admin);

        Livewire::test(SessionReportApproval::class, ['reportId' => $report->id])
            ->assertSee('Waiting Approval')
            ->assertSeeHtml('wire:click="approve"');
    }

    public function test_approve_button_is_hidden_from_crew(): void
    {
        $report = $this->makeReport();
        $this->actingAs($this->crew);

        Livewire::test(SessionReportApproval::class, ['reportId' => $report->id])
            ->assertSee('Waiting Approval')
            ->assertDontSeeHtml('wire:click="approve"');
    }

    public function test_approve_button_is_hidden_when_report_is_not_waiting_approval(): void
    {
        $revisionReport = $this->makeReport(SessionReport::STATUS_REVISION);

        $approvedReport = $this->makeReport();
        app(SessionWorkflowService::class)->approve($approvedReport, $this->supervisor);

        $this->actingAs($this->supervisor);

        Livewire::test(SessionReportApproval::class, ['reportId' => $revisionReport->id])
            ->assertSee('Revision')
            ->assertDontSeeHtml('wire:click="approve"');

        Livewire::test(SessionReportApproval::class, ['reportId' => $approvedReport->id])
            ->assertSee('Approved')
            ->assertDontSeeHtml('wire:click="approve"');
    }

    public function test_supervisor_approves_report_and_status_updates_in_place(): void
    {
        $report = $this->makeReport();
        $this->actingAs($this->supervisor);

        Livewire::test(SessionReportApproval::class, ['reportId' => $report->id])
            ->call('approve')
            ->assertNotified('Rekap sesi berhasil disetujui.')
            ->assertSee('Approved')
            ->assertDontSeeHtml('wire:click="approve"');

        $report->refresh();
        $this->assertEquals(SessionReport::STATUS_APPROVED, $report->status);
        $this->assertEquals($this->supervisor->karyawan_id, $report->approved_by);
        $this->assertNotNull($report->approved_at);
    }

    public function test_crew_cannot_approve_report_from_modal(): void
    {
        $report = $this->makeReport();
        $this->actingAs($this->crew);

        Livewire::test(SessionReportApproval::class, ['reportId' => $report->id])
            ->call('approve')
            ->assertNotified('Gagal menyetujui rekap sesi.');

        $report->refresh();
        $this->assertEquals(SessionReport::STATUS_WAITING_APPROVAL, $report->status);
        $this->assertNull($report->approved_by);
        $this->assertNull($report->approved_at);
    }

    public function test_approve_is_rejected_when_report_is_not_waiting_approval(): void
    {
        $report = $this->makeReport(SessionReport::STATUS_REVISION);
        $this->actingAs($this->supervisor);

        Livewire::test(SessionReportApproval::class, ['reportId' => $report->id])
            ->call('approve')
            ->assertNotified('Gagal menyetujui rekap sesi.');

        $this->assertEquals(SessionReport::STATUS_REVISION, $report->fresh()->status);
    }

    public function test_review_modal_on_approval_page_shows_approve_button(): void
    {
        $report = $this->makeReport();
        $this->actingAs($this->supervisor);

        Livewire::test(ListSessionApprovals::class)
            ->mountTableAction('review', $report->id)
            ->assertSee('Approve');
    }

    public function test_detail_modal_on_history_page_shows_approve_button(): void
    {
        $report = $this->makeReport();
        $this->actingAs($this->supervisor);

        Livewire::test(ListSessionHistories::class)
            ->mountTableAction('detail', $report->id)
            ->assertSee('Approve');
    }

    public function test_revisi_and_approve_buttons_are_shown_side_by_side_for_waiting_approval(): void
    {
        $report = $this->makeReport();
        $this->actingAs($this->supervisor);

        Livewire::test(SessionReportApproval::class, ['reportId' => $report->id])
            ->assertSee('Revisi')
            ->assertSee('Approve')
            ->assertSee('Waiting Approval')
            ->assertSeeHtml('x-on:click="showRevisionModal = true"')
            ->assertSeeHtml('x-on:click="showApproveModal = true"')
            ->assertSeeHtml('wire:click="approve"')
            ->assertSeeHtml('wire:click="requestRevision"');
    }

    public function test_supervisor_requests_revision_with_note_and_status_updates_to_revision(): void
    {
        $report = $this->makeReport();
        $this->actingAs($this->supervisor);

        Livewire::test(SessionReportApproval::class, ['reportId' => $report->id])
            ->set('revisionReason', 'Periksa kembali mutasi QRIS dan sesi yang belum tercatat.')
            ->call('requestRevision')
            ->assertNotified('Rekap sesi dikembalikan untuk revisi.')
            ->assertSee('Revision')
            ->assertDontSeeHtml('wire:click="approve"')
            ->assertDontSeeHtml('wire:click="requestRevision"');

        $report->refresh();
        $this->assertEquals(SessionReport::STATUS_REVISION, $report->status);
        $this->assertEquals('Periksa kembali mutasi QRIS dan sesi yang belum tercatat.', $report->revision_note);
    }

    public function test_request_revision_requires_mandatory_note(): void
    {
        $report = $this->makeReport();
        $this->actingAs($this->supervisor);

        Livewire::test(SessionReportApproval::class, ['reportId' => $report->id])
            ->set('revisionReason', '')
            ->call('requestRevision')
            ->assertHasErrors(['revisionReason']);

        $this->assertEquals(SessionReport::STATUS_WAITING_APPROVAL, $report->fresh()->status);
    }

    public function test_supervisor_cannot_approve_own_report_when_they_are_submitter(): void
    {
        // Supervisor submits a report themselves
        $ownReport = SessionReport::create([
            'report_date' => now()->toDateString(),
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->supervisor->karyawan_id,
        ]);
        $ownReport->crews()->attach([$this->crew->karyawan_id]);

        SessionTransaction::create([
            'session_report_id' => $ownReport->id,
            'payment_method' => 'CASH',
            'amount' => 30000,
        ]);
        app(SessionWorkflowService::class)->submit($ownReport, $this->supervisor);

        $this->actingAs($this->supervisor);

        // Buttons must be hidden
        Livewire::test(SessionReportApproval::class, ['reportId' => $ownReport->id])
            ->assertSee('Waiting Approval')
            ->assertDontSeeHtml('wire:click="approve"')
            ->assertDontSeeHtml('wire:click="requestRevision"');

        // Calling approve directly must fail with self-approval error
        Livewire::test(SessionReportApproval::class, ['reportId' => $ownReport->id])
            ->call('approve')
            ->assertNotified('Gagal menyetujui rekap sesi.');

        $this->assertEquals(SessionReport::STATUS_WAITING_APPROVAL, $ownReport->fresh()->status);
    }

    public function test_supervisor_cannot_approve_own_report_when_they_are_crew(): void
    {
        // Admin submits, but supervisor is on duty as crew
        $crewReport = SessionReport::create([
            'report_date' => now()->toDateString(),
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->admin->karyawan_id,
        ]);
        $crewReport->crews()->attach([$this->supervisor->karyawan_id]);

        SessionTransaction::create([
            'session_report_id' => $crewReport->id,
            'payment_method' => 'CASH',
            'amount' => 30000,
        ]);
        app(SessionWorkflowService::class)->submit($crewReport, $this->admin);

        $this->actingAs($this->supervisor);

        // Buttons must be hidden from supervisor on their own duty report
        Livewire::test(SessionReportApproval::class, ['reportId' => $crewReport->id])
            ->assertSee('Waiting Approval')
            ->assertDontSeeHtml('wire:click="approve"')
            ->assertDontSeeHtml('wire:click="requestRevision"');

        // Backend guard rejects approval
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Anda tidak dapat menyetujui rekap sesi Anda sendiri.');
        app(SessionWorkflowService::class)->approve($crewReport, $this->supervisor);
    }

    public function test_another_supervisor_can_approve_when_colleague_is_submitter(): void
    {
        // Supervisor 1 submits
        $ownReport = SessionReport::create([
            'report_date' => now()->toDateString(),
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $this->supervisor->karyawan_id,
        ]);
        $ownReport->crews()->attach([$this->crew->karyawan_id]);

        SessionTransaction::create([
            'session_report_id' => $ownReport->id,
            'payment_method' => 'CASH',
            'amount' => 30000,
        ]);
        app(SessionWorkflowService::class)->submit($ownReport, $this->supervisor);

        // Admin (another approver) views the modal
        $this->actingAs($this->admin);

        Livewire::test(SessionReportApproval::class, ['reportId' => $ownReport->id])
            ->assertSee('Waiting Approval')
            ->assertSeeHtml('wire:click="approve"')
            ->call('approve')
            ->assertNotified('Rekap sesi berhasil disetujui.')
            ->assertSee('Approved');

        $this->assertEquals(SessionReport::STATUS_APPROVED, $ownReport->fresh()->status);
        $this->assertEquals($this->admin->karyawan_id, $ownReport->fresh()->approved_by);
    }

    public function test_crew_can_see_revision_note_and_resubmit_report(): void
    {
        $report = $this->makeReport();
        $this->actingAs($this->supervisor);

        // Supervisor requests revision
        app(SessionWorkflowService::class)->requestRevision($report, $this->supervisor, 'Upload bukti transaksi yang kurang.');

        // Crew views modal
        $this->actingAs($this->crew);

        $view = $this->view('filament.resources.session-reports.review-modal', ['record' => $report->fresh()]);
        $view->assertSee('Catatan Revisi Supervisor:');
        $view->assertSee('Upload bukti transaksi yang kurang.');

        // Crew resubmits report
        app(SessionWorkflowService::class)->submit($report->fresh(), $this->crew);

        $this->assertEquals(SessionReport::STATUS_WAITING_APPROVAL, $report->fresh()->status);

        // Supervisor can now see approve & revisi buttons again
        $this->actingAs($this->supervisor);
        Livewire::test(SessionReportApproval::class, ['reportId' => $report->id])
            ->assertSee('Revisi')
            ->assertSee('Approve');
    }

    public function test_newspaper_janus_bonus_alert_shown_for_unapproved_report_under_target(): void
    {
        $this->actingAs($this->crew);

        $cabangJanus = \App\Models\Cabang::create([
            'cabang_id' => 'C-JAN-UNAPP',
            'perusahaan_id' => 'P01',
            'nama_cabang' => 'Newspaper Janus',
        ]);

        $report = SessionReport::create([
            'report_date' => now()->toDateString(),
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'submitted_by' => $this->crew->karyawan_id,
            'cabang_id' => $cabangJanus->cabang_id,
        ]);
        $report->crews()->attach([$this->crew->karyawan_id]);

        // 10 sessions (target is 30)
        for ($i = 0; $i < 10; $i++) {
            SessionTransaction::create([
                'session_report_id' => $report->id,
                'payment_method' => 'CASH',
                'amount' => 30000,
            ]);
        }
        app(\App\Services\PricingService::class)->recalculateReport($report);

        $view = $this->view('filament.resources.session-reports.review-modal', ['record' => $report->fresh()]);
        $view->assertSee('Bonus Crew Cabang Newspaper Janus');
        // Yellow alert must be present for unapproved report
        $view->assertSee('bg-amber-50', false);
        $view->assertSee('BELUM MENCAPAI TARGET BONUS');
        $view->assertSee('Bonus: Rp 0');
    }

    public function test_newspaper_janus_bonus_yellow_alert_hidden_for_approved_report_under_target(): void
    {
        $this->actingAs($this->supervisor);

        $cabangJanus = \App\Models\Cabang::create([
            'cabang_id' => 'C-JAN-APP',
            'perusahaan_id' => 'P01',
            'nama_cabang' => 'Newspaper Janus',
        ]);

        $report = SessionReport::create([
            'report_date' => now()->toDateString(),
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'submitted_by' => $this->crew->karyawan_id,
            'cabang_id' => $cabangJanus->cabang_id,
        ]);
        $report->crews()->attach([$this->crew->karyawan_id]);

        // 10 sessions (target is 30)
        for ($i = 0; $i < 10; $i++) {
            SessionTransaction::create([
                'session_report_id' => $report->id,
                'payment_method' => 'CASH',
                'amount' => 30000,
            ]);
        }
        app(\App\Services\PricingService::class)->recalculateReport($report);
        app(SessionWorkflowService::class)->approve($report, $this->supervisor);

        $view = $this->view('filament.resources.session-reports.review-modal', ['record' => $report->fresh()]);
        $view->assertSee('Bonus Crew Cabang Newspaper Janus');
        // Yellow warning background must NOT be present
        $view->assertDontSee('bg-amber-50', false);
        $view->assertDontSee('border-amber-200', false);
        // Neutral proportional container must be present
        $view->assertSee('Target Bonus Harian Belum Tercapai');
        $view->assertSee('10 Sesi');
        $view->assertSee('20 Sesi');
        $view->assertSee('Bonus: Rp 0');
    }
}
