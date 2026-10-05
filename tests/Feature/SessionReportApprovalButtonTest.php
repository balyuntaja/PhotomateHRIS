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
            'report_date' => '2026-05-23',
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
}
