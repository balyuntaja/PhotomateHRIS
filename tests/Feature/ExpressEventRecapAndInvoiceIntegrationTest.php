<?php

namespace Tests\Feature;

use App\Filament\Resources\ExpressEventResource\Pages\ListExpressEvents;
use App\Filament\Resources\ExpressEventResource\Pages\ViewExpressEvent;
use App\Livewire\ExpressEventInvoiceManager;
use App\Models\Cabang;
use App\Models\ExpressEvent;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialParentCategory;
use App\Models\FinancialPaymentMethod;
use App\Models\Invoice;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\PricingSetting;
use App\Models\Role;
use App\Models\SessionReport;
use App\Models\SessionTransaction;
use App\Services\ExpressEventHppService;
use App\Services\ExpressEventInvoiceService;
use App\Services\ExpressEventSyncService;
use App\Services\HppDashboardService;
use App\Services\RekapPerbulanService;
use App\Services\SessionReportFinancialService;
use App\Services\SessionWorkflowService;
use App\Utils\RupiahHelper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as MailNotification;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ExpressEventRecapAndInvoiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Perusahaan $perusahaan;
    protected Cabang $cabangSharingProfit;
    protected Cabang $cabangSelfRun;
    protected Cabang $cabangSewa;
    protected Cabang $cabangJanus;
    protected Cabang $cabangGolio;

    protected Karyawan $superAdmin;
    protected Karyawan $supervisor;
    protected Karyawan $crew;

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
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Newspaper Janus',
        ]);

        $this->cabangGolio = Cabang::create([
            'cabang_id' => 'C0006',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Golio',
        ]);

        $this->cabangSewa = Cabang::create([
            'cabang_id' => 'C0007',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Express Sewa',
        ]);

        $this->cabangSelfRun = Cabang::create([
            'cabang_id' => 'C0008',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Express Self Run',
        ]);

        $this->cabangSharingProfit = Cabang::create([
            'cabang_id' => 'C0009',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Express Sharing Profit',
        ]);

        $roleAdmin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web'], ['role_id' => 'R01']);
        $roleSupervisor = Role::firstOrCreate(['name' => 'Supervisor', 'guard_name' => 'web'], ['role_id' => 'R08']);
        $roleCrew = Role::firstOrCreate(['name' => 'Karyawan', 'guard_name' => 'web'], ['role_id' => 'R07']);

        $this->superAdmin = $this->makeKaryawan('ADM01', 'Super Admin Express', $roleAdmin, 'admin@photomate.id', '930000000001');
        $this->supervisor = $this->makeKaryawan('SPV01', 'Supervisor Express', $roleSupervisor, 'spv@photomate.id', '930000000002');
        $this->crew = $this->makeKaryawan('CRW01', 'Crew Express', $roleCrew, 'crew@photomate.id', '930000000003');

        FinancialAccount::firstOrCreate(['name' => 'Photomate Express'], ['is_active' => true, 'sort_order' => 1]);
        FinancialPaymentMethod::firstOrCreate(['name' => 'QRIS'], ['is_active' => true]);
        FinancialPaymentMethod::firstOrCreate(['name' => 'Tunai'], ['is_active' => true]);

        $parentIncome = FinancialParentCategory::firstOrCreate(['name' => FinancialParentCategory::NAME_INCOME], ['is_active' => true]);
        FinancialCategory::firstOrCreate(
            ['name' => SessionReportFinancialService::CATEGORY_NAME, 'transaction_type' => FinancialCategory::TYPE_INCOME],
            ['parent_category_id' => $parentIncome->id, 'is_active' => true]
        );
        FinancialCategory::firstOrCreate(
            ['name' => 'Pelunasan', 'transaction_type' => FinancialCategory::TYPE_INCOME],
            ['parent_category_id' => $parentIncome->id, 'is_active' => true]
        );
    }

    protected function makeKaryawan(string $id, string $name, Role $role, string $email, string $nik): Karyawan
    {
        $user = Karyawan::create([
            'karyawan_id' => $id,
            'role_id' => $role->role_id,
            'perusahaan_id' => 'P0001',
            'nik' => $nik,
            'nama_lengkap' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1994-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);

        $user->assignRole($role);

        return $user->fresh();
    }

    protected function makeRecap(array $attributes = []): SessionReport
    {
        static $seq = 1;
        $cabangId = $attributes['cabang_id'] ?? $this->cabangSharingProfit->cabang_id;
        $isManual = in_array($cabangId, [$this->cabangSewa->cabang_id, $this->cabangSelfRun->cabang_id], true);
        $reportDate = $attributes['report_date'] ?? '2026-11-15';
        $sessions = $attributes['sessions'] ?? 30;
        $sheets = $attributes['sheets'] ?? 50;
        $cash = $attributes['cash'] ?? ($isManual ? 0 : 600000);
        $qris = $attributes['qris'] ?? ($isManual ? 0 : 900000);
        unset($attributes['sessions'], $attributes['sheets'], $attributes['cash'], $attributes['qris']);

        $report = SessionReport::create(array_merge([
            'report_number' => 'SR-SYNC-' . str_pad((string) ($seq++), 3, '0', STR_PAD_LEFT),
            'report_date' => $reportDate,
            'event_date' => $reportDate,
            'event_note' => 'Default Express Event',
            'cabang_id' => $cabangId,
            'status' => SessionReport::STATUS_APPROVED,
            'wedding_total_sessions' => $isManual ? $sessions : null,
            'wedding_total_sheets' => $isManual ? $sheets : null,
            'total_transactions' => $sessions,
            'total_sessions' => $sheets,
            'total_cash_amount' => $cash,
            'total_cash_sessions' => $cash > 0 ? (int) floor($sheets / 2) : 0,
            'total_cash_transactions' => $cash > 0 ? (int) floor($sessions / 2) : 0,
            'total_qris_amount' => $qris,
            'total_qris_sessions' => $qris > 0 ? (int) ceil($sheets / 2) : 0,
            'total_qris_transactions' => $qris > 0 ? (int) ceil($sessions / 2) : 0,
            'grand_total_amount' => $cash + $qris,
            'submitted_by' => $this->crew->karyawan_id,
            'submitted_at' => $reportDate . ' 20:00:00',
            'approved_by' => $this->supervisor->karyawan_id,
            'approved_at' => $reportDate . ' 21:00:00',
        ], $attributes));

        $report->crews()->attach([$this->crew->karyawan_id]);

        if (! $isManual && $report->transactions()->count() === 0) {
            if ($cash > 0) {
                SessionTransaction::create([
                    'session_report_id' => $report->id,
                    'payment_method' => 'CASH',
                    'session_count' => (int) floor($sheets / 2),
                    'amount' => $cash,
                ]);
            }
            if ($qris > 0) {
                SessionTransaction::create([
                    'session_report_id' => $report->id,
                    'payment_method' => 'QRIS',
                    'session_count' => (int) ceil($sheets / 2),
                    'amount' => $qris,
                ]);
            }
        }

        return $report->fresh(['cabang', 'crews', 'transactions', 'invoice']);
    }

    protected function makeInvoice(array $attributes = []): Invoice
    {
        static $invSeq = 1;
        $total = $attributes['total'] ?? 3500000;

        return Invoice::create(array_merge([
            'invoice_number' => 'INV-EXP-2026-' . str_pad((string) ($invSeq++), 3, '0', STR_PAD_LEFT),
            'client_name' => 'PT Klien Utama',
            'event_name' => 'Event Kontrak Express',
            'event_location' => 'Malang',
            'person_in_charge' => 'Budi PIC',
            'event_date' => '2026-11-15',
            'event_end_date' => '2026-11-15',
            'invoice_date' => '2026-11-01',
            'due_date' => '2026-11-10',
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'down_payment' => 0,
            'status' => 'Lunas',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
        ], $attributes));
    }

    /**
     * Skenario 1, 2, 3, 4:
     * Sharing Profit, Self Run, dan Sewa berstatus Approved dengan event lengkap masuk ke Manajemen HPP;
     * rekap Pending / Draft / belum lengkap / non-Express tidak ikut masuk.
     */
    public function test_1_to_4_sync_imports_approved_sharing_profit_self_run_and_sewa_and_skips_pending_or_incomplete(): void
    {
        $this->actingAs($this->superAdmin);

        // 1. Sharing Profit Approved lengkap
        $sharingRecap = $this->makeRecap([
            'cabang_id' => $this->cabangSharingProfit->cabang_id,
            'event_note' => 'Bazaar Kuliner Sharing Profit',
            'event_date' => '2026-11-10',
            'sessions' => 40,
            'sheets' => 60,
            'cash' => 700000,
            'qris' => 1100000,
        ]);

        // 2. Self Run Approved lengkap
        $selfRunRecap = $this->makeRecap([
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_note' => 'Gathering BUMN Self Run',
            'event_date' => '2026-11-12',
            'sessions' => 55,
            'sheets' => 90,
        ]);

        // 3. Sewa Approved lengkap
        $sewaRecap = $this->makeRecap([
            'cabang_id' => $this->cabangSewa->cabang_id,
            'event_note' => 'Wedding Akbar Sewa',
            'event_date' => '2026-11-14',
            'sessions' => 75,
            'sheets' => 140,
        ]);

        // 4a. Pending (WAITING_APPROVAL) -> tidak boleh masuk
        $this->makeRecap([
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'event_note' => 'Event Masih Pending Approval',
            'event_date' => '2026-11-16',
        ]);

        // 4b. Approved tetapi Tanggal Event belum diisi -> tidak boleh masuk
        $this->makeRecap([
            'cabang_id' => $this->cabangSewa->cabang_id,
            'status' => SessionReport::STATUS_APPROVED,
            'event_note' => 'Event Tanpa Tanggal',
            'event_date' => null,
        ]);

        // 4c. Approved tetapi cabang Janus / Golio -> tidak boleh masuk ke ExpressEvent
        $this->makeRecap([
            'cabang_id' => $this->cabangJanus->cabang_id,
            'status' => SessionReport::STATUS_APPROVED,
            'event_note' => 'Janus Bukan Express',
            'event_date' => '2026-11-16',
        ]);

        $stats = app(ExpressEventSyncService::class)->syncAllApprovedRecaps($this->superAdmin);
        $this->assertSame(3, $stats['events_created']);
        $this->assertSame(3, ExpressEvent::count());

        $evSharing = ExpressEvent::where('event_name', 'Bazaar Kuliner Sharing Profit')->firstOrFail();
        $this->assertTrue($evSharing->isSharingProfit());
        $this->assertFalse($evSharing->requiresInvoice());
        $this->assertSame(40, $evSharing->total_allocated_sessions);
        $this->assertSame(60, $evSharing->total_allocated_sheets);
        $this->assertSame(1800000, $evSharing->effective_revenue);
        $this->assertSame('Menunggu pengelolaan HPP', $evSharing->completeness_status_label);

        $evSelfRun = ExpressEvent::where('event_name', 'Gathering BUMN Self Run')->firstOrFail();
        $this->assertTrue($evSelfRun->isSelfRun());
        $this->assertTrue($evSelfRun->requiresInvoice());
        $this->assertSame(55, $evSelfRun->total_allocated_sessions);
        $this->assertSame(90, $evSelfRun->total_allocated_sheets);
        $this->assertSame(0, $evSelfRun->effective_revenue);
        $this->assertSame('Invoice Belum Terhubung', $evSelfRun->completeness_status_label);

        $evSewa = ExpressEvent::where('event_name', 'Wedding Akbar Sewa')->firstOrFail();
        $this->assertTrue($evSewa->isSewa());
        $this->assertTrue($evSewa->requiresInvoice());
        $this->assertSame(75, $evSewa->total_allocated_sessions);
        $this->assertSame(140, $evSewa->total_allocated_sheets);
        $this->assertSame(0, $evSewa->effective_revenue);
        $this->assertSame('Invoice Belum Terhubung', $evSewa->completeness_status_label);

        $this->assertDatabaseMissing('express_events', ['event_name' => 'Event Masih Pending Approval']);
        $this->assertDatabaseMissing('express_events', ['event_name' => 'Event Tanpa Tanggal']);
        $this->assertDatabaseMissing('express_events', ['event_name' => 'Janus Bukan Express']);
    }

    /**
     * Skenario 5 & Auto-Sync Approval:
     * Rekap historis lintas tahun tersinkronisasi lewat tombol Sinkronkan Rekap Baru,
     * dan rekap baru yang di-approve otomatis masuk saat sudah lengkap.
     */
    public function test_5_historical_unsynced_recaps_and_newly_approved_recaps_sync_reliably(): void
    {
        MailNotification::fake();
        $this->actingAs($this->superAdmin);

        // Rekap historis lama (tahun 2024) yang belum pernah masuk ke Manajemen HPP
        $oldRecap2024 = $this->makeRecap([
            'cabang_id' => $this->cabangSewa->cabang_id,
            'report_date' => '2024-07-20',
            'event_date' => '2024-07-19',
            'event_note' => 'Konser Historis 2024',
            'sessions' => 85,
            'sheets' => 170,
        ]);

        // Klik tombol "Sinkronkan Rekap Baru" ketika filter bulan sedang di bulan berjalan (2026)
        Livewire::test(ListExpressEvents::class)
            ->call('syncRecaps')
            ->assertNotified('Sinkronisasi Selesai');

        $historicalEvent = ExpressEvent::where('event_name', 'Konser Historis 2024')->first();
        $this->assertNotNull($historicalEvent);
        $this->assertSame('2024-07-19', $historicalEvent->start_date?->toDateString());
        $this->assertSame(170, $historicalEvent->total_allocated_sheets);
        $this->assertSame([$oldRecap2024->id], $historicalEvent->sessionReports()->pluck('session_reports.id')->all());

        // Rekap baru WAITING_APPROVAL -> saat di-approve oleh Supervisor langsung auto-sync
        $pendingRecap = $this->makeRecap([
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'report_date' => '2026-11-18',
            'event_date' => '2026-11-18',
            'event_note' => 'Auto Sync Saat Approval',
            'sessions' => 45,
            'sheets' => 90,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        $this->assertDatabaseMissing('express_events', ['event_name' => 'Auto Sync Saat Approval']);

        app(SessionWorkflowService::class)->approve($pendingRecap, $this->supervisor);

        $this->assertDatabaseHas('express_events', [
            'event_name' => 'Auto Sync Saat Approval',
            'operational_type' => ExpressEvent::TYPE_SELF_RUN,
        ]);
    }

    /**
     * Skenario 6 & 14:
     * Beberapa rekap sesi dari event yang sama digabung ke satu Event HPP tanpa duplikasi,
     * nama event generik di bulan berbeda tetap terpisah, dan sinkronisasi berulang idempoten.
     */
    public function test_6_and_14_multiple_recaps_of_same_event_group_without_duplication_and_repeated_sync_is_idempotent(): void
    {
        $this->actingAs($this->superAdmin);

        // Dua rekap pada event Sharing Profit yang sama (Booth 1 & Booth 2 di tanggal yang sama)
        $booth1 = $this->makeRecap([
            'cabang_id' => $this->cabangSharingProfit->cabang_id,
            'report_date' => '2026-11-20',
            'event_date' => '2026-11-20',
            'event_note' => ' Jatim Fair Expo ',
            'sessions' => 30,
            'sheets' => 50,
            'cash' => 500000,
            'qris' => 1000000,
        ]);

        $booth2 = $this->makeRecap([
            'cabang_id' => $this->cabangSharingProfit->cabang_id,
            'report_date' => '2026-11-21',
            'event_date' => '2026-11-21',
            'event_note' => 'jatim   fair expo',
            'sessions' => 25,
            'sheets' => 40,
            'cash' => 400000,
            'qris' => 600000,
        ]);

        // Nama event generik yang kebetulan sama tetapi berbeda event (6 bulan sebelumnya) -> tidak boleh tergabung
        $differentMonthEvent = $this->makeRecap([
            'cabang_id' => $this->cabangSharingProfit->cabang_id,
            'report_date' => '2026-04-10',
            'event_date' => '2026-04-10',
            'event_note' => 'Corporate Gathering',
            'sessions' => 20,
            'sheets' => 30,
            'cash' => 300000,
            'qris' => 300000,
        ]);

        $laterSameGenericName = $this->makeRecap([
            'cabang_id' => $this->cabangSharingProfit->cabang_id,
            'report_date' => '2026-11-25',
            'event_date' => '2026-11-25',
            'event_note' => 'Corporate Gathering',
            'sessions' => 35,
            'sheets' => 55,
            'cash' => 500000,
            'qris' => 700000,
        ]);

        $syncService = app(ExpressEventSyncService::class);

        $firstRun = $syncService->syncAllApprovedRecaps($this->superAdmin);
        $this->assertSame(3, $firstRun['events_created']);
        $this->assertSame(1, $firstRun['recaps_attached']);

        // Jalankan sinkronisasi berulang kali -> idempoten (0 event baru, 0 duplikasi)
        $secondRun = $syncService->syncAllApprovedRecaps($this->superAdmin);
        $thirdRun = $syncService->syncAllApprovedRecaps($this->superAdmin);
        $this->assertSame(0, $secondRun['events_created']);
        $this->assertSame(0, $secondRun['recaps_attached']);
        $this->assertSame(4, $secondRun['already_synced']);
        $this->assertSame(4, $thirdRun['already_synced']);

        $this->assertSame(3, ExpressEvent::count());

        $jatimFair = ExpressEvent::where('event_name', 'Jatim Fair Expo')->whereDate('start_date', '2026-11-20')->firstOrFail();
        $this->assertSame('2026-11-21', $jatimFair->end_date?->toDateString());
        $this->assertSame(2, $jatimFair->recaps()->count());
        $this->assertSame(55, $jatimFair->total_allocated_sessions);
        $this->assertSame(90, $jatimFair->total_allocated_sheets);
        $this->assertSame(2500000, $jatimFair->effective_revenue);
        $this->assertEqualsCanonicalizing([$booth1->id, $booth2->id], $jatimFair->sessionReports()->pluck('session_reports.id')->all());

        // Corporate Gathering April dan November menjadi 2 event berbeda
        $this->assertSame(2, ExpressEvent::where('event_name', 'Corporate Gathering')->count());
        $this->assertNotNull($differentMonthEvent->fresh());
        $this->assertNotNull($laterSameGenericName->fresh());
    }

    /**
     * Skenario 7, 8, 9, 10, 11, 12, 15:
     * Penghubungan satu & multi invoice pada Self Run & Sewa, DP/Belum Lunas selectable,
     * harga event dari nilai kontrak invoice (bukan DP), eksklusivitas antar event HPP,
     * sinkronisasi perubahan nominal invoice sumber, dan pelepasan invoice tanpa menghapus data asli.
     */
    public function test_7_to_12_and_15_invoice_linking_contract_pricing_exclusivity_filters_and_detaching(): void
    {
        $this->actingAs($this->superAdmin);

        // Event 1: Self Run
        $selfRunRecap1 = $this->makeRecap([
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'report_date' => '2026-11-15',
            'event_date' => '2026-11-15',
            'event_note' => 'Tech Summit Self Run',
            'sessions' => 40,
            'sheets' => 60,
        ]);
        $selfRunRecap2 = $this->makeRecap([
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'report_date' => '2026-11-16',
            'event_date' => '2026-11-16',
            'event_note' => 'Tech Summit Self Run',
            'sessions' => 60,
            'sheets' => 90,
        ]);

        // Event 2: Sewa
        $sewaRecap = $this->makeRecap([
            'cabang_id' => $this->cabangSewa->cabang_id,
            'report_date' => '2026-11-20',
            'event_date' => '2026-11-20',
            'event_note' => 'Gala Wedding Sewa',
            'sessions' => 80,
            'sheets' => 150,
        ]);

        app(ExpressEventSyncService::class)->syncAllApprovedRecaps($this->superAdmin);

        $eventSelfRun = ExpressEvent::where('event_name', 'Tech Summit Self Run')->firstOrFail();
        $eventSewa = ExpressEvent::where('event_name', 'Gala Wedding Sewa')->firstOrFail();

        // Set tarif HPP terlebih dahulu: sebelum invoice dihubungkan, event Self Run / Sewa BELUM siap dikonfirmasi HPP!
        $hppService = app(ExpressEventHppService::class);
        $hppService->updateRate($eventSelfRun, 5000, $this->superAdmin);
        $eventSelfRun->refresh();

        $this->assertFalse($eventSelfRun->isReadyForHppCalculation());
        $this->assertSame('Invoice Belum Terhubung', $eventSelfRun->completeness_status_label);

        try {
            $hppService->confirmHpp($eventSelfRun, $this->superAdmin);
            $this->fail('confirmHpp harus gagal sebelum invoice dihubungkan pada Self Run.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Invoice belum terhubung', $e->getMessage());
        }

        // Buat berbagai status Invoice: Sebagian Dibayar (DP), Belum Dibayar, Lunas
        $invDp = $this->makeInvoice([
            'invoice_number' => 'INV-SR-DP-001',
            'client_name' => 'PT Teknologi Nusantara',
            'event_name' => 'Tech Summit Self Run Utama',
            'event_date' => '2026-11-15',
            'invoice_date' => '2026-11-01',
            'total' => 5000000,          // Nilai kontrak Rp 5.000.000
            'down_payment' => 1500000,   // DP Rp 1.500.000 -> JANGAN dipakai sebagai harga event!
            'status' => 'Sebagian Dibayar',
        ]);

        $invUnpaidAddOn = $this->makeInvoice([
            'invoice_number' => 'INV-SR-UNPAID-002',
            'client_name' => 'PT Teknologi Nusantara',
            'event_name' => 'Tech Summit Add-On Extra Booth',
            'event_date' => '2026-11-16',
            'invoice_date' => '2026-11-05',
            'total' => 2500000,          // Nilai kontrak Rp 2.500.000
            'down_payment' => 0,
            'status' => 'Belum Dibayar',
        ]);

        $invSewaLunas = $this->makeInvoice([
            'invoice_number' => 'INV-SEWA-LUNAS-003',
            'client_name' => 'Keluarga Hendra',
            'event_name' => 'Gala Wedding Sewa',
            'event_date' => '2026-11-20',
            'invoice_date' => '2026-11-10',
            'total' => 4200000,
            'down_payment' => 0,
            'status' => 'Lunas',
            'cabang_id' => $this->cabangSewa->cabang_id,
        ]);

        // Skenario 7, 9, 11, 12: Buka modal pemilihan invoice pada Self Run, uji search & filter, pilih 2 invoice sekaligus (DP + Belum Dibayar)
        $modalComponent = Livewire::test(ExpressEventInvoiceManager::class, ['eventId' => $eventSelfRun->id])
            ->assertSee('Invoice Terkait')
            ->assertSee('Invoice Belum Terhubung')
            ->assertSee('+ Hubungkan Invoice')
            ->call('openModal')
            ->assertSet('isModalOpen', true)
            ->assertSee('INV-SR-DP-001')
            ->assertSee('INV-SR-UNPAID-002')
            ->assertSee('INV-SEWA-LUNAS-003')
            // Uji search
            ->set('search', 'Teknologi Nusantara')
            ->assertSee('INV-SR-DP-001')
            ->assertDontSee('INV-SEWA-LUNAS-003')
            // Uji filter status (DP / Sebagian Dibayar tetap muncul dan dapat dipilih)
            ->set('statusFilter', 'Sebagian Dibayar')
            ->assertSee('INV-SR-DP-001')
            ->assertDontSee('INV-SR-UNPAID-002')
            ->call('resetFilters')
            // Uji filter tanggal
            ->set('dateFrom', '2026-11-15')
            ->set('dateTo', '2026-11-16')
            ->assertSee('INV-SR-DP-001')
            ->assertSee('INV-SR-UNPAID-002')
            ->assertDontSee('INV-SEWA-LUNAS-003')
            ->call('resetFilters')
            // Pilih 2 invoice sekaligus
            ->call('toggleInvoice', $invDp->id)
            ->call('toggleInvoice', $invUnpaidAddOn->id)
            ->call('attachSelectedInvoices')
            ->assertNotified('Invoice Berhasil Dihubungkan')
            ->assertSet('isModalOpen', false)
            ->assertSee('Invoice Terhubung (2)')
            ->assertSee('INV-SR-DP-001')
            ->assertSee('INV-SR-UNPAID-002')
            ->assertSee(RupiahHelper::format(7500000));

        $eventSelfRun->refresh();
        $this->assertTrue($eventSelfRun->hasLinkedInvoices());
        $this->assertTrue($eventSelfRun->isReadyForHppCalculation());
        // Total nilai kontrak = 5.000.000 + 2.500.000 = 7.500.000 (BUKAN DP 1.500.000!)
        $this->assertSame(7500000, $eventSelfRun->total_invoice_amount);
        $this->assertSame(7500000, $eventSelfRun->total_allocated_revenue);
        $this->assertSame(7500000, $eventSelfRun->effective_revenue);
        $this->assertSame('Menunggu konfirmasi', $eventSelfRun->completeness_status_label);

        // Pastikan nominal pada invoice asli TIDAK berubah
        $this->assertSame(5000000.0, (float) $invDp->fresh()->total);
        $this->assertSame(1500000.0, (float) $invDp->fresh()->down_payment);

        // Skenario 10: Invoice yang sudah dipakai di Tech Summit Self Run TIDAK BISA dipilih di Gala Wedding Sewa
        $selectableForSewa = app(ExpressEventInvoiceService::class)->getSelectableInvoices($eventSewa);
        $dpRowForSewa = $selectableForSewa->firstWhere('id', $invDp->id);
        $this->assertTrue($dpRowForSewa['used_by_other']);
        $this->assertSame('Tech Summit Self Run', $dpRowForSewa['other_event_name']);

        Livewire::test(ExpressEventInvoiceManager::class, ['eventId' => $eventSewa->id])
            ->call('openModal')
            ->assertSee('Sudah digunakan: Tech Summit Self Run');

        try {
            app(ExpressEventInvoiceService::class)->attachInvoices($eventSewa, [$invDp->id], $this->superAdmin);
            $this->fail('Invoice yang sudah digunakan event lain harus ditolak di backend.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah digunakan pada event HPP lain', $e->getMessage());
        }

        // Skenario 8: Hubungkan invoice yang valid ke event Sewa & konfirmasi HPP
        app(ExpressEventInvoiceService::class)->attachInvoices($eventSewa, [$invSewaLunas->id], $this->superAdmin);
        $hppService->updateRate($eventSewa, 4000, $this->superAdmin);
        $hppService->confirmHpp($eventSewa, $this->superAdmin);

        $eventSewa->refresh();
        $this->assertSame(4200000, $eventSewa->total_invoice_amount);
        $this->assertSame(600000, $eventSewa->calculated_hpp); // 150 lbr * 4.000
        $this->assertSame(3600000, $eventSewa->gross_profit);
        $this->assertSame('Sudah dikonfirmasi', $eventSewa->completeness_status_label);

        // Perubahan nominal kontrak pada Invoice sumber otomatis memperbarui nilai Event HPP & mencatat audit log
        $invUnpaidAddOn->update([
            'subtotal' => 3000000,
            'total' => 3000000,
        ]);
        $eventSelfRun->refresh();
        $this->assertSame(8000000, $eventSelfRun->total_invoice_amount); // 5.000.000 + 3.000.000
        $this->assertSame(8000000, $eventSelfRun->total_allocated_revenue);
        $this->assertDatabaseHas('express_event_audit_logs', [
            'express_event_id' => $eventSelfRun->id,
            'action' => 'INVOICE_SOURCE_UPDATED',
        ]);

        // Skenario 15: Melepas invoice dari event HPP TIDAK menghapus data invoice asli di tabel invoices
        $modalComponent->call('detachInvoice', $invUnpaidAddOn->id)
            ->assertNotified('Invoice Dilepas');

        $eventSelfRun->refresh();
        $this->assertSame(1, $eventSelfRun->invoices()->count());
        $this->assertSame(5000000, $eventSelfRun->total_invoice_amount);
        $this->assertDatabaseHas('invoices', [
            'id' => $invUnpaidAddOn->id,
            'invoice_number' => 'INV-SR-UNPAID-002',
            'total' => 3000000,
        ]);

        // Verifikasi Halaman Detail Event Express (ViewExpressEvent) untuk Self Run menampilkan section yang diminta
        Livewire::test(ViewExpressEvent::class, ['record' => $eventSelfRun->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Informasi Event')
            ->assertSee('Express Self Run')
            ->assertSee('2 rekap sesi')
            ->assertSee('100 sesi')
            ->assertSee('Informasi Pendapatan')
            ->assertSee('Invoice Terhubung')
            ->assertSee('INV-SR-DP-001')
            ->assertSee(RupiahHelper::format(5000000))
            ->assertSee('Informasi Pengelolaan HPP')
            ->assertSee('Menunggu konfirmasi')
            ->assertSee('Invoice Terkait');
    }

    /**
     * Skenario 13:
     * Express Sharing Profit menampilkan QRIS, Tunai, dan Grand Total dari Rekap Sesi,
     * menyembunyikan section Invoice Terkait, menolak attach invoice di backend,
     * dan langsung dapat dihitung/dikonfirmasi HPP tanpa invoice.
     */
    public function test_13_sharing_profit_uses_recap_revenue_hides_invoice_section_and_confirms_without_invoice(): void
    {
        $this->actingAs($this->superAdmin);

        $this->makeRecap([
            'cabang_id' => $this->cabangSharingProfit->cabang_id,
            'report_date' => '2026-11-22',
            'event_date' => '2026-11-22',
            'event_note' => 'Sharing Profit Kampus',
            'sessions' => 50,
            'sheets' => 80,
            'cash' => 800000,
            'qris' => 1400000,
        ]);

        app(ExpressEventSyncService::class)->syncAllApprovedRecaps($this->superAdmin);

        $event = ExpressEvent::where('event_name', 'Sharing Profit Kampus')->firstOrFail();
        $this->assertTrue($event->isSharingProfit());
        $this->assertFalse($event->requiresInvoice());
        $this->assertSame(1400000, $event->total_recap_qris_amount);
        $this->assertSame(800000, $event->total_recap_cash_amount);
        $this->assertSame(2200000, $event->effective_revenue);
        $this->assertSame('Menunggu pengelolaan HPP', $event->completeness_status_label);

        // Detail Event Express menampilkan Total QRIS, Total Tunai, Grand Total dan MENYEMBUNYIKAN Invoice Terkait
        Livewire::test(ViewExpressEvent::class, ['record' => $event->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Informasi Event')
            ->assertSee('Express Sharing Profit')
            ->assertSee('Informasi Pendapatan')
            ->assertSee('Total QRIS')
            ->assertSee(RupiahHelper::format(1400000))
            ->assertSee('Total Tunai')
            ->assertSee(RupiahHelper::format(800000))
            ->assertSee('Grand Total')
            ->assertSee(RupiahHelper::format(2200000))
            ->assertDontSee('Invoice Terkait')
            ->assertDontSee('+ Hubungkan Invoice');

        // Backend menolak jika dipaksa menghubungkan invoice ke Sharing Profit
        $dummyInvoice = $this->makeInvoice();
        try {
            app(ExpressEventInvoiceService::class)->attachInvoices($event, [$dummyInvoice->id], $this->superAdmin);
            $this->fail('Sharing Profit tidak boleh menerima penghubungan invoice.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Self Run dan Express Sewa', $e->getMessage());
        }

        // Konfirmasi HPP Sharing Profit berjalan normal dan tercatat akurat di Dashboard HPP & Rekap Perbulan
        $hppService = app(ExpressEventHppService::class);
        $hppService->updateRate($event, 3500, $this->superAdmin);
        $this->assertSame('Menunggu konfirmasi', $event->fresh()->completeness_status_label);

        $hppService->confirmHpp($event, $this->superAdmin);
        $event->refresh();

        $this->assertSame('Sudah dikonfirmasi', $event->completeness_status_label);
        $this->assertSame(280000, $event->calculated_hpp); // 80 lbr * 3.500
        $this->assertSame(1920000, $event->gross_profit);  // 2.200.000 - 280.000

        $summary = app(HppDashboardService::class)->getSummary(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $this->assertSame(280000, $summary['total_hpp_express']);
        $this->assertSame(2200000, $summary['branches']['Express']['total_revenue']);

        $monthly = app(RekapPerbulanService::class)->getReportData(2026, 11);
        $this->assertSame(2200000, $monthly['booth_table']['rows']['Express']['income']);
        $this->assertSame(280000, $monthly['booth_table']['rows']['Express']['hpp']);
    }

    public function test_recap_deletion_protects_confirmed_hpp_history_and_cleanly_detaches_unconfirmed(): void
    {
        $this->actingAs($this->superAdmin);

        $confirmedRecap = $this->makeRecap([
            'cabang_id' => $this->cabangSharingProfit->cabang_id,
            'event_note' => 'Event Terkonfirmasi Terlindungi',
            'event_date' => '2026-11-18',
        ]);
        $unconfirmedRecap = $this->makeRecap([
            'cabang_id' => $this->cabangSharingProfit->cabang_id,
            'event_note' => 'Event Draft HPP',
            'event_date' => '2026-11-19',
        ]);

        app(ExpressEventSyncService::class)->syncAllApprovedRecaps($this->superAdmin);

        $confirmedEvent = ExpressEvent::where('event_name', 'Event Terkonfirmasi Terlindungi')->firstOrFail();
        $unconfirmedEvent = ExpressEvent::where('event_name', 'Event Draft HPP')->firstOrFail();

        $hppService = app(ExpressEventHppService::class);
        $hppService->updateRate($confirmedEvent, 4000, $this->superAdmin);
        $hppService->confirmHpp($confirmedEvent, $this->superAdmin);

        // Menghapus rekap yang sudah terhubung ke Event HPP Terkonfirmasi harus ditolak agar histori HPP tidak rusak
        try {
            $confirmedRecap->delete();
            $this->fail('Menghapus rekap dari Event HPP terkonfirmasi harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah dikonfirmasi', $e->getMessage());
        }

        $this->assertDatabaseHas('session_reports', ['id' => $confirmedRecap->id]);

        // Menghapus rekap pada Event HPP yang belum dikonfirmasi melepaskan alokasi dengan bersih & mencatat audit log
        $unconfirmedRecap->delete();
        $unconfirmedEvent->refresh();

        $this->assertSame(0, $unconfirmedEvent->recaps()->count());
        $this->assertSame(ExpressEvent::RECON_UNALLOCATED, $unconfirmedEvent->reconciliation_status);
        $this->assertDatabaseHas('express_event_audit_logs', [
            'express_event_id' => $unconfirmedEvent->id,
            'action' => 'RECAP_DELETED',
        ]);
    }
}
