<?php

namespace Tests\Feature;

use App\Filament\Resources\SessionHistoryResource\Pages\ListSessionHistories;
use App\Models\Cabang;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\PricingSetting;
use App\Models\Role;
use App\Models\SessionReport;
use App\Models\SessionTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SessionHistoryCabangFilterTest extends TestCase
{
    use RefreshDatabase;

    protected Perusahaan $perusahaan;
    protected Cabang $cabangJanus;
    protected Cabang $cabangExpressSharingProfit;
    protected Cabang $cabangExpressSelfRun;
    protected Cabang $cabangExpressSewa;
    protected Cabang $cabangGolio;

    protected Karyawan $superAdmin;
    protected Karyawan $supervisorA;
    protected Karyawan $supervisorB;
    protected Karyawan $crewA;
    protected Karyawan $crewB;

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

        $this->perusahaan = Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        $this->cabangJanus = Cabang::create([
            'cabang_id' => 'C0004',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Newspaper Janus',
        ]);

        $this->cabangGolio = Cabang::create([
            'cabang_id' => 'C0006',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Golio',
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

        $roleAdmin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web'], ['role_id' => 'R01']);
        $roleSupervisor = Role::firstOrCreate(['name' => 'Supervisor', 'guard_name' => 'web'], ['role_id' => 'R08']);
        $roleCrew = Role::firstOrCreate(['name' => 'Karyawan', 'guard_name' => 'web'], ['role_id' => 'R07']);

        $this->superAdmin = $this->makeKaryawan('ADM01', 'Super Admin', $roleAdmin, 'admin.hist@photomate.id', '920000000001');
        $this->supervisorA = $this->makeKaryawan('SPV01', 'Supervisor Alpha', $roleSupervisor, 'spva@photomate.id', '920000000002');
        $this->supervisorB = $this->makeKaryawan('SPV02', 'Supervisor Beta', $roleSupervisor, 'spvb@photomate.id', '920000000003');
        $this->crewA = $this->makeKaryawan('CRW01', 'Crew Satu', $roleCrew, 'crew1@photomate.id', '920000000004');
        $this->crewB = $this->makeKaryawan('CRW02', 'Crew Dua', $roleCrew, 'crew2@photomate.id', '920000000005');
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
            'tanggal_lahir' => '1993-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);

        $user->assignRole($role);

        return $user->fresh();
    }

    protected function makeReport(
        Cabang $cabang,
        string $reportNumber,
        string $reportDate,
        Karyawan $crew,
        Karyawan $approver,
        string $paymentMethod = 'CASH',
        ?string $eventNote = null
    ): SessionReport {
        $isManual = in_array($cabang->cabang_id, [
            $this->cabangExpressSewa->cabang_id,
            $this->cabangExpressSelfRun->cabang_id,
        ], true);

        $report = SessionReport::create([
            'report_number' => $reportNumber,
            'report_date' => $reportDate,
            'status' => SessionReport::STATUS_APPROVED,
            'cabang_id' => $cabang->cabang_id,
            'event_note' => $eventNote,
            'event_date' => $eventNote ? $reportDate : null,
            'wedding_total_sessions' => $isManual ? 20 : null,
            'wedding_total_sheets' => $isManual ? 30 : null,
            'total_transactions' => $isManual ? 20 : 4,
            'total_sessions' => $isManual ? 30 : 8,
            'total_cash_amount' => $paymentMethod === 'CASH' ? 200000 : 0,
            'total_qris_amount' => $paymentMethod === 'QRIS' ? 200000 : 0,
            'grand_total_amount' => 200000,
            'submitted_by' => $crew->karyawan_id,
            'submitted_at' => $reportDate . ' 20:00:00',
            'approved_by' => $approver->karyawan_id,
            'approved_at' => $reportDate . ' 21:00:00',
        ]);

        $report->crews()->attach([$crew->karyawan_id]);

        SessionTransaction::create([
            'session_report_id' => $report->id,
            'payment_method' => $paymentMethod,
            'session_count' => 8,
            'amount' => 200000,
            'notes' => 'Transaksi ' . $reportNumber,
        ]);

        return $report->fresh(['cabang', 'crews', 'transactions', 'approver']);
    }

    public function test_filter_cabang_options_hanya_menampilkan_all_newspaper_janus_express_dan_golio(): void
    {
        $this->actingAs($this->superAdmin);

        $component = Livewire::test(ListSessionHistories::class);
        $filter = $component->instance()->getTable()->getFilter('cabang_id');

        $this->assertNotNull($filter);
        $this->assertSame('All', $filter->getPlaceholder());

        $options = $filter->getOptions();

        $this->assertSame([
            SessionReport::HISTORY_BRANCH_FILTER_JANUS => 'Newspaper Janus',
            SessionReport::HISTORY_BRANCH_FILTER_EXPRESS => 'Express',
            SessionReport::HISTORY_BRANCH_FILTER_GOLIO => 'Golio',
        ], $options);

        $this->assertNotContains('Express Sharing Profit', $options);
        $this->assertNotContains('Express Self Run', $options);
        $this->assertNotContains('Express Sewa', $options);
    }

    public function test_filter_express_menggabungkan_sharing_profit_self_run_dan_sewa_tanpa_mengubah_record(): void
    {
        $this->actingAs($this->superAdmin);

        $janusReport = $this->makeReport($this->cabangJanus, 'SR-JAN-001', '2026-11-20', $this->crewA, $this->supervisorA);
        $sharingReport = $this->makeReport($this->cabangExpressSharingProfit, 'SR-EXP-SP1', '2026-11-21', $this->crewA, $this->supervisorA, 'CASH', 'Event Sharing Profit');
        $selfRunReport = $this->makeReport($this->cabangExpressSelfRun, 'SR-EXP-SR1', '2026-11-22', $this->crewA, $this->supervisorA, 'CASH', 'Event Self Run');
        $sewaReport = $this->makeReport($this->cabangExpressSewa, 'SR-EXP-SW1', '2026-11-23', $this->crewA, $this->supervisorA, 'CASH', 'Event Sewa');
        $golioReport = $this->makeReport($this->cabangGolio, 'SR-GOL-001', '2026-11-24', $this->crewA, $this->supervisorA);

        $allReports = [$janusReport, $sharingReport, $selfRunReport, $sewaReport, $golioReport];

        // 1. All (tanpa filter): tampilkan seluruh cabang dan tetap tampilkan kategori cabang asli di tabel
        Livewire::test(ListSessionHistories::class)
            ->assertCanSeeTableRecords($allReports)
            ->assertSee('Newspaper Janus')
            ->assertSee('Express Sharing Profit')
            ->assertSee('Express Self Run')
            ->assertSee('Express Sewa')
            ->assertSee('Golio');

        // 2. Filter Express: tampilkan gabungan Express Sharing Profit, Express Self Run, dan Express Sewa
        Livewire::test(ListSessionHistories::class)
            ->filterTable('cabang_id', SessionReport::HISTORY_BRANCH_FILTER_EXPRESS)
            ->assertCanSeeTableRecords([$sharingReport, $selfRunReport, $sewaReport])
            ->assertCanNotSeeTableRecords([$janusReport, $golioReport])
            ->assertSee('Express Sharing Profit')
            ->assertSee('Express Self Run')
            ->assertSee('Express Sewa');

        // 3. Filter Newspaper Janus
        Livewire::test(ListSessionHistories::class)
            ->filterTable('cabang_id', SessionReport::HISTORY_BRANCH_FILTER_JANUS)
            ->assertCanSeeTableRecords([$janusReport])
            ->assertCanNotSeeTableRecords([$sharingReport, $selfRunReport, $sewaReport, $golioReport]);

        // 4. Filter Golio
        Livewire::test(ListSessionHistories::class)
            ->filterTable('cabang_id', SessionReport::HISTORY_BRANCH_FILTER_GOLIO)
            ->assertCanSeeTableRecords([$golioReport])
            ->assertCanNotSeeTableRecords([$janusReport, $sharingReport, $selfRunReport, $sewaReport]);

        // 5. Pastikan record di database tidak digabung atau diubah
        $this->assertSame($this->cabangExpressSharingProfit->cabang_id, $sharingReport->fresh()->cabang_id);
        $this->assertSame($this->cabangExpressSelfRun->cabang_id, $selfRunReport->fresh()->cabang_id);
        $this->assertSame($this->cabangExpressSewa->cabang_id, $sewaReport->fresh()->cabang_id);
        $this->assertSame(5, Cabang::count());
    }

    public function test_filter_cabang_express_kompatibel_dengan_tanggal_crew_payment_supervisor_search_dan_pagination(): void
    {
        $this->actingAs($this->superAdmin);

        $spTarget = $this->makeReport(
            $this->cabangExpressSharingProfit,
            'SR-FLT-TARGET-1',
            '2026-11-15',
            $this->crewA,
            $this->supervisorA,
            'QRIS',
            'Festival Target'
        );
        $srTarget = $this->makeReport(
            $this->cabangExpressSelfRun,
            'SR-FLT-TARGET-2',
            '2026-11-16',
            $this->crewA,
            $this->supervisorA,
            'QRIS',
            'Wedding Target'
        );
        $sewaTarget = $this->makeReport(
            $this->cabangExpressSewa,
            'SR-FLT-TARGET-3',
            '2026-11-17',
            $this->crewA,
            $this->supervisorA,
            'QRIS',
            'Campus Target'
        );
        $spCashOtherCrew = $this->makeReport(
            $this->cabangExpressSharingProfit,
            'SR-FLT-OTHER-1',
            '2026-11-14',
            $this->crewB,
            $this->supervisorB,
            'CASH',
            'Other Crew Event'
        );
        $sewaOutsideDate = $this->makeReport(
            $this->cabangExpressSewa,
            'SR-FLT-OUTDATE',
            '2026-10-05',
            $this->crewA,
            $this->supervisorA,
            'QRIS',
            'Old Date Event'
        );
        $janusSameCriteria = $this->makeReport(
            $this->cabangJanus,
            'SR-FLT-JANUS',
            '2026-11-16',
            $this->crewA,
            $this->supervisorA,
            'QRIS'
        );

        // Kombinasi filter Express + Tanggal + Crew + Payment Method + Supervisor + Search
        Livewire::test(ListSessionHistories::class)
            ->filterTable('cabang_id', SessionReport::HISTORY_BRANCH_FILTER_EXPRESS)
            ->filterTable('tanggal', [
                'dari_tanggal' => '2026-11-10',
                'sampai_tanggal' => '2026-11-20',
            ])
            ->filterTable('crew', $this->crewA->karyawan_id)
            ->filterTable('payment_method', 'QRIS')
            ->filterTable('approved_by', $this->supervisorA->karyawan_id)
            ->assertCanSeeTableRecords([$spTarget, $srTarget, $sewaTarget])
            ->assertCanNotSeeTableRecords([$spCashOtherCrew, $sewaOutsideDate, $janusSameCriteria])
            ->searchTable('SR-FLT-TARGET-2')
            ->assertCanSeeTableRecords([$srTarget])
            ->assertCanNotSeeTableRecords([$spTarget, $sewaTarget]);

        // Verifikasi pagination pada hasil filter Express
        $paginated = Livewire::test(ListSessionHistories::class)
            ->filterTable('cabang_id', SessionReport::HISTORY_BRANCH_FILTER_EXPRESS)
            ->set('tableRecordsPerPage', 2);

        $page1Records = $paginated->instance()->getTableRecords();
        $this->assertSame(5, $page1Records->total());
        $this->assertCount(2, $page1Records->items());
        $paginated->assertCanSeeTableRecords([$sewaTarget, $srTarget])
            ->assertCanNotSeeTableRecords([$spTarget, $spCashOtherCrew, $sewaOutsideDate, $janusSameCriteria])
            ->call('gotoPage', 2, 'page')
            ->assertCanSeeTableRecords([$spTarget, $spCashOtherCrew])
            ->assertCanNotSeeTableRecords([$sewaTarget, $srTarget, $sewaOutsideDate, $janusSameCriteria]);
    }
}
