<?php

namespace Tests\Feature;

use App\Filament\Resources\ExpressEventResource\Pages\CreateExpressEvent;
use App\Filament\Resources\ExpressEventResource\Pages\EditExpressEvent;
use App\Filament\Resources\ExpressEventResource\Pages\ListExpressEvents;
use App\Filament\Resources\ExpressEventResource\Pages\ViewExpressEvent;
use App\Models\Cabang;
use App\Models\Event;
use App\Models\ExpressEvent;
use App\Models\ExpressEventInvoice;
use App\Models\ExpressEventRecap;
use App\Models\ExpressEventRecapAllocation;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialParentCategory;
use App\Models\FinancialPaymentMethod;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\PricingSetting;
use App\Models\Role;
use App\Models\SessionReport;
use App\Services\ExpressEventAllocationService;
use App\Services\ExpressEventConsolidationService;
use App\Services\ExpressEventHppService;
use App\Services\ExpressEventInvoiceService;
use App\Services\ExpressEventSyncService;
use App\Services\SessionReportFinancialService;
use App\Services\SessionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as MailNotification;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ExpressEventGroupingAndHppTest extends TestCase
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

        MailNotification::fake();

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

        $this->superAdmin = $this->makeKaryawan('ADM01', 'Super Admin Express', $roleAdmin, 'admin@photomate.id', '940000000001');
        $this->supervisor = $this->makeKaryawan('SPV01', 'Supervisor Express', $roleSupervisor, 'spv@photomate.id', '940000000002');
        $this->crew = $this->makeKaryawan('CRW01', 'Crew Express', $roleCrew, 'crew@photomate.id', '940000000003');

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

        $cabangId = $attributes['cabang_id'] ?? $this->cabangSelfRun->cabang_id;
        $isManual = in_array($cabangId, [$this->cabangSewa->cabang_id, $this->cabangSelfRun->cabang_id], true);
        $reportDate = $attributes['report_date'] ?? '2026-10-29';
        $eventDate = array_key_exists('event_date', $attributes) ? $attributes['event_date'] : $reportDate;
        $sessions = $attributes['sessions'] ?? 30;
        $sheets = $attributes['sheets'] ?? 50;
        $cash = $attributes['cash'] ?? ($isManual ? 0 : 600000);
        $qris = $attributes['qris'] ?? ($isManual ? 0 : 900000);

        unset($attributes['sessions'], $attributes['sheets'], $attributes['cash'], $attributes['qris']);

        return SessionReport::create(array_merge([
            'report_number' => 'SR-GRP-' . str_pad((string) ($seq++), 3, '0', STR_PAD_LEFT),
            'report_date' => $reportDate,
            'event_date' => $eventDate,
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
    }

    protected function makeEvent(array $attributes = []): Event
    {
        static $evSeq = 1;
        $num = $evSeq++;

        return Event::create(array_merge([
            'name' => 'Default Express Event',
            'event_code' => 'EVT-GRP-' . str_pad((string) $num, 3, '0', STR_PAD_LEFT),
            'location' => 'Malang',
            'date' => '2026-10-29',
            'status' => 'OPEN',
        ], $attributes));
    }

    protected function makeInvoice(array $attributes = []): Invoice
    {
        static $invSeq = 1;
        $total = $attributes['total'] ?? 5000000;

        return Invoice::create(array_merge([
            'invoice_number' => 'INV-GRP-' . str_pad((string) ($invSeq++), 3, '0', STR_PAD_LEFT),
            'client_name' => 'PT Klien Photomate',
            'event_name' => 'Default Express Event',
            'event_location' => 'Malang',
            'person_in_charge' => 'Budi PIC',
            'event_date' => '2026-10-29',
            'event_end_date' => '2026-10-29',
            'invoice_date' => '2026-10-25',
            'due_date' => '2026-10-28',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'down_payment' => 0,
            'status' => 'Lunas',
        ], $attributes));
    }

    // =========================================================================
    // SKENARIO 1 — Satu Event, Dua Rekap Sesi (Halloween Blockparty 2026)
    // =========================================================================

    public function test_scenario_1_same_operational_event_with_multiple_recaps_groups_into_single_hpp_event(): void
    {
        $this->actingAs($this->superAdmin);

        $operationalEvent = $this->makeEvent([
            'name' => 'Halloween Blockparty 2026',
            'date' => '2026-10-29',
        ]);

        $recapDay1 = $this->makeRecap([
            'report_number' => 'SR-HLW-01',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_id' => $operationalEvent->id,
            'event_note' => 'Halloween Blockparty 2026',
            'event_date' => '2026-10-29',
            'report_date' => '2026-10-29',
            'sessions' => 23,
            'sheets' => 20,
        ]);

        $recapDay2And3 = $this->makeRecap([
            'report_number' => 'SR-HLW-02',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_id' => $operationalEvent->id,
            'event_note' => 'Halloween Blockparty 2026',
            'event_date' => '2026-10-30',
            'report_date' => '2026-10-31',
            'sessions' => 77,
            'sheets' => 68,
        ]);

        $stats = app(ExpressEventSyncService::class)->syncAllApprovedRecaps($this->superAdmin);

        $this->assertSame(1, $stats['events_created']);
        $this->assertSame(1, $stats['recaps_attached']);
        $this->assertSame(1, ExpressEvent::count());

        $hppEvent = ExpressEvent::with(['recaps.sessionReport', 'allocations'])->firstOrFail();

        $this->assertSame('Halloween Blockparty 2026', $hppEvent->event_name);
        $this->assertSame($operationalEvent->id, $hppEvent->event_id);
        $this->assertSame("event:{$operationalEvent->id}", $hppEvent->operational_identity_key);
        $this->assertSame('2026-10-29', $hppEvent->start_date->toDateString());
        $this->assertSame('2026-10-31', $hppEvent->end_date->toDateString());
        $this->assertSame('29/10/2026 - 31/10/2026', $hppEvent->period_range_label);
        $this->assertSame(100, $hppEvent->total_allocated_sessions);
        $this->assertSame(88, $hppEvent->total_allocated_sheets);
        $this->assertFalse($hppEvent->needs_admin_verification);
        $this->assertCount(2, $hppEvent->recaps);

        // Sinkronisasi ulang bersifat idempoten dan tidak menduplikasi baris
        $repeatStats = app(ExpressEventSyncService::class)->syncAllApprovedRecaps($this->superAdmin);
        $this->assertSame(0, $repeatStats['events_created']);
        $this->assertSame(1, ExpressEvent::count());

        // Halaman Daftar & Detail menampilkan 1 event dengan 2 rincian rekap sesi
        Livewire::test(ListExpressEvents::class)
            ->call('setTab', 'express')
            ->assertCanSeeTableRecords([$hppEvent])
            ->assertSee('Halloween Blockparty 2026')
            ->assertSee('29/10/2026 - 31/10/2026');

        Livewire::test(ViewExpressEvent::class, ['record' => $hppEvent->getRouteKey()])
            ->assertSee('Alokasi Rekap Sesi Express')
            ->assertSee('Alokasi Invoice Event')
            ->assertSee($recapDay1->report_number)
            ->assertSee($recapDay2And3->report_number);
    }

    // =========================================================================
    // SKENARIO 2 — Dua Event Berbeda dengan Nama Sama (Gathering PT Sampoerna 2026)
    // =========================================================================

    public function test_scenario_2_same_event_name_with_different_operational_identities_stays_separate(): void
    {
        $this->actingAs($this->superAdmin);

        $eventOct06 = $this->makeEvent([
            'name' => 'Gathering PT Sampoerna 2026',
            'date' => '2026-10-06',
            'status' => 'CLOSED',
        ]);
        $eventOct20 = $this->makeEvent([
            'name' => 'Gathering PT Sampoerna 2026',
            'date' => '2026-10-20',
            'status' => 'OPEN',
        ]);

        $invoiceOct06 = $this->makeInvoice([
            'invoice_number' => 'INV-SMP-0610',
            'client_name' => 'PT HM Sampoerna Tbk',
            'event_name' => 'Gathering PT Sampoerna 2026',
            'event_date' => '2026-10-06',
            'cabang_id' => $this->cabangSewa->cabang_id,
            'subtotal' => 4500000,
            'total' => 4500000,
        ]);

        $invoiceOct20 = $this->makeInvoice([
            'invoice_number' => 'INV-SMP-2010',
            'client_name' => 'PT HM Sampoerna Tbk',
            'event_name' => 'Gathering PT Sampoerna 2026',
            'event_date' => '2026-10-20',
            'cabang_id' => $this->cabangSewa->cabang_id,
            'subtotal' => 6000000,
            'total' => 6000000,
        ]);

        $recapOct06 = $this->makeRecap([
            'report_number' => 'SR-SMP-01',
            'cabang_id' => $this->cabangSewa->cabang_id,
            'event_id' => $eventOct06->id,
            'invoice_id' => $invoiceOct06->id,
            'event_note' => 'Gathering PT Sampoerna 2026',
            'event_date' => '2026-10-06',
            'report_date' => '2026-10-06',
            'sessions' => 40,
            'sheets' => 50,
        ]);

        $recapOct20 = $this->makeRecap([
            'report_number' => 'SR-SMP-02',
            'cabang_id' => $this->cabangSewa->cabang_id,
            'event_id' => $eventOct20->id,
            'invoice_id' => $invoiceOct20->id,
            'event_note' => 'Gathering PT Sampoerna 2026',
            'event_date' => '2026-10-20',
            'report_date' => '2026-10-20',
            'sessions' => 55,
            'sheets' => 70,
        ]);

        // Verifikasi pemisahan grup pada Create Event manual: memilih nama event hanya mengisi 1 identitas operasional, bukan menggabung keduanya!
        $groups = app(ExpressEventAllocationService::class)
            ->getOperationalIdentityGroupsForEventName('Gathering PT Sampoerna 2026');
        $this->assertCount(2, $groups);

        // Verifikasi sinkronisasi otomatis menghasilkan 2 ExpressEvent terpisah
        $stats = app(ExpressEventSyncService::class)->syncAllApprovedRecaps($this->superAdmin);

        $this->assertSame(2, $stats['events_created']);
        $this->assertSame(0, $stats['recaps_attached']);
        $this->assertSame(2, ExpressEvent::count());

        $hppEvents = ExpressEvent::orderBy('start_date')->get();
        $firstHpp = $hppEvents[0];
        $secondHpp = $hppEvents[1];

        $this->assertSame($eventOct06->id, $firstHpp->event_id);
        $this->assertSame('06/10/2026', $firstHpp->period_range_label);
        $this->assertSame(40, $firstHpp->total_allocated_sessions);
        $this->assertSame(50, $firstHpp->total_allocated_sheets);
        $this->assertFalse($firstHpp->needs_admin_verification);

        $this->assertSame($eventOct20->id, $secondHpp->event_id);
        $this->assertSame('20/10/2026', $secondHpp->period_range_label);
        $this->assertSame(55, $secondHpp->total_allocated_sessions);
        $this->assertSame(70, $secondHpp->total_allocated_sheets);
        $this->assertFalse($secondHpp->needs_admin_verification);

        // Masing-masing dihubungkan ke invoice tersendiri
        $invoiceService = app(ExpressEventInvoiceService::class);
        $invoiceService->attachInvoices($firstHpp, [$invoiceOct06->id], $this->superAdmin);
        $invoiceService->attachInvoices($secondHpp, [$invoiceOct20->id], $this->superAdmin);

        $this->assertSame(4500000, $firstHpp->fresh()->effective_revenue);
        $this->assertSame(6000000, $secondHpp->fresh()->effective_revenue);

        // Penggabungan paksa 2 rekap dengan event_id berbeda pada satu Event HPP harus ditolak
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ID Event Operasional berbeda');

        app(ExpressEventAllocationService::class)->syncAllocations($firstHpp, [
            [
                'session_report_id' => $recapOct06->id,
                'allocated_sessions' => 40,
                'allocated_sheets' => 50,
                'allocated_revenue' => 0,
            ],
            [
                'session_report_id' => $recapOct20->id,
                'allocated_sessions' => 55,
                'allocated_sheets' => 70,
                'allocated_revenue' => 0,
            ],
        ]);
    }

    // =========================================================================
    // SKENARIO 3 — Invoice Hanya Dihitung Sekali & Rekalkulasi HPP Real-Time
    // =========================================================================

    public function test_scenario_3_invoice_counted_only_once_across_multiple_recaps_and_realtime_hpp(): void
    {
        $this->actingAs($this->superAdmin);

        $opEvent = $this->makeEvent([
            'name' => 'Halloween Blockparty 2026',
            'date' => '2026-10-29',
        ]);

        $invoice = $this->makeInvoice([
            'invoice_number' => 'INV-HLW-5JT',
            'event_name' => 'Halloween Blockparty 2026',
            'event_date' => '2026-10-29',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'subtotal' => 5000000,
            'total' => 5000000,
        ]);

        $this->makeRecap([
            'report_number' => 'SR-HLW-A',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_id' => $opEvent->id,
            'invoice_id' => $invoice->id,
            'event_note' => 'Halloween Blockparty 2026',
            'event_date' => '2026-10-29',
            'report_date' => '2026-10-29',
            'sessions' => 23,
            'sheets' => 20,
        ]);

        $this->makeRecap([
            'report_number' => 'SR-HLW-B',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_id' => $opEvent->id,
            'invoice_id' => $invoice->id,
            'event_note' => 'Halloween Blockparty 2026',
            'event_date' => '2026-10-30',
            'report_date' => '2026-10-31',
            'sessions' => 77,
            'sheets' => 68,
        ]);

        app(ExpressEventSyncService::class)->syncAllApprovedRecaps($this->superAdmin);
        $hppEvent = ExpressEvent::firstOrFail();

        // Sebelum invoice dihubungkan pada Express Self Run, omzet HPP wajib 0 (bukan dari rekap)
        $this->assertSame(0, $hppEvent->effective_revenue);
        $this->assertSame('Invoice Belum Terhubung', $hppEvent->invoice_connection_status_label);

        // Hubungkan 1 invoice Rp 5.000.000, atur tarif HPP Rp 5.000/lembar, lalu konfirmasi HPP
        app(ExpressEventInvoiceService::class)->attachInvoices($hppEvent, [$invoice->id], $this->superAdmin);
        app(ExpressEventHppService::class)->updateRate($hppEvent->fresh(), 5000, $this->superAdmin);
        app(ExpressEventHppService::class)->confirmHpp($hppEvent->fresh(), $this->superAdmin);

        $hppEvent = $hppEvent->fresh(['invoices', 'recaps.allocations', 'latestHpp']);

        // Invoice Rp 5.000.000 WAJIB dihitung 1 kali (Rp 5.000.000), BUKAN 2 kali (Rp 10.000.000)!
        $this->assertSame(100, $hppEvent->total_allocated_sessions);
        $this->assertSame(88, $hppEvent->total_allocated_sheets);
        $this->assertSame(5000000, $hppEvent->total_invoice_amount);
        $this->assertSame(5000000, $hppEvent->effective_revenue);
        $this->assertSame(5000000, $hppEvent->total_allocated_revenue);
        $this->assertSame(440000, $hppEvent->calculated_hpp);   // 88 lembar × Rp 5.000
        $this->assertSame(4560000, $hppEvent->gross_profit);    // Rp 5.000.000 - Rp 440.000
        $this->assertSame('Invoice Terhubung', $hppEvent->invoice_connection_status_label);

        $this->assertNotNull($hppEvent->latestHpp);
        $this->assertSame(5000000, (int) $hppEvent->latestHpp->total_revenue);
        $this->assertSame(440000, (int) $hppEvent->latestHpp->total_hpp);
        $this->assertSame(4560000, (int) $hppEvent->latestHpp->gross_profit);

        // Perubahan nilai sumber pada Invoice otomatis memperbarui perhitungan HPP event
        $invoice->update(['subtotal' => 5500000, 'total' => 5500000]);
        $hppEvent = $hppEvent->fresh(['invoices', 'latestHpp']);
        $this->assertSame(5500000, $hppEvent->effective_revenue);
        $this->assertSame(5060000, $hppEvent->gross_profit);
        $this->assertSame(5500000, (int) $hppEvent->latestHpp->total_revenue);
        $this->assertSame(5060000, (int) $hppEvent->latestHpp->gross_profit);

        // Pelepasan invoice otomatis mengembalikan omzet ke 0 dan memperbarui status HPP
        app(ExpressEventInvoiceService::class)->detachInvoice($hppEvent, $invoice->id, $this->superAdmin);
        $hppEvent = $hppEvent->fresh(['invoices', 'latestHpp']);
        $this->assertSame(0, $hppEvent->effective_revenue);
        $this->assertSame('Invoice Belum Terhubung', $hppEvent->invoice_connection_status_label);
        $this->assertSame(0, (int) $hppEvent->latestHpp->total_revenue);
    }

    // =========================================================================
    // SKENARIO 4 — Beberapa Invoice untuk Satu Event
    // =========================================================================

    public function test_scenario_4_multiple_invoices_linked_to_single_event_sums_contract_values(): void
    {
        $this->actingAs($this->superAdmin);

        $recap = $this->makeRecap([
            'report_number' => 'SR-MULTI-INV-01',
            'cabang_id' => $this->cabangSewa->cabang_id,
            'event_note' => 'Festival Musik Raya 2026',
            'event_date' => '2026-11-10',
            'report_date' => '2026-11-10',
            'sessions' => 60,
            'sheets' => 100,
        ]);

        $invoiceMain = $this->makeInvoice([
            'invoice_number' => 'INV-FMR-MAIN',
            'event_name' => 'Festival Musik Raya 2026',
            'event_date' => '2026-11-10',
            'cabang_id' => $this->cabangSewa->cabang_id,
            'subtotal' => 5000000,
            'total' => 5000000,
        ]);

        $invoiceAddOn = $this->makeInvoice([
            'invoice_number' => 'INV-FMR-EXTRA',
            'event_name' => 'Festival Musik Raya 2026 (Extra Hours)',
            'event_date' => '2026-11-10',
            'cabang_id' => $this->cabangSewa->cabang_id,
            'subtotal' => 2000000,
            'total' => 2000000,
        ]);

        Livewire::test(CreateExpressEvent::class)
            ->set('data.event_name', 'Festival Musik Raya 2026')
            ->call('openInvoiceModal')
            ->set('modalSelectedInvoiceIds', [$invoiceMain->id, $invoiceAddOn->id])
            ->call('confirmAttachModalInvoices')
            ->set('data.hpp_rate_per_sheet', '4.500')
            ->call('create')
            ->assertHasNoFormErrors();

        $event = ExpressEvent::where('event_name', 'Festival Musik Raya 2026')->firstOrFail();

        $this->assertCount(2, $event->invoices);
        $this->assertSame(100, $event->total_allocated_sheets);
        $this->assertSame(7000000, $event->total_invoice_amount);
        $this->assertSame(7000000, $event->effective_revenue);
        $this->assertSame(450000, $event->calculated_hpp);   // 100 × 4.500
        $this->assertSame(6550000, $event->gross_profit);    // 7.000.000 - 450.000
        $this->assertSame($recap->id, $event->recaps()->first()->session_report_id);
    }

    // =========================================================================
    // SKENARIO 5 — Invoice yang Sudah Terhubung ke Event HPP Lain Ditolak
    // =========================================================================

    public function test_scenario_5_invoice_already_linked_to_other_hpp_event_cannot_be_reused(): void
    {
        $this->actingAs($this->superAdmin);

        $sharedInvoice = $this->makeInvoice([
            'invoice_number' => 'INV-LOCKED-001',
            'event_name' => 'Gala Dinner Eksklusif',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'total' => 6000000,
        ]);

        $recapA = $this->makeRecap([
            'report_number' => 'SR-LOCK-A',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_note' => 'Gala Dinner Eksklusif',
            'event_date' => '2026-11-05',
            'report_date' => '2026-11-05',
            'sessions' => 30,
            'sheets' => 40,
        ]);

        $recapB = $this->makeRecap([
            'report_number' => 'SR-LOCK-B',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_note' => 'Konferensi Bisnis Tahunan',
            'event_date' => '2026-11-12',
            'report_date' => '2026-11-12',
            'sessions' => 35,
            'sheets' => 45,
        ]);

        app(ExpressEventSyncService::class)->syncAllApprovedRecaps($this->superAdmin);

        $eventA = ExpressEvent::where('event_name', 'Gala Dinner Eksklusif')->firstOrFail();
        $eventB = ExpressEvent::where('event_name', 'Konferensi Bisnis Tahunan')->firstOrFail();

        $invoiceService = app(ExpressEventInvoiceService::class);
        $invoiceService->attachInvoices($eventA, [$sharedInvoice->id], $this->superAdmin);

        // Pada daftar pilihan modal Event B, invoice harus ditandai used_by_other = true
        $selectableForB = $invoiceService->getSelectableInvoices($eventB);
        $row = $selectableForB->firstWhere('id', $sharedInvoice->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['used_by_other']);
        $this->assertSame('Gala Dinner Eksklusif', $row['other_event_name']);

        // Pada modal Livewire Edit Event B, pemilihan invoice tersebut diabaikan/ditolak
        Livewire::test(EditExpressEvent::class, ['record' => $eventB->getRouteKey()])
            ->call('openInvoiceModal')
            ->set('modalSelectedInvoiceIds', [$sharedInvoice->id])
            ->call('confirmAttachModalInvoices')
            ->assertSet('data.linked_invoice_ids', []);

        // Pemanggilan langsung ke service wajib melempar pesan error eksplisit
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah digunakan pada event HPP lain');

        $invoiceService->attachInvoices($eventB, [$sharedInvoice->id], $this->superAdmin);
    }

    // =========================================================================
    // SKENARIO 6 — Invoice Status DP / Belum Lunas Memakai Nilai Kontrak Penuh
    // =========================================================================

    public function test_scenario_6_dp_or_unpaid_invoice_uses_full_contract_value(): void
    {
        $this->actingAs($this->superAdmin);

        $this->makeRecap([
            'report_number' => 'SR-DP-01',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_note' => 'Wedding Akbar & Citra',
            'event_date' => '2026-11-14',
            'report_date' => '2026-11-14',
            'sessions' => 50,
            'sheets' => 80,
        ]);

        $dpInvoice = $this->makeInvoice([
            'invoice_number' => 'INV-DP-5JT',
            'client_name' => 'Akbar & Citra',
            'event_name' => 'Wedding Akbar & Citra',
            'event_date' => '2026-11-14',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'subtotal' => 5000000,
            'total' => 5000000,
            'down_payment' => 2000000,
            'status' => 'Sebagian Dibayar',
        ]);

        app(ExpressEventSyncService::class)->syncAllApprovedRecaps($this->superAdmin);
        $event = ExpressEvent::where('event_name', 'Wedding Akbar & Citra')->firstOrFail();

        app(ExpressEventInvoiceService::class)->attachInvoices($event, [$dpInvoice->id], $this->superAdmin);
        app(ExpressEventHppService::class)->updateRate($event->fresh(), 5000, $this->superAdmin);
        app(ExpressEventHppService::class)->confirmHpp($event->fresh(), $this->superAdmin);

        $event = $event->fresh(['invoices', 'latestHpp']);

        // Wajib memakai total kontrak Rp 5.000.000, BUKAN DP Rp 2.000.000
        $this->assertSame(5000000, $event->total_invoice_amount);
        $this->assertSame(5000000, $event->effective_revenue);
        $this->assertSame(400000, $event->calculated_hpp);   // 80 × 5.000
        $this->assertSame(4600000, $event->gross_profit);    // 5.000.000 - 400.000
        $this->assertSame(5000000, (int) $event->latestHpp->total_revenue);
    }

    // =========================================================================
    // SKENARIO 7 — Express Sharing Profit Menghitung Omzet dari Rekap Sesi Approved
    // =========================================================================

    public function test_scenario_7_express_sharing_profit_calculates_revenue_from_approved_recaps_not_invoices(): void
    {
        $this->actingAs($this->superAdmin);

        $this->makeRecap([
            'report_number' => 'SR-SP-DAY1',
            'cabang_id' => $this->cabangSharingProfit->cabang_id,
            'event_note' => 'Pop Up Market Tunjungan',
            'event_date' => '2026-11-01',
            'report_date' => '2026-11-01',
            'sessions' => 25,
            'sheets' => 30,
            'cash' => 450000,
            'qris' => 900000, // 1.350.000
        ]);

        $this->makeRecap([
            'report_number' => 'SR-SP-DAY2',
            'cabang_id' => $this->cabangSharingProfit->cabang_id,
            'event_note' => 'Pop Up Market Tunjungan',
            'event_date' => '2026-11-02',
            'report_date' => '2026-11-02',
            'sessions' => 35,
            'sheets' => 40,
            'cash' => 650000,
            'qris' => 1000000, // 1.650.000
        ]);

        app(ExpressEventSyncService::class)->syncAllApprovedRecaps($this->superAdmin);

        $this->assertSame(1, ExpressEvent::count());
        $spEvent = ExpressEvent::firstOrFail();

        $this->assertTrue($spEvent->isSharingProfit());
        $this->assertFalse($spEvent->requiresInvoice());
        $this->assertSame('01/11/2026 - 02/11/2026', $spEvent->period_range_label);
        $this->assertSame(60, $spEvent->total_allocated_sessions);
        $this->assertSame(70, $spEvent->total_allocated_sheets);
        $this->assertSame(3000000, $spEvent->effective_revenue);
        $this->assertSame('Tidak Memerlukan Invoice', $spEvent->invoice_connection_status_label);

        app(ExpressEventHppService::class)->updateRate($spEvent, 4000, $this->superAdmin);
        $spEvent = $spEvent->fresh(['latestHpp']);

        $this->assertSame(280000, $spEvent->calculated_hpp);  // 70 × 4.000
        $this->assertSame(2720000, $spEvent->gross_profit);   // 3.000.000 - 280.000
    }

    // =========================================================================
    // SKENARIO 8 — Rekap Sesi Belum Approved Tidak Masuk Perhitungan & Cancel Approval Real-Time
    // =========================================================================

    public function test_scenario_8_non_approved_recaps_excluded_and_cancelled_approval_recalculates_hpp(): void
    {
        $this->actingAs($this->superAdmin);

        $opEvent = $this->makeEvent([
            'name' => 'Expo Pendidikan 2026',
            'date' => '2026-11-18',
        ]);

        $approvedDay1 = $this->makeRecap([
            'report_number' => 'SR-EXPO-DAY1',
            'cabang_id' => $this->cabangSharingProfit->cabang_id,
            'event_id' => $opEvent->id,
            'event_note' => 'Expo Pendidikan 2026',
            'event_date' => '2026-11-18',
            'report_date' => '2026-11-18',
            'status' => SessionReport::STATUS_APPROVED,
            'sessions' => 30,
            'sheets' => 40,
            'cash' => 500000,
            'qris' => 700000, // 1.200.000
        ]);

        $approvedDay2 = $this->makeRecap([
            'report_number' => 'SR-EXPO-DAY2',
            'cabang_id' => $this->cabangSharingProfit->cabang_id,
            'event_id' => $opEvent->id,
            'event_note' => 'Expo Pendidikan 2026',
            'event_date' => '2026-11-19',
            'report_date' => '2026-11-19',
            'status' => SessionReport::STATUS_APPROVED,
            'sessions' => 35,
            'sheets' => 50,
            'cash' => 600000,
            'qris' => 900000, // 1.500.000
        ]);

        // Rekap Waiting Approval & Rejected pada event yang sama TIDAK BOLEH disinkronkan
        $waitingDay3 = $this->makeRecap([
            'report_number' => 'SR-EXPO-WAITING',
            'cabang_id' => $this->cabangSharingProfit->cabang_id,
            'event_id' => $opEvent->id,
            'event_note' => 'Expo Pendidikan 2026',
            'event_date' => '2026-11-20',
            'report_date' => '2026-11-20',
            'status' => SessionReport::STATUS_WAITING_APPROVAL,
            'approved_by' => null,
            'approved_at' => null,
            'sessions' => 25,
            'sheets' => 35,
            'cash' => 400000,
            'qris' => 600000,
        ]);

        $this->makeRecap([
            'report_number' => 'SR-EXPO-REJECTED',
            'cabang_id' => $this->cabangSharingProfit->cabang_id,
            'event_id' => $opEvent->id,
            'event_note' => 'Expo Pendidikan 2026',
            'event_date' => '2026-11-20',
            'report_date' => '2026-11-20',
            'status' => SessionReport::STATUS_REJECTED,
            'approved_by' => null,
            'approved_at' => null,
            'sessions' => 15,
            'sheets' => 20,
            'cash' => 200000,
            'qris' => 300000,
        ]);

        app(ExpressEventSyncService::class)->syncAllApprovedRecaps($this->superAdmin);

        $event = ExpressEvent::firstOrFail();
        app(ExpressEventHppService::class)->updateRate($event, 5000, $this->superAdmin);
        app(ExpressEventHppService::class)->confirmHpp($event->fresh(), $this->superAdmin);
        $event = $event->fresh(['latestHpp']);

        // Hanya Day 1 (40 lbr, Rp 1.2jt) + Day 2 (50 lbr, Rp 1.5jt) yang masuk = 90 lembar, Rp 2.700.000
        $this->assertSame(65, $event->total_allocated_sessions);
        $this->assertSame(90, $event->total_allocated_sheets);
        $this->assertSame(2700000, $event->effective_revenue);
        $this->assertSame(450000, $event->calculated_hpp);
        $this->assertFalse($event->recaps()->where('session_report_id', $waitingDay3->id)->exists());

        // Ketika approval Day 2 dibatalkan oleh Super Admin, Day 2 berubah menjadi WAITING_APPROVAL
        // dan angka HPP event otomatis mengecualikan Day 2 secara real-time!
        app(SessionWorkflowService::class)->cancelApproval(
            $approvedDay2,
            $this->superAdmin,
            'Verifikasi ulang jumlah lembar cetak hari ke-2'
        );

        $event = $event->fresh(['latestHpp']);
        $this->assertSame('18/11/2026', $event->period_range_label);
        $this->assertSame(30, $event->total_allocated_sessions);
        $this->assertSame(40, $event->total_allocated_sheets);
        $this->assertSame(1200000, $event->effective_revenue);
        $this->assertSame(200000, $event->calculated_hpp);   // 40 × 5.000
        $this->assertSame(1000000, $event->gross_profit);
        $this->assertSame(40, (int) $event->latestHpp->total_sheets);
        $this->assertSame(200000, (int) $event->latestHpp->total_hpp);
        $this->assertNotNull($approvedDay1->fresh());
    }

    // =========================================================================
    // SKENARIO 9 (Section 8) — Konsolidasi Data Historis & Penandaan Verifikasi Admin
    // =========================================================================

    public function test_historical_consolidation_merges_proven_duplicates_and_flags_ambiguous_records_safely(): void
    {
        $this->actingAs($this->superAdmin);

        // 1. Data Historis Terpecah yang TERBUKTI 1 Event Operasional (event_id & invoice_id sama)
        $hlwOpEvent = $this->makeEvent([
            'name' => 'Halloween Blockparty 2026',
            'date' => '2026-10-29',
        ]);
        $hlwInvoice = $this->makeInvoice([
            'invoice_number' => 'INV-HIST-HLW',
            'event_name' => 'Halloween Blockparty 2026',
            'event_date' => '2026-10-29',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'total' => 5000000,
        ]);

        $hlwRecap1 = $this->makeRecap([
            'report_number' => 'SR-HIST-HLW-1',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_id' => $hlwOpEvent->id,
            'invoice_id' => $hlwInvoice->id,
            'event_note' => 'Halloween Blockparty 2026',
            'event_date' => '2026-10-29',
            'report_date' => '2026-10-29',
            'sessions' => 23,
            'sheets' => 20,
        ]);
        $hlwRecap2 = $this->makeRecap([
            'report_number' => 'SR-HIST-HLW-2',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_id' => $hlwOpEvent->id,
            'invoice_id' => $hlwInvoice->id,
            'event_note' => 'Halloween Blockparty 2026',
            'event_date' => '2026-10-30',
            'report_date' => '2026-10-31',
            'sessions' => 77,
            'sheets' => 68,
        ]);

        // Simulasikan kondisi bug lama: 2 baris ExpressEvent terpisah untuk 1 event operasional yang sama
        $legacySplit1 = ExpressEvent::create([
            'event_name' => 'Halloween Blockparty 2026',
            'operational_type' => ExpressEvent::TYPE_SELF_RUN,
            'start_date' => '2026-10-29',
            'end_date' => '2026-10-29',
            'hpp_rate_per_sheet' => 5000,
            'reconciliation_status' => ExpressEvent::RECON_FULLY_ALLOCATED,
            'hpp_status' => ExpressEvent::HPP_CALCULATED,
        ]);
        $r1 = ExpressEventRecap::create([
            'express_event_id' => $legacySplit1->id,
            'session_report_id' => $hlwRecap1->id,
            'status' => ExpressEventRecap::STATUS_ACTIVE,
        ]);
        ExpressEventRecapAllocation::create([
            'express_event_recap_id' => $r1->id,
            'allocated_sessions' => 23,
            'allocated_sheets' => 20,
            'allocated_revenue' => 5000000,
        ]);
        ExpressEventInvoice::create([
            'express_event_id' => $legacySplit1->id,
            'invoice_id' => $hlwInvoice->id,
        ]);

        $legacySplit2 = ExpressEvent::create([
            'event_name' => 'Halloween Blockparty 2026',
            'operational_type' => ExpressEvent::TYPE_SELF_RUN,
            'start_date' => '2026-10-31',
            'end_date' => '2026-10-31',
            'reconciliation_status' => ExpressEvent::RECON_FULLY_ALLOCATED,
            'hpp_status' => ExpressEvent::HPP_PENDING,
        ]);
        $r2 = ExpressEventRecap::create([
            'express_event_id' => $legacySplit2->id,
            'session_report_id' => $hlwRecap2->id,
            'status' => ExpressEventRecap::STATUS_ACTIVE,
        ]);
        ExpressEventRecapAllocation::create([
            'express_event_recap_id' => $r2->id,
            'allocated_sessions' => 77,
            'allocated_sheets' => 68,
            'allocated_revenue' => 0,
        ]);

        // 2. Data Historis AMBIGU (nama sama, tanggal berdekatan 8 hari, TANPA event_id & TANPA invoice_id)
        $ambRecap1 = $this->makeRecap([
            'report_number' => 'SR-AMB-1',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_id' => null,
            'invoice_id' => null,
            'event_note' => 'Bazaar Kampus 2026',
            'event_date' => '2026-10-10',
            'report_date' => '2026-10-10',
            'sessions' => 30,
            'sheets' => 40,
        ]);
        $ambRecap2 = $this->makeRecap([
            'report_number' => 'SR-AMB-2',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_id' => null,
            'invoice_id' => null,
            'event_note' => 'Bazaar Kampus 2026',
            'event_date' => '2026-10-18',
            'report_date' => '2026-10-18',
            'sessions' => 35,
            'sheets' => 45,
        ]);

        $ambEvent1 = ExpressEvent::create([
            'event_name' => 'Bazaar Kampus 2026',
            'operational_type' => ExpressEvent::TYPE_SELF_RUN,
            'start_date' => '2026-10-10',
            'end_date' => '2026-10-10',
            'reconciliation_status' => ExpressEvent::RECON_FULLY_ALLOCATED,
            'hpp_status' => ExpressEvent::HPP_PENDING,
        ]);
        $ar1 = ExpressEventRecap::create([
            'express_event_id' => $ambEvent1->id,
            'session_report_id' => $ambRecap1->id,
            'status' => ExpressEventRecap::STATUS_ACTIVE,
        ]);
        ExpressEventRecapAllocation::create([
            'express_event_recap_id' => $ar1->id,
            'allocated_sessions' => 30,
            'allocated_sheets' => 40,
            'allocated_revenue' => 0,
        ]);

        $ambEvent2 = ExpressEvent::create([
            'event_name' => 'Bazaar Kampus 2026',
            'operational_type' => ExpressEvent::TYPE_SELF_RUN,
            'start_date' => '2026-10-18',
            'end_date' => '2026-10-18',
            'reconciliation_status' => ExpressEvent::RECON_FULLY_ALLOCATED,
            'hpp_status' => ExpressEvent::HPP_PENDING,
        ]);
        $ar2 = ExpressEventRecap::create([
            'express_event_id' => $ambEvent2->id,
            'session_report_id' => $ambRecap2->id,
            'status' => ExpressEventRecap::STATUS_ACTIVE,
        ]);
        ExpressEventRecapAllocation::create([
            'express_event_recap_id' => $ar2->id,
            'allocated_sessions' => 35,
            'allocated_sheets' => 45,
            'allocated_revenue' => 0,
        ]);

        $txCountBefore = FinancialTransaction::count();

        // Jalankan command konsolidasi historis
        $this->artisan('hpp:consolidate-express-events')
            ->assertExitCode(0);

        // A. Halloween Blockparty 2026 terbukti 1 event -> dikonsolidasikan menjadi 1 baris utuh!
        $hlwEvents = ExpressEvent::where('event_name', 'Halloween Blockparty 2026')->get();
        $this->assertCount(1, $hlwEvents);

        $consolidatedHlw = $hlwEvents->first();
        $this->assertSame('29/10/2026 - 31/10/2026', $consolidatedHlw->period_range_label);
        $this->assertSame(100, $consolidatedHlw->total_allocated_sessions);
        $this->assertSame(88, $consolidatedHlw->total_allocated_sheets);
        $this->assertSame(5000000, $consolidatedHlw->effective_revenue);
        $this->assertSame(440000, $consolidatedHlw->calculated_hpp);
        $this->assertFalse($consolidatedHlw->needs_admin_verification);

        // B. Data asli SessionReport & Invoice TIDAK ada yang dihapus, dan tidak membuat FinancialTransaction liar
        $this->assertDatabaseHas('session_reports', ['id' => $hlwRecap1->id]);
        $this->assertDatabaseHas('session_reports', ['id' => $hlwRecap2->id]);
        $this->assertDatabaseHas('invoices', ['id' => $hlwInvoice->id]);
        $this->assertSame($txCountBefore, FinancialTransaction::count());

        // C. Bazaar Kampus 2026 yang ambigu TIDAK digabung paksa, melainkan ditandai Perlu Verifikasi Admin
        $bazaarEvents = ExpressEvent::where('event_name', 'Bazaar Kampus 2026')->orderBy('start_date')->get();
        $this->assertCount(2, $bazaarEvents);
        $this->assertTrue($bazaarEvents[0]->needs_admin_verification);
        $this->assertTrue($bazaarEvents[1]->needs_admin_verification);
        $this->assertSame('Perlu Verifikasi Admin', $bazaarEvents[0]->completeness_status_label);

        // D. Admin memverifikasi pada halaman View Event -> status verifikasi selesai dan tercatat di audit log
        Livewire::test(ViewExpressEvent::class, ['record' => $bazaarEvents[0]->getRouteKey()])
            ->callAction('mark_verified')
            ->assertNotified();

        $verifiedBazaar = $bazaarEvents[0]->fresh();
        $this->assertFalse($verifiedBazaar->needs_admin_verification);
        $this->assertDatabaseHas('express_event_audit_logs', [
            'express_event_id' => $verifiedBazaar->id,
            'action' => 'ADMIN_VERIFIED_IDENTITY',
        ]);
    }

    // =========================================================================
    // SKENARIO 10 — Kasus Real di Lapangan: Baris 1 Sudah Terhubung Invoice,
    // Baris 2 Historis Manual Tanpa ID -> Otomatis Tergabung & Tombol Gabungkan Event
    // =========================================================================

    public function test_split_multiday_events_where_row_1_has_invoice_and_row_2_has_manual_detail_auto_consolidate(): void
    {
        $this->actingAs($this->superAdmin);

        $invoice = $this->makeInvoice([
            'invoice_number' => 'INV-HLW-REAL-01',
            'event_name' => 'Halloween Blockparty 2026',
            'event_date' => '2026-10-29',
            'event_end_date' => '2026-10-31',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'total' => 5000000,
        ]);

        // Kedua rekap historis diisi lewat Riwayat Rekap Sesi (event_id = null, invoice_id = null)
        $recap1 = $this->makeRecap([
            'report_number' => 'SR-HLW-MANUAL-1',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_id' => null,
            'invoice_id' => null,
            'event_note' => 'Halloween Blockparty 2026',
            'event_date' => '2026-10-29',
            'report_date' => '2026-10-29',
            'sessions' => 23,
            'sheets' => 20,
        ]);

        $recap2 = $this->makeRecap([
            'report_number' => 'SR-HLW-MANUAL-2',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_id' => null,
            'invoice_id' => null,
            'event_note' => 'Halloween Blockparty 2026',
            'event_date' => '2026-10-31',
            'report_date' => '2026-10-31',
            'sessions' => 77,
            'sheets' => 68,
        ]);

        // Kondisi di DB sebelum perbaikan: 2 baris ExpressEvent terpisah di mana Baris 1 sudah memakai invoice tersebut!
        $row1 = ExpressEvent::create([
            'event_name' => 'Halloween Blockparty 2026',
            'operational_type' => ExpressEvent::TYPE_SELF_RUN,
            'start_date' => '2026-10-29',
            'end_date' => '2026-10-29',
            'hpp_rate_per_sheet' => 5000,
            'reconciliation_status' => ExpressEvent::RECON_FULLY_ALLOCATED,
            'hpp_status' => ExpressEvent::HPP_CALCULATED,
        ]);
        $link1 = ExpressEventRecap::create([
            'express_event_id' => $row1->id,
            'session_report_id' => $recap1->id,
            'status' => ExpressEventRecap::STATUS_ACTIVE,
        ]);
        ExpressEventRecapAllocation::create([
            'express_event_recap_id' => $link1->id,
            'allocated_sessions' => 23,
            'allocated_sheets' => 20,
            'allocated_revenue' => 5000000,
        ]);
        ExpressEventInvoice::create([
            'express_event_id' => $row1->id,
            'invoice_id' => $invoice->id,
        ]);

        $row2 = ExpressEvent::create([
            'event_name' => 'Halloween Blockparty 2026',
            'operational_type' => ExpressEvent::TYPE_SELF_RUN,
            'start_date' => '2026-10-31',
            'end_date' => '2026-10-31',
            'reconciliation_status' => ExpressEvent::RECON_FULLY_ALLOCATED,
            'hpp_status' => ExpressEvent::HPP_PENDING,
        ]);
        $link2 = ExpressEventRecap::create([
            'express_event_id' => $row2->id,
            'session_report_id' => $recap2->id,
            'status' => ExpressEventRecap::STATUS_ACTIVE,
        ]);
        ExpressEventRecapAllocation::create([
            'express_event_recap_id' => $link2->id,
            'allocated_sessions' => 77,
            'allocated_sheets' => 68,
            'allocated_revenue' => 0,
        ]);

        // Saat Admin membuka halaman Manajemen HPP, sinkronisasi otomatis langsung menyatukan Baris 2 ke Baris 1!
        Livewire::test(ListExpressEvents::class)
            ->call('setTab', 'express');

        $events = ExpressEvent::where('event_name', 'Halloween Blockparty 2026')->get();
        $this->assertCount(1, $events);

        $unified = $events->first();
        $this->assertSame('29/10/2026 - 31/10/2026', $unified->period_range_label);
        $this->assertSame(100, $unified->total_allocated_sessions);
        $this->assertSame(88, $unified->total_allocated_sheets);
        $this->assertSame(5000000, $unified->effective_revenue);
        $this->assertSame(440000, $unified->calculated_hpp);
        $this->assertSame(4560000, $unified->gross_profit);
    }

    public function test_admin_can_merge_two_split_events_via_invoice_modal_and_via_gabungkan_event_action(): void
    {
        $this->actingAs($this->superAdmin);

        $invoice = $this->makeInvoice([
            'invoice_number' => 'INV-MERGE-UI-01',
            'event_name' => 'Pameran Otomotif Nasional',
            'event_date' => '2026-10-01',
            'event_end_date' => '2026-10-01',
            'cabang_id' => $this->cabangSewa->cabang_id,
            'total' => 8000000,
        ]);

        // Buat 2 baris event dengan penulisan nama berbeda sehingga tidak digabung otomatis,
        // namun Admin mengetahui keduanya adalah 1 event operasional yang sama.
        $recapA = $this->makeRecap([
            'report_number' => 'SR-PON-DAY1',
            'cabang_id' => $this->cabangSewa->cabang_id,
            'event_note' => 'Pameran Otomotif Nasional (Hari 1)',
            'event_date' => '2026-10-01',
            'report_date' => '2026-10-01',
            'sessions' => 40,
            'sheets' => 50,
        ]);

        $recapB = $this->makeRecap([
            'report_number' => 'SR-PON-DAY5',
            'cabang_id' => $this->cabangSewa->cabang_id,
            'event_note' => 'Pameran Otomotif Nasional (Penutupan)',
            'event_date' => '2026-10-05',
            'report_date' => '2026-10-05',
            'sessions' => 60,
            'sheets' => 70,
        ]);

        $recapC = $this->makeRecap([
            'report_number' => 'SR-PON-BONUS',
            'cabang_id' => $this->cabangSewa->cabang_id,
            'event_note' => 'Pameran Otomotif Nasional (Afterparty)',
            'event_date' => '2026-10-06',
            'report_date' => '2026-10-06',
            'sessions' => 20,
            'sheets' => 30,
        ]);

        app(ExpressEventSyncService::class)->syncAllApprovedRecaps($this->superAdmin);

        $eventDay1 = ExpressEvent::where('event_name', 'Pameran Otomotif Nasional (Hari 1)')->firstOrFail();
        $eventDay5 = ExpressEvent::where('event_name', 'Pameran Otomotif Nasional (Penutupan)')->firstOrFail();
        $eventDay6 = ExpressEvent::where('event_name', 'Pameran Otomotif Nasional (Afterparty)')->firstOrFail();

        app(ExpressEventInvoiceService::class)->attachInvoices($eventDay1, [$invoice->id], $this->superAdmin);
        app(ExpressEventHppService::class)->updateRate($eventDay1, 5000, $this->superAdmin);

        // 1. Dari modal Hubungkan Invoice pada Event Hari 5, Admin klik "Gabungkan ke Event Ini"
        Livewire::test(\App\Livewire\ExpressEventInvoiceManager::class, ['eventId' => $eventDay5->id])
            ->call('openModal')
            ->assertSee('Gabungkan ke Event Ini')
            ->call('mergeIntoInvoiceOwnerEvent', $eventDay1->id)
            ->assertRedirect();

        $this->assertSoftDeleted('express_events', ['id' => $eventDay5->id]);
        $eventDay1 = $eventDay1->fresh();
        $this->assertSame('01/10/2026 - 05/10/2026', $eventDay1->period_range_label);
        $this->assertSame(100, $eventDay1->total_allocated_sessions);
        $this->assertSame(120, $eventDay1->total_allocated_sheets);
        $this->assertSame(8000000, $eventDay1->effective_revenue); // Invoice tetap dihitung 1x

        // 2. Dari halaman View Event Hari 1, Admin juga bisa memakai tombol header "Gabungkan Event" untuk menarik Event Hari 6
        Livewire::test(ViewExpressEvent::class, ['record' => $eventDay1->getRouteKey()])
            ->callAction('merge_events', [
                'source_event_ids' => [$eventDay6->id],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSoftDeleted('express_events', ['id' => $eventDay6->id]);
        $eventDay1 = $eventDay1->fresh();
        $this->assertSame('01/10/2026 - 06/10/2026', $eventDay1->period_range_label);
        $this->assertSame(120, $eventDay1->total_allocated_sessions);
        $this->assertSame(150, $eventDay1->total_allocated_sheets);
        $this->assertSame(8000000, $eventDay1->effective_revenue);
        $this->assertSame(750000, $eventDay1->calculated_hpp); // 150 × 5.000

        // Pastikan tidak ada data SessionReport atau Invoice asli yang terhapus
        $this->assertDatabaseHas('session_reports', ['id' => $recapA->id]);
        $this->assertDatabaseHas('session_reports', ['id' => $recapB->id]);
        $this->assertDatabaseHas('session_reports', ['id' => $recapC->id]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }
}
