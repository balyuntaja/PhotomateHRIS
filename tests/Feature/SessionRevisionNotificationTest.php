<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\SessionReportResource;
use App\Filament\Resources\SessionReportResource\Pages\EditSessionReport;
use App\Filament\Resources\SessionReportResource\Pages\ListSessionReports;
use App\Filament\Resources\SessionReportResource\Pages\ViewSessionReport;
use App\Livewire\EmployeeRevisionPopup;
use App\Livewire\SessionReportApproval;
use App\Models\Cabang;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\PricingSetting;
use App\Models\Role;
use App\Models\SessionReport;
use App\Models\SessionTransaction;
use App\Notifications\SessionRevisionDatabaseNotification;
use App\Services\RevisionNotificationService;
use App\Services\SessionWorkflowService;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class SessionRevisionNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $crewKia;
    protected Karyawan $crewShasha;
    protected Karyawan $unrelatedCrew;
    protected Karyawan $supervisor;
    protected Karyawan $admin;
    protected Cabang $cabangJanus;
    protected Cabang $cabangExpress;

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

        Role::firstOrCreate(['role_id' => 'R01'], ['name' => 'Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['role_id' => 'R07'], ['name' => 'Karyawan', 'guard_name' => 'web']);
        Role::firstOrCreate(['role_id' => 'R08'], ['name' => 'Supervisor', 'guard_name' => 'web']);

        Perusahaan::firstOrCreate(
            ['perusahaan_id' => 'P01'],
            [
                'nama_perusahaan' => 'Photomate Indonesia',
                'email' => 'info@photomate.id',
                'nomor_telepon' => '08123456789',
                'jam_masuk' => '08:00',
                'jam_pulang' => '17:00',
            ]
        );

        $this->cabangJanus = Cabang::create([
            'cabang_id' => 'C-JAN',
            'perusahaan_id' => 'P01',
            'nama_cabang' => 'Newspaper Janus',
        ]);

        $this->cabangExpress = Cabang::create([
            'cabang_id' => 'C-EXP',
            'perusahaan_id' => 'P01',
            'nama_cabang' => 'Photomate Express',
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

        $this->crewKia = Karyawan::create([
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

        $this->crewShasha = Karyawan::create([
            'karyawan_id' => 'SHA01',
            'role_id' => 'R07',
            'perusahaan_id' => 'P01',
            'nik' => '1234567890123452',
            'nama_lengkap' => 'Shasha',
            'email' => 'shasha@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '2001-02-02',
            'jenis_kelamin' => 'Perempuan',
            'alamat' => 'Malang',
        ]);

        $this->unrelatedCrew = Karyawan::create([
            'karyawan_id' => 'OTH01',
            'role_id' => 'R07',
            'perusahaan_id' => 'P01',
            'nik' => '1234567890123459',
            'nama_lengkap' => 'Rani Tidak Terkait',
            'email' => 'rani@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '2002-03-03',
            'jenis_kelamin' => 'Perempuan',
            'alamat' => 'Malang',
        ]);
    }

    protected function createSubmittedReport(
        Karyawan $submitter,
        array $crewIds,
        Cabang $cabang,
        ?string $date = null
    ): SessionReport {
        $report = SessionReport::create([
            'report_date' => $date ?? Carbon::today()->toDateString(),
            'cabang_id' => $cabang->cabang_id,
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_by' => $submitter->karyawan_id,
        ]);

        $report->crews()->attach($crewIds);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => 'QRIS',
            'amount' => 45000,
        ]);

        app(SessionWorkflowService::class)->submit($report, $submitter);

        return $report->fresh(['cabang', 'crews', 'submitter']);
    }

    public function test_supervisor_revision_creates_sync_database_notifications_only_for_related_crews(): void
    {
        // Even with database queue connection (not sync), revision DB notifications must be written immediately.
        config(['queue.default' => 'database']);

        $reportDate = Carbon::today()->subDay();
        $report = $this->createSubmittedReport(
            $this->crewKia,
            [$this->crewKia->karyawan_id, $this->crewShasha->karyawan_id],
            $this->cabangJanus,
            $reportDate->toDateString()
        );

        app(SessionWorkflowService::class)->requestRevision(
            $report,
            $this->supervisor,
            'Nominal QRIS pada sesi ke-2 kurang Rp 15.000.'
        );

        $this->assertSame(1, $this->crewKia->unreadNotifications()->count());
        $this->assertSame(1, $this->crewShasha->unreadNotifications()->count());
        $this->assertSame(0, $this->unrelatedCrew->unreadNotifications()->count());

        /** @var DatabaseNotification $notif */
        $notif = $this->crewKia->unreadNotifications()->first();
        $this->assertSame(SessionRevisionDatabaseNotification::class, $notif->type);
        $this->assertSame('filament', data_get($notif->data, 'format'));
        $this->assertSame(RevisionNotificationService::TYPE_SESSION_REVISION, data_get($notif->data, 'type'));
        $this->assertSame($report->id, data_get($notif->data, 'session_report_id'));
        $this->assertSame($report->report_number, data_get($notif->data, 'report_number'));
        $this->assertSame($reportDate->toDateString(), data_get($notif->data, 'report_date'));
        $this->assertSame('Newspaper Janus', data_get($notif->data, 'cabang_name'));
        $this->assertStringContainsString('Kia', data_get($notif->data, 'crew_names'));
        $this->assertStringContainsString('Shasha', data_get($notif->data, 'crew_names'));
        $this->assertSame('Nominal QRIS pada sesi ke-2 kurang Rp 15.000.', data_get($notif->data, 'revision_note'));
        $this->assertNotEmpty(data_get($notif->data, 'revised_at'));
        $this->assertSame('Budi Supervisor', data_get($notif->data, 'supervisor_name'));
        $this->assertSame(
            SessionReportResource::getUrl('view', ['record' => $report]),
            data_get($notif->data, 'detail_url')
        );
    }

    public function test_employee_sees_automatic_revision_popup_on_dashboard_and_rekap_sesi_without_duplication(): void
    {
        $report = $this->createSubmittedReport(
            $this->crewKia,
            [$this->crewKia->karyawan_id, $this->crewShasha->karyawan_id],
            $this->cabangJanus
        );

        app(SessionWorkflowService::class)->requestRevision(
            $report,
            $this->supervisor,
            'Lampiran foto bukti QRIS belum jelas.'
        );

        $this->actingAs($this->crewKia);

        // Dashboard renders simplified popup automatically
        $dashboardResponse = $this->get('/admin')->assertOk();
        $dashboardResponse
            ->assertSee('Rekap Sesi Memerlukan Revisi')
            ->assertSee('Cabang Newspaper Janus')
            ->assertSee('Crew: Kia, Shasha')
            ->assertSee('Lampiran foto bukti QRIS belum jelas.')
            ->assertSee('Lihat Rekap &amp; Perbaiki', false);

        // Rekap Sesi page renders popup automatically
        $rekapResponse = $this->get(SessionReportResource::getUrl('index'))->assertOk();
        $rekapResponse
            ->assertSee('Rekap Sesi Memerlukan Revisi')
            ->assertSee('Cabang Newspaper Janus')
            ->assertSee('Lampiran foto bukti QRIS belum jelas.')
            ->assertSee('Lihat Rekap &amp; Perbaiki', false);

        // Refreshing multiple times always shows popup again while status is REVISION, without duplicating notifications
        $this->get('/admin')
            ->assertOk()
            ->assertSee('Rekap Sesi Memerlukan Revisi')
            ->assertSee('Cabang Newspaper Janus');
        $this->get(SessionReportResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Rekap Sesi Memerlukan Revisi');
        $this->assertSame(1, $this->crewKia->notifications()->count());
    }

    public function test_multiple_revisions_are_rendered_together_in_single_popup(): void
    {
        $report1 = $this->createSubmittedReport(
            $this->crewKia,
            [$this->crewKia->karyawan_id],
            $this->cabangJanus,
            now()->toDateString()
        );
        $report2 = $this->createSubmittedReport(
            $this->crewKia,
            [$this->crewKia->karyawan_id, $this->crewShasha->karyawan_id],
            $this->cabangExpress,
            now()->subDay()->toDateString()
        );

        $workflow = app(SessionWorkflowService::class);
        $workflow->requestRevision($report1, $this->supervisor, 'Catatan revisi pertama untuk Janus.');
        $workflow->requestRevision($report2, $this->supervisor, 'Catatan revisi kedua untuk Express.');

        $this->actingAs($this->crewKia);

        Livewire::test(EmployeeRevisionPopup::class)
            ->assertSet('showModal', true)
            ->assertCount('revisionItems', 2)
            ->assertSee('Rekap Sesi Memerlukan Revisi')
            ->assertSee('2 Rekap')
            ->assertSee($report1->report_number)
            ->assertSee('Newspaper Janus')
            ->assertSee('Catatan revisi pertama untuk Janus.')
            ->assertSee($report2->report_number)
            ->assertSee('Photomate Express')
            ->assertSee('Catatan revisi kedua untuk Express.');
    }

    public function test_closing_popup_does_not_mark_notification_read_and_persists_across_relogin(): void
    {
        $report = $this->createSubmittedReport(
            $this->crewKia,
            [$this->crewKia->karyawan_id],
            $this->cabangJanus
        );

        app(SessionWorkflowService::class)->requestRevision(
            $report,
            $this->supervisor,
            'Periksa jumlah lembar sesi 1.'
        );

        $this->actingAs($this->crewKia);

        Livewire::test(EmployeeRevisionPopup::class)
            ->assertSet('showModal', true)
            ->call('dismissPopup')
            ->assertSet('showModal', false);

        // Notification remains unread in DB & bell badge
        $this->assertSame(1, $this->crewKia->unreadNotifications()->count());

        // Simulate logout and login again
        auth()->logout();
        $this->actingAs($this->crewKia->fresh());

        // Popup appears again and bell notification is still unread
        Livewire::test(EmployeeRevisionPopup::class)
            ->assertSet('showModal', true)
            ->assertCount('revisionItems', 1);

        Livewire::test(DatabaseNotifications::class)
            ->assertSee('1')
            ->assertSee('Rekap Sesi Memerlukan Revisi')
            ->assertSee('Newspaper Janus')
            ->assertSee('Periksa jumlah lembar sesi 1.');
    }

    public function test_clicking_popup_cta_or_opening_detail_marks_read_while_keeping_revision_status_until_resubmit(): void
    {
        $report = $this->createSubmittedReport(
            $this->crewKia,
            [$this->crewKia->karyawan_id, $this->crewShasha->karyawan_id],
            $this->cabangJanus
        );

        app(SessionWorkflowService::class)->requestRevision(
            $report,
            $this->supervisor,
            'Mohon lengkapi catatan transaksi tunai.'
        );

        $this->actingAs($this->crewKia);

        Livewire::test(EmployeeRevisionPopup::class)
            ->call('openRevision', $report->id)
            ->assertRedirect(SessionReportResource::getUrl('edit', ['record' => $report]));

        // Notification in navbar bell is marked read for Kia, still unread for Shasha, and status remains REVISION
        $this->assertSame(0, $this->crewKia->unreadNotifications()->count());
        $this->assertSame(1, $this->crewShasha->unreadNotifications()->count());
        $this->assertSame(SessionReport::STATUS_REVISION, $report->fresh()->status);

        // Because status is still REVISION, refreshing Dashboard/Rekap Sesi still shows popup until resubmitted
        Livewire::test(EmployeeRevisionPopup::class)
            ->assertSet('showModal', true)
            ->assertCount('revisionItems', 1);

        // Shasha opens Detail Rekap Sesi (ViewSessionReport, target of navbar bell click) -> marks bell notification read for Shasha
        $this->actingAs($this->crewShasha);
        Livewire::test(ViewSessionReport::class, ['record' => $report->getKey()])
            ->assertSuccessful()
            ->assertSee('Mohon lengkapi catatan transaksi tunai.')
            ->assertActionVisible('edit')
            ->assertActionVisible('submit_rekap');

        $this->assertSame(0, $this->crewShasha->unreadNotifications()->count());
        $this->assertSame(SessionReport::STATUS_REVISION, $report->fresh()->status);

        // Once resubmitted, status changes to WAITING_APPROVAL and popup no longer appears
        app(SessionWorkflowService::class)->submit($report->fresh(), $this->crewKia);
        $this->actingAs($this->crewKia);
        Livewire::test(EmployeeRevisionPopup::class)
            ->assertSet('showModal', false)
            ->assertCount('revisionItems', 0);
    }

    public function test_navbar_notification_renders_revision_details_and_links_to_report_detail(): void
    {
        $reportDate = Carbon::today()->subDay();
        $expectedDateFormatted = $reportDate->copy()->locale('id')->translatedFormat('d F Y');

        $report = $this->createSubmittedReport(
            $this->crewKia,
            [$this->crewKia->karyawan_id],
            $this->cabangJanus,
            $reportDate->toDateString()
        );

        app(SessionWorkflowService::class)->requestRevision(
            $report,
            $this->supervisor,
            'Nominal tunai kurang Rp 30.000.'
        );

        $this->actingAs($this->crewKia);

        $detailUrl = SessionReportResource::getUrl('view', ['record' => $report]);

        Livewire::test(DatabaseNotifications::class)
            ->assertSee('Rekap Sesi Memerlukan Revisi')
            ->assertSee($report->report_number)
            ->assertSee($expectedDateFormatted)
            ->assertSee('Newspaper Janus')
            ->assertSee('Nominal tunai kurang Rp 30.000.')
            ->assertSee('Belum Dibaca')
            ->assertSeeHtml('href="' . $detailUrl . '"');
    }

    public function test_second_revision_after_resubmit_creates_new_unread_notification(): void
    {
        $report = $this->createSubmittedReport(
            $this->crewKia,
            [$this->crewKia->karyawan_id],
            $this->cabangJanus
        );

        $workflow = app(SessionWorkflowService::class);

        // First revision
        $workflow->requestRevision($report, $this->supervisor, 'Revisi tahap 1.');
        $this->actingAs($this->crewKia);
        Livewire::test(ViewSessionReport::class, ['record' => $report->getKey()]);
        $this->assertSame(0, $this->crewKia->unreadNotifications()->count());

        // Crew resubmits -> status returns to WAITING_APPROVAL
        $workflow->submit($report->fresh(), $this->crewKia, 'Sudah diperbaiki tahap 1.');
        $this->assertSame(SessionReport::STATUS_WAITING_APPROVAL, $report->fresh()->status);

        // Supervisor requests revision again
        $this->actingAs($this->supervisor);
        $workflow->requestRevision($report->fresh(), $this->supervisor, 'Revisi tahap 2: cek QRIS.');

        // New unread notification exists alongside the old read one
        $this->actingAs($this->crewKia);
        $this->assertSame(2, $this->crewKia->notifications()->count());
        $this->assertSame(1, $this->crewKia->unreadNotifications()->count());

        Livewire::test(EmployeeRevisionPopup::class)
            ->assertSet('showModal', true)
            ->assertCount('revisionItems', 1)
            ->assertSee('Revisi tahap 2: cek QRIS.')
            ->assertDontSee('Revisi tahap 1.');
    }

    public function test_rekap_sesi_table_highlights_revision_rows_and_exposes_edit_and_submit_ulang(): void
    {
        $report = $this->createSubmittedReport(
            $this->crewKia,
            [$this->crewKia->karyawan_id],
            $this->cabangJanus
        );

        app(SessionWorkflowService::class)->requestRevision(
            $report,
            $this->supervisor,
            'Sesuaikan total lembar sesi.'
        );

        $this->actingAs($this->crewKia);

        Livewire::test(ListSessionReports::class)
            ->assertSuccessful()
            ->assertSeeHtml('pm-revision-row')
            ->assertSeeHtml('pm-revision-status-badge')
            ->assertSee('Perlu Revisi')
            ->assertSee('Submit Ulang')
            ->assertSee('Sesuaikan total lembar sesi.')
            ->assertTableActionVisible('edit', $report)
            ->assertTableActionVisible('submit_rekap', $report);

        // Opening review_detail modal in table also marks notification as read
        Livewire::test(ListSessionReports::class)
            ->mountTableAction('review_detail', $report->id);

        $this->assertSame(0, $this->crewKia->unreadNotifications()->count());
    }

    public function test_supervisors_and_admins_do_not_get_employee_popup_and_approval_flow_unchanged(): void
    {
        $report = $this->createSubmittedReport(
            $this->crewKia,
            [$this->crewKia->karyawan_id],
            $this->cabangJanus
        );

        app(SessionWorkflowService::class)->requestRevision(
            $report,
            $this->supervisor,
            'Periksa kembali.'
        );

        foreach ([$this->supervisor, $this->admin] as $elevated) {
            $this->flushSession();
            $this->actingAs($elevated);

            Livewire::test(EmployeeRevisionPopup::class)
                ->assertSet('showModal', false)
                ->assertCount('revisionItems', 0)
                ->assertDontSee('Rekap Sesi Memerlukan Revisi');

            $this->actingAs($elevated)
                ->get('/admin')
                ->assertOk()
                ->assertDontSee('id="pm-revision-popup-title"', false);
        }

        // Crew resubmits -> Supervisor still approves normally via SessionReportApproval
        $this->actingAs($this->crewKia);
        app(SessionWorkflowService::class)->submit($report->fresh(), $this->crewKia);

        $this->actingAs($this->supervisor);
        Livewire::test(SessionReportApproval::class, ['reportId' => $report->id])
            ->assertSee('Approve')
            ->assertSee('Revisi')
            ->call('approve')
            ->assertNotified('Rekap sesi berhasil disetujui.');

        $this->assertSame(SessionReport::STATUS_APPROVED, $report->fresh()->status);
    }

    public function test_unrelated_employee_cannot_view_notification_or_open_revision_report(): void
    {
        $report = $this->createSubmittedReport(
            $this->crewKia,
            [$this->crewKia->karyawan_id],
            $this->cabangJanus
        );

        app(SessionWorkflowService::class)->requestRevision(
            $report,
            $this->supervisor,
            'Catatan rahasia crew terkait.'
        );

        $kiaNotifId = $this->crewKia->unreadNotifications()->first()->id;

        $this->actingAs($this->unrelatedCrew);

        // No notification in bell or popup
        $this->assertSame(0, $this->unrelatedCrew->notifications()->count());

        Livewire::test(EmployeeRevisionPopup::class)
            ->assertSet('showModal', false)
            ->assertCount('revisionItems', 0)
            ->call('openRevision', $report->id)
            ->assertNoRedirect();

        // Kia's notification must remain untouched/unread
        $this->assertSame(1, $this->crewKia->unreadNotifications()->count());

        // Unrelated employee cannot access view or edit URL of Kia's report
        $this->get(SessionReportResource::getUrl('view', ['record' => $report]))->assertForbidden();
        $this->get(SessionReportResource::getUrl('edit', ['record' => $report]))->assertForbidden();
    }

    public function test_supervisor_request_revision_via_approval_table_action_and_crew_resubmit_via_table_works(): void
    {
        $report = $this->createSubmittedReport(
            $this->crewKia,
            [$this->crewKia->karyawan_id],
            $this->cabangJanus
        );

        // Supervisor requests revision via SessionApprovalResource table action
        $this->actingAs($this->supervisor);
        Livewire::test(\App\Filament\Resources\SessionApprovalResource\Pages\ListSessionApprovals::class)
            ->callTableAction('request_revision', $report, data: [
                'reason' => 'Bukti transaksi QRIS belum sesuai.',
            ])
            ->assertHasNoTableActionErrors();

        $report = $report->fresh();
        $this->assertSame(SessionReport::STATUS_REVISION, $report->status);
        $this->assertSame(1, $this->crewKia->unreadNotifications()->count());

        // Crew opens Rekap Sesi and resubmits via submit_rekap table action
        $this->flushSession();
        $this->actingAs($this->crewKia);

        Livewire::test(ListSessionReports::class)
            ->callTableAction('submit_rekap', $report)
            ->assertHasNoTableActionErrors()
            ->assertDispatched('databaseNotificationsSent')
            ->assertDispatched('session-report-revision-updated');

        $this->assertSame(SessionReport::STATUS_WAITING_APPROVAL, $report->fresh()->status);
        $this->assertSame(0, $this->crewKia->unreadNotifications()->count());

        // Popup no longer shows once resubmitted
        Livewire::test(EmployeeRevisionPopup::class)
            ->assertSet('showModal', false)
            ->assertCount('revisionItems', 0);
    }
}
