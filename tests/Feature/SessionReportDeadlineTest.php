<?php

namespace Tests\Feature;

use App\Filament\Resources\SessionReportResource;
use App\Filament\Resources\SessionReportResource\Pages\CreateSessionReport;
use App\Filament\Resources\SessionReportResource\Pages\EditSessionReport;
use App\Filament\Resources\SessionReportResource\Pages\ListSessionReports;
use App\Filament\Resources\SessionReportResource\Pages\ViewSessionReport;
use App\Models\Karyawan;
use App\Models\PricingSetting;
use App\Models\Role;
use App\Models\SessionReport;
use App\Models\SessionTransaction;
use App\Services\SessionWorkflowService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class SessionReportDeadlineTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $crew;
    protected Karyawan $supervisor;
    protected Karyawan $admin;
    protected \App\Models\Cabang $cabang;

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
        Role::firstOrCreate(['name' => 'Supervisor', 'guard_name' => 'web'], ['role_id' => 'R08']);
        Role::firstOrCreate(['name' => 'Karyawan', 'guard_name' => 'web'], ['role_id' => 'R07']);

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

        $this->cabang = \App\Models\Cabang::firstOrCreate(
            ['cabang_id' => 'C-PM'],
            [
                'perusahaan_id' => 'P01',
                'nama_cabang' => 'Photomate',
            ]
        );

        $this->admin = Karyawan::firstOrCreate(
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

        $this->crew = Karyawan::firstOrCreate(
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function freezeAt(string $wibDateTime): void
    {
        Carbon::setTestNow(Carbon::parse($wibDateTime, 'Asia/Jakarta')->setTimezone(config('app.timezone')));
    }

    protected function makeReport(string $reportDate, string $status = SessionReport::STATUS_DRAFT, ?Karyawan $submitter = null): SessionReport
    {
        $submitter ??= $this->crew;

        $report = SessionReport::create([
            'report_date' => $reportDate,
            'status' => $status,
            'submitted_by' => $submitter->karyawan_id,
            'cabang_id' => $this->cabang->cabang_id,
        ]);

        $report->crews()->attach([$this->crew->karyawan_id]);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'CASH',
            'amount' => 30000,
        ]);

        return $report;
    }

    protected function makeRevisionReportPastDeadline(string $reportDate = '2026-09-23'): SessionReport
    {
        $this->freezeAt('2026-09-25 22:00:00');
        $report = $this->makeReport($reportDate);

        $this->actingAs($this->crew);
        app(SessionWorkflowService::class)->submit($report, $this->crew);

        $this->freezeAt('2026-09-26 08:00:00');
        $this->actingAs($this->supervisor);
        app(SessionWorkflowService::class)->requestRevision(
            $report->fresh(),
            $this->supervisor,
            'Perbaiki data transaksi.'
        );

        return $report->fresh();
    }

    /**
     * Deadline = tanggal rekap + 2 hari pukul 23:59:59 WIB (sesuai tabel requirement).
     */
    public function test_deadline_formula_matches_requirement_table(): void
    {
        foreach ([
            '2026-09-23' => '2026-09-25 23:59:59',
            '2026-09-24' => '2026-09-26 23:59:59',
            '2026-09-25' => '2026-09-27 23:59:59',
        ] as $reportDate => $expectedDeadline) {
            $deadline = SessionReport::inputDeadlineFor($reportDate);

            $this->assertSame($expectedDeadline, $deadline->format('Y-m-d H:i:s'));
            $this->assertSame('Asia/Jakarta', $deadline->timezoneName);
        }

        $this->freezeAt('2026-09-25 23:59:59');
        $this->assertFalse(SessionReport::inputWindowClosedFor('2026-09-23'));

        $this->freezeAt('2026-09-26 00:00:00');
        $this->assertTrue(SessionReport::inputWindowClosedFor('2026-09-23'));
    }

    public function test_crew_can_create_report_within_deadline(): void
    {
        $this->freezeAt('2026-09-25 23:59:59');
        $this->actingAs($this->crew);

        Livewire::test(CreateSessionReport::class)
            ->fillForm([
                'report_date' => '2026-09-23',
                'cabang_id' => $this->cabang->cabang_id,
                'crews' => [$this->crew->karyawan_id],
                'transactions' => [
                    ['payment_method' => 'CASH', 'amount' => 30000],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $report = SessionReport::latest('id')->first();
        $this->assertNotNull($report);
        $this->assertSame('2026-09-23', $report->report_date->toDateString());
        $this->assertFalse($report->isPastInputDeadline());
    }

    public function test_crew_can_edit_and_delete_report_within_deadline(): void
    {
        $this->freezeAt('2026-09-25 23:59:59');
        $report = $this->makeReport('2026-09-23');
        $this->actingAs($this->crew);

        $this->assertTrue(Gate::allows('update', $report));
        $this->assertTrue(Gate::allows('delete', $report));

        Livewire::test(ListSessionReports::class)
            ->assertTableActionVisible('edit', $report)
            ->assertTableActionVisible('delete', $report);

        Livewire::test(EditSessionReport::class, ['record' => $report->getKey()])
            ->assertSuccessful()
            ->fillForm(['report_date' => '2026-09-24'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-09-24', $report->fresh()->report_date->toDateString());

        $reportId = $report->id;
        $report->delete();

        $this->assertDatabaseMissing('session_reports', ['id' => $reportId]);
    }

    public function test_crew_cannot_create_report_past_deadline(): void
    {
        $this->freezeAt('2026-09-26 00:00:00');
        $this->actingAs($this->crew);

        Livewire::test(CreateSessionReport::class)
            ->fillForm([
                'report_date' => '2026-09-23',
                'cabang_id' => $this->cabang->cabang_id,
                'crews' => [$this->crew->karyawan_id],
                'transactions' => [
                    ['payment_method' => 'CASH', 'amount' => 30000],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['report_date']);

        $this->assertDatabaseCount('session_reports', 0);
    }

    public function test_model_guard_rejects_direct_create_past_deadline_for_crew(): void
    {
        $this->freezeAt('2026-09-26 00:00:00');
        $this->actingAs($this->crew);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rekap sudah ditutup');

        $this->makeReport('2026-09-23');
    }

    public function test_crew_cannot_update_or_delete_report_past_deadline(): void
    {
        $this->freezeAt('2026-09-25 23:59:59');
        $report = $this->makeReport('2026-09-23');

        $this->freezeAt('2026-09-26 00:00:00');
        $this->actingAs($this->crew);

        $this->assertTrue($report->isPastInputDeadline());
        $this->assertFalse(Gate::allows('update', $report));
        $this->assertFalse(Gate::allows('delete', $report));

        Livewire::test(ListSessionReports::class)
            ->assertTableActionHidden('edit', $report)
            ->assertTableActionHidden('delete', $report);

        Livewire::test(ViewSessionReport::class, ['record' => $report->getKey()])
            ->assertSuccessful()
            ->assertActionHidden('edit');
    }

    public function test_edit_page_redirects_crew_when_deadline_passed(): void
    {
        $this->freezeAt('2026-09-25 23:59:59');
        $report = $this->makeReport('2026-09-23');

        $this->freezeAt('2026-09-26 00:00:00');
        $this->actingAs($this->crew);

        Livewire::test(EditSessionReport::class, ['record' => $report->getKey()])
            ->assertRedirect(SessionReportResource::getUrl('view', ['record' => $report]));
    }

    public function test_edit_page_save_is_rejected_when_deadline_passes_while_page_is_open(): void
    {
        $this->freezeAt('2026-09-25 22:00:00');
        $report = $this->makeReport('2026-09-23');
        $this->actingAs($this->crew);

        $component = Livewire::test(EditSessionReport::class, ['record' => $report->getKey()])
            ->assertSuccessful();

        $this->freezeAt('2026-09-26 00:00:01');

        $component
            ->fillForm(['event_note' => 'Perubahan setelah deadline'])
            ->call('save');

        $this->assertNull($report->fresh()->event_note);
    }

    public function test_backend_guard_rejects_direct_update_and_delete_past_deadline(): void
    {
        $this->freezeAt('2026-09-25 23:59:59');
        $updateReport = $this->makeReport('2026-09-23');
        $deleteReport = $this->makeReport('2026-09-23');

        $this->freezeAt('2026-09-26 00:00:00');
        $this->actingAs($this->crew);

        try {
            $updateReport->update(['event_note' => 'Bypass attempt']);
            $this->fail('Update seharusnya ditolak karena sudah melewati deadline.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rekap sudah ditutup', $e->getMessage());
        }

        try {
            $deleteReport->delete();
            $this->fail('Delete seharusnya ditolak karena sudah melewati deadline.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rekap sudah ditutup', $e->getMessage());
        }

        $this->assertNull($updateReport->fresh()->event_note);
        $this->assertDatabaseHas('session_reports', ['id' => $deleteReport->id]);
    }

    public function test_admin_and_supervisor_are_not_limited_by_deadline(): void
    {
        $this->freezeAt('2026-10-05 10:00:00');

        foreach ([$this->admin, $this->supervisor] as $elevated) {
            $this->actingAs($elevated);

            $report = $this->makeReport('2026-09-23', SessionReport::STATUS_DRAFT, $elevated);

            $this->assertTrue(Gate::allows('update', $report));
            $this->assertTrue(Gate::allows('delete', $report));

            $report->update(['event_note' => 'Dibuat dan diubah oleh ' . $elevated->nama_lengkap]);
            $this->assertSame('Dibuat dan diubah oleh ' . $elevated->nama_lengkap, $report->fresh()->event_note);

            $reportId = $report->id;
            $report->delete();
            $this->assertDatabaseMissing('session_reports', ['id' => $reportId]);
        }
    }

    public function test_admin_can_create_report_with_old_date_through_filament(): void
    {
        $this->freezeAt('2026-10-05 10:00:00');
        $this->actingAs($this->admin);

        Livewire::test(CreateSessionReport::class)
            ->fillForm([
                'report_date' => '2026-09-23',
                'cabang_id' => $this->cabang->cabang_id,
                'crews' => [$this->crew->karyawan_id],
                'transactions' => [
                    ['payment_method' => 'CASH', 'amount' => 30000],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-09-23', SessionReport::latest('id')->first()->report_date->toDateString());
    }

    public function test_review_modal_shows_closed_status_and_deadline_for_crew(): void
    {
        $this->freezeAt('2026-09-25 10:00:00');
        $report = $this->makeReport('2026-09-23');

        $this->actingAs($this->crew);
        $open = $this->view('filament.resources.session-reports.review-modal', ['record' => $report]);
        $open->assertSee('Periode Input Rekap Masih Terbuka');
        $open->assertSee('Batas input dan perubahan rekap untuk tanggal 23 September 2026 adalah 25 September 2026 pukul 23:59.', false);

        $this->freezeAt('2026-09-26 08:00:00');
        $closed = $this->view('filament.resources.session-reports.review-modal', ['record' => $report]);
        $closed->assertSee('Rekap Sudah Ditutup');
        $closed->assertSee('Batas input dan perubahan rekap untuk tanggal 23 September 2026 adalah 25 September 2026 pukul 23:59.', false);
    }

    public function test_review_modal_hides_deadline_banner_from_supervisor(): void
    {
        $this->freezeAt('2026-09-26 08:00:00');
        $report = $this->makeReport('2026-09-23');
        $this->actingAs($this->supervisor);

        $view = $this->view('filament.resources.session-reports.review-modal', ['record' => $report]);
        $view->assertDontSee('Rekap Sudah Ditutup');
        $view->assertDontSee('Periode Input Rekap Masih Terbuka');
    }

    public function test_supervisor_can_request_revision_past_deadline(): void
    {
        $this->freezeAt('2026-09-25 22:00:00');
        $report = $this->makeReport('2026-09-23');

        $this->actingAs($this->crew);
        app(SessionWorkflowService::class)->submit($report, $this->crew);

        $this->freezeAt('2026-09-26 08:00:00');
        $this->actingAs($this->supervisor);

        $updated = app(SessionWorkflowService::class)->requestRevision(
            $report->fresh(),
            $this->supervisor,
            'Nominal QRIS belum sesuai.'
        );

        $this->assertSame(SessionReport::STATUS_REVISION, $updated->status);
        $this->assertSame('Nominal QRIS belum sesuai.', $updated->revision_note);
        $this->assertDatabaseHas('approval_logs', [
            'session_report_id' => $report->id,
            'user_id' => $this->supervisor->karyawan_id,
            'action' => 'REVISION_REQUESTED',
        ]);
    }

    public function test_crew_can_edit_revision_report_past_deadline(): void
    {
        $report = $this->makeRevisionReportPastDeadline();
        $this->actingAs($this->crew);

        $this->assertTrue(Gate::allows('update', $report));
        $this->assertFalse(Gate::allows('delete', $report));

        Livewire::test(ListSessionReports::class)
            ->assertTableActionVisible('edit', $report)
            ->assertTableActionVisible('submit_rekap', $report);

        Livewire::test(ViewSessionReport::class, ['record' => $report->getKey()])
            ->assertSuccessful()
            ->assertActionVisible('edit');

        Livewire::test(EditSessionReport::class, ['record' => $report->getKey()])
            ->assertSuccessful()
            ->fillForm(['report_date' => '2026-09-22'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-09-22', $report->fresh()->report_date->toDateString());

        $report->update(['event_note' => 'Update langsung setelah deadline.']);
        $this->assertSame('Update langsung setelah deadline.', $report->fresh()->event_note);
    }

    public function test_crew_can_resubmit_revision_report_past_deadline(): void
    {
        $report = $this->makeRevisionReportPastDeadline();
        $this->actingAs($this->crew);

        app(SessionWorkflowService::class)->submit($report, $this->crew);
        $report->refresh();

        $this->assertSame(SessionReport::STATUS_WAITING_APPROVAL, $report->status);
        $this->assertDatabaseHas('approval_logs', [
            'session_report_id' => $report->id,
            'user_id' => $this->crew->karyawan_id,
            'action' => 'RESUBMITTED',
        ]);

        $this->assertFalse(Gate::allows('update', $report));

        try {
            $report->update(['event_note' => 'Tidak boleh setelah submit ulang.']);
            $this->fail('Update setelah submit ulang seharusnya ditolak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rekap sudah ditutup', $e->getMessage());
        }
    }

    public function test_crew_cannot_edit_approved_report_past_deadline(): void
    {
        $report = $this->makeReport('2026-09-23', SessionReport::STATUS_APPROVED);
        $this->freezeAt('2026-09-26 08:00:00');
        $this->actingAs($this->crew);

        $this->assertFalse(Gate::allows('update', $report));

        Livewire::test(ListSessionReports::class)
            ->assertTableActionHidden('edit', $report);

        try {
            $report->update(['event_note' => 'Bypass approved.']);
            $this->fail('Update rekap APPROVED seharusnya ditolak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('APPROVED', $e->getMessage());
        }
    }

    public function test_crew_cannot_submit_non_revision_report_past_deadline(): void
    {
        $this->freezeAt('2026-09-25 22:00:00');
        $report = $this->makeReport('2026-09-23');

        $this->freezeAt('2026-09-26 08:00:00');
        $this->actingAs($this->crew);

        Livewire::test(ListSessionReports::class)
            ->assertTableActionHidden('submit_rekap', $report);

        try {
            app(SessionWorkflowService::class)->submit($report, $this->crew);
            $this->fail('Submit rekap non-revisi setelah deadline seharusnya ditolak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rekap sudah ditutup', $e->getMessage());
        }

        $this->assertSame(SessionReport::STATUS_DRAFT, $report->fresh()->status);
    }

    public function test_second_revision_cycle_past_deadline_still_allowed(): void
    {
        $report = $this->makeRevisionReportPastDeadline();
        $this->actingAs($this->crew);

        app(SessionWorkflowService::class)->submit($report, $this->crew);

        $this->actingAs($this->supervisor);
        app(SessionWorkflowService::class)->requestRevision(
            $report->fresh(),
            $this->supervisor,
            'Masih ada selisih pada transaksi QRIS.'
        );

        $this->freezeAt('2026-10-10 08:00:00');
        $this->actingAs($this->crew);
        $report->refresh();

        $this->assertSame(SessionReport::STATUS_REVISION, $report->status);
        $this->assertTrue(Gate::allows('update', $report));

        $report->update(['event_note' => 'Perbaikan revisi kedua.']);
        app(SessionWorkflowService::class)->submit($report->fresh(), $this->crew);

        $this->assertSame(SessionReport::STATUS_WAITING_APPROVAL, $report->fresh()->status);
        $this->assertSame(
            2,
            \App\Models\ApprovalLog::where('session_report_id', $report->id)
                ->where('action', 'REVISION_REQUESTED')
                ->count()
        );
        $this->assertSame(
            2,
            \App\Models\ApprovalLog::where('session_report_id', $report->id)
                ->where('action', 'RESUBMITTED')
                ->count()
        );
    }

    public function test_revision_edit_page_save_works_when_deadline_passes_while_page_is_open(): void
    {
        $this->freezeAt('2026-09-25 22:00:00');
        $report = $this->makeReport('2026-09-23');

        $this->actingAs($this->crew);
        app(SessionWorkflowService::class)->submit($report, $this->crew);

        $this->actingAs($this->supervisor);
        app(SessionWorkflowService::class)->requestRevision(
            $report->fresh(),
            $this->supervisor,
            'Perbaiki data.'
        );

        $this->actingAs($this->crew);
        $component = Livewire::test(EditSessionReport::class, ['record' => $report->getKey()])
            ->assertSuccessful();

        $this->freezeAt('2026-09-26 00:00:01');

        $component
            ->fillForm(['report_date' => '2026-09-22'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-09-22', $report->fresh()->report_date->toDateString());
    }

    public function test_review_modal_shows_revision_deadline_exemption_for_crew(): void
    {
        $report = $this->makeRevisionReportPastDeadline();
        $this->actingAs($this->crew);

        $view = $this->view('filament.resources.session-reports.review-modal', ['record' => $report]);

        $view->assertSee('Batas H+2 Dikecualikan untuk Revisi');
        $view->assertSee('Crew dapat mengedit dan mengirim ulang rekap tanpa batas H+2', false);
        $view->assertDontSee('Rekap Sudah Ditutup');
    }
}
