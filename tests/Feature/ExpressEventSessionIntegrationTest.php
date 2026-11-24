<?php

namespace Tests\Feature;

use App\Filament\Resources\ExpressEventResource;
use App\Filament\Resources\ExpressEventResource\Pages\CreateExpressEvent;
use App\Filament\Resources\ExpressEventResource\Pages\EditExpressEvent;
use App\Filament\Resources\SessionReportResource\Pages\CreateSessionReport;
use App\Filament\Resources\SessionReportResource\Pages\EditSessionReport;
use App\Models\Cabang;
use App\Models\Event;
use App\Models\ExpressEvent;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Role;
use App\Models\SessionReport;
use App\Services\ExpressEventAllocationService;
use App\Utils\RupiahHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ExpressEventSessionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Perusahaan $perusahaan;
    protected Cabang $cabangSelfRun;
    protected Cabang $cabangSewa;
    protected Cabang $cabangSharingProfit;
    protected Karyawan $superAdmin;
    protected Karyawan $crew;

    protected function setUp(): void
    {
        parent::setUp();

        $this->perusahaan = Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        $this->cabangSelfRun = Cabang::create([
            'cabang_id' => 'C0010',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Express Self Run',
            'alamat' => 'Self Run Outlet',
        ]);

        $this->cabangSewa = Cabang::create([
            'cabang_id' => 'C0011',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Express Sewa',
            'alamat' => 'Sewa Outlet',
        ]);

        $this->cabangSharingProfit = Cabang::create([
            'cabang_id' => 'C0012',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => 'Express Sharing Profit',
            'alamat' => 'Sharing Profit Outlet',
        ]);

        $roleAdmin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web'], ['role_id' => 'R01']);
        $roleCrew = Role::firstOrCreate(['name' => 'Karyawan', 'guard_name' => 'web'], ['role_id' => 'R07']);

        $this->superAdmin = Karyawan::create([
            'karyawan_id' => 'K0001',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'role_id' => $roleAdmin->role_id ?? 'R01',
            'nik' => '1234567890123450',
            'nama_lengkap' => 'Admin Photomate',
            'email' => 'admin@photomate.id',
            'nomor_telepon' => '081234567890',
            'status_karyawan' => 'Tetap',
            'tanggal_masuk' => '2026-01-01',
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jakarta',
            'password' => bcrypt('password'),
        ]);

        $this->crew = Karyawan::create([
            'karyawan_id' => 'K0002',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'role_id' => $roleCrew->role_id ?? 'R07',
            'nik' => '1234567890123451',
            'nama_lengkap' => 'Crew Lapangan',
            'email' => 'crew@photomate.id',
            'nomor_telepon' => '081234567891',
            'status_karyawan' => 'Tetap',
            'tanggal_masuk' => '2026-01-01',
            'tanggal_lahir' => '2000-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
            'password' => bcrypt('password'),
        ]);
    }

    protected function makeInvoice(array $attributes = []): Invoice
    {
        static $seq = 1;
        $total = $attributes['total'] ?? 2500000;
        return Invoice::create(array_merge([
            'invoice_number' => 'INV-PMT-2026-' . str_pad((string) ($seq++), 3, '0', STR_PAD_LEFT),
            'client_name' => 'Klien Event',
            'event_name' => 'Event Default',
            'event_location' => 'Malang',
            'person_in_charge' => 'PIC',
            'event_date' => now()->toDateString(),
            'event_end_date' => now()->toDateString(),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'down_payment' => 0,
            'status' => 'Belum Dibayar',
        ], $attributes));
    }

    public function test_express_self_run_dropdown_options_format_and_filtering(): void
    {
        $this->actingAs($this->superAdmin);

        $selfRunInvoice = $this->makeInvoice([
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_name' => 'Self Run Gathering Telkom',
            'event_date' => '2026-11-20',
            'total' => 3500000,
        ]);

        $sewaInvoice = $this->makeInvoice([
            'cabang_id' => $this->cabangSewa->cabang_id,
            'event_name' => 'Sewa Wedding Budi & Ani',
            'event_date' => '2026-11-22',
            'total' => 5000000,
        ]);

        $component = Livewire::test(CreateSessionReport::class)
            ->set('data.cabang_id', $this->cabangSelfRun->cabang_id);

        $component->assertFormFieldExists('invoice_id')
            ->assertFormFieldIsVisible('invoice_id')
            ->assertFormFieldIsVisible('event_date');

        $field = collect($component->instance()->form->getFlatFields())
            ->first(fn ($f, $k) => str_ends_with($k, 'invoice_id'));

        $this->assertNotNull($field);
        $options = $field->getOptions();

        $this->assertArrayHasKey($selfRunInvoice->id, $options);
        $this->assertArrayNotHasKey($sewaInvoice->id, $options);

        // Format: Nama Event (Tanggal) tanpa nominal harga
        $this->assertSame('Self Run Gathering Telkom (20/11/2026)', $options[$selfRunInvoice->id]);
        $this->assertStringNotContainsString(RupiahHelper::format(3500000), $options[$selfRunInvoice->id]);
    }

    public function test_express_sewa_dropdown_options_format_and_filtering(): void
    {
        $this->actingAs($this->superAdmin);

        $selfRunInvoice = $this->makeInvoice([
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_name' => 'Self Run Expo',
            'total' => 2000000,
        ]);

        $sewaInvoice = $this->makeInvoice([
            'cabang_id' => $this->cabangSewa->cabang_id,
            'event_name' => 'Sewa Photobooth Wedding Mega',
            'event_date' => '2026-11-25',
            'total' => 4500000,
        ]);

        $component = Livewire::test(CreateSessionReport::class)
            ->set('data.cabang_id', $this->cabangSewa->cabang_id);

        $component->assertFormFieldExists('invoice_id')
            ->assertFormFieldIsVisible('invoice_id')
            ->assertFormFieldIsVisible('event_date');

        $field = collect($component->instance()->form->getFlatFields())
            ->first(fn ($f, $k) => str_ends_with($k, 'invoice_id'));

        $this->assertNotNull($field);
        $options = $field->getOptions();

        $this->assertArrayHasKey($sewaInvoice->id, $options);
        $this->assertArrayNotHasKey($selfRunInvoice->id, $options);

        // Format: Nama Event (Tanggal) tanpa nominal harga
        $this->assertSame('Sewa Photobooth Wedding Mega (25/11/2026)', $options[$sewaInvoice->id]);
        $this->assertStringNotContainsString(RupiahHelper::format(4500000), $options[$sewaInvoice->id]);
    }

    public function test_express_sharing_profit_uses_manual_text_input(): void
    {
        $this->actingAs($this->superAdmin);

        $component = Livewire::test(CreateSessionReport::class)
            ->set('data.cabang_id', $this->cabangSharingProfit->cabang_id);

        $component->assertFormFieldIsHidden('invoice_id')
            ->assertFormFieldIsVisible('event_note')
            ->assertFormFieldIsVisible('event_date');
    }

    public function test_create_session_report_express_self_run_saves_invoice_and_event_relation(): void
    {
        $this->actingAs($this->superAdmin);

        $invoice = $this->makeInvoice([
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_name' => 'Self Run Seminar IT',
            'total' => 3000000,
        ]);

        // Existing event with same name to test event_id linking
        $matchingEvent = Event::create([
            'name' => 'Self Run Seminar IT',
            'event_code' => 'EVT-SEMINAR',
            'location' => 'Malang',
            'date' => now()->toDateString(),
            'status' => 'OPEN',
        ]);

        Livewire::test(CreateSessionReport::class)
            ->fillForm([
                'report_date' => now()->toDateString(),
                'cabang_id' => $this->cabangSelfRun->cabang_id,
                'invoice_id' => $invoice->id,
                'wedding_total_sessions' => 50,
                'wedding_total_sheets' => 100,
                'crews' => [$this->crew->karyawan_id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $report = SessionReport::latest('id')->firstOrFail();
        $this->assertSame($this->cabangSelfRun->cabang_id, $report->cabang_id);
        $this->assertSame($invoice->id, $report->invoice_id);
        $this->assertSame('Self Run Seminar IT', $report->event_note);
        $this->assertSame($invoice->event_date?->toDateString(), $report->event_date?->toDateString());
        $this->assertSame($matchingEvent->id, $report->event_id);
    }

    public function test_validation_rejects_cross_branch_invoice_manipulation(): void
    {
        $this->actingAs($this->superAdmin);

        // Express Sewa invoice
        $sewaInvoice = $this->makeInvoice([
            'cabang_id' => $this->cabangSewa->cabang_id,
            'event_name' => 'Sewa Gathering Corporate',
            'total' => 4000000,
        ]);

        // Attempt to select Sewa invoice for Express Self Run branch
        Livewire::test(CreateSessionReport::class)
            ->fillForm([
                'report_date' => now()->toDateString(),
                'cabang_id' => $this->cabangSelfRun->cabang_id,
                'invoice_id' => $sewaInvoice->id,
                'wedding_total_sessions' => 40,
                'wedding_total_sheets' => 80,
                'crews' => [$this->crew->karyawan_id],
            ])
            ->call('create')
            ->assertHasFormErrors(['invoice_id']);
    }

    public function test_create_session_report_express_sharing_profit_saves_manual_event_name(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(CreateSessionReport::class)
            ->fillForm([
                'report_date' => now()->toDateString(),
                'cabang_id' => $this->cabangSharingProfit->cabang_id,
                'event_note' => 'Booth Bazar Festival Kampus',
                'event_date' => '2026-11-28',
                'wedding_total_sessions' => 30,
                'wedding_total_sheets' => 60,
                'crews' => [$this->crew->karyawan_id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $report = SessionReport::latest('id')->firstOrFail();
        $this->assertSame($this->cabangSharingProfit->cabang_id, $report->cabang_id);
        $this->assertNull($report->invoice_id);
        $this->assertSame('Booth Bazar Festival Kampus', $report->event_note);
        $this->assertSame('2026-11-28', $report->event_date?->toDateString());
    }

    public function test_express_sewa_auto_populates_allocated_revenue_from_invoice_total(): void
    {
        $this->actingAs($this->superAdmin);

        // Invoice with total Rp 6.000.000, but DP Rp 2.000.000 (partially paid)
        $invoice = $this->makeInvoice([
            'cabang_id' => $this->cabangSewa->cabang_id,
            'event_name' => 'Wedding Citra & Dimas',
            'total' => 6000000,
            'down_payment' => 2000000,
            'status' => 'Sebagian Dibayar',
        ]);

        $report = SessionReport::create([
            'report_number' => 'SR-20261124-001',
            'report_date' => now()->toDateString(),
            'cabang_id' => $this->cabangSewa->cabang_id,
            'invoice_id' => $invoice->id,
            'event_note' => $invoice->event_name,
            'status' => SessionReport::STATUS_APPROVED,
            'wedding_total_sessions' => 120,
            'wedding_total_sheets' => 240,
            'total_sessions' => 240,
            'total_transactions' => 120,
            'grand_total_amount' => 0,
        ]);

        // Check allocation service source totals
        $service = app(ExpressEventAllocationService::class);
        $sourceTotals = $service->getSourceTotals($report);

        // Omzet MUST be full invoice total (Rp 6.000.000), NOT DP (Rp 2.000.000) or grand_total_amount (0)
        $this->assertSame(6000000, $sourceTotals['revenue']);
        $this->assertSame(120, $sourceTotals['sessions']);
        $this->assertSame(240, $sourceTotals['sheets']);

        // Test in ExpressEventResource form
        Livewire::test(ExpressEventResource\Pages\CreateExpressEvent::class)
            ->fillForm([
                'event_name' => 'Event Wedding Citra & Dimas',
                'start_date' => now()->toDateString(),
                'end_date' => now()->toDateString(),
                'allocations_data' => [
                    [
                        'session_report_id' => $report->id,
                    ],
                ],
            ])
            ->assertFormSet([
                'allocations_data.0.allocated_sessions' => 120,
                'allocations_data.0.allocated_sheets' => 240,
                'allocations_data.0.allocated_revenue' => 6000000,
            ]);
    }

    public function test_multiple_rekap_sessions_sharing_same_invoice_does_not_double_count_omzet(): void
    {
        $this->actingAs($this->superAdmin);

        $invoice = $this->makeInvoice([
            'cabang_id' => $this->cabangSewa->cabang_id,
            'event_name' => 'Multi-Day Event Exhibition',
            'total' => 5000000,
            'status' => 'Lunas',
        ]);

        // Day 1 Rekap
        $reportDay1 = SessionReport::create([
            'report_number' => 'SR-20261124-010',
            'report_date' => now()->subDay()->toDateString(),
            'cabang_id' => $this->cabangSewa->cabang_id,
            'invoice_id' => $invoice->id,
            'status' => SessionReport::STATUS_APPROVED,
            'wedding_total_sessions' => 50,
            'wedding_total_sheets' => 100,
            'total_sessions' => 100,
            'total_transactions' => 50,
            'grand_total_amount' => 0,
        ]);

        // Day 2 Rekap
        $reportDay2 = SessionReport::create([
            'report_number' => 'SR-20261124-011',
            'report_date' => now()->toDateString(),
            'cabang_id' => $this->cabangSewa->cabang_id,
            'invoice_id' => $invoice->id,
            'status' => SessionReport::STATUS_APPROVED,
            'wedding_total_sessions' => 60,
            'wedding_total_sheets' => 120,
            'total_sessions' => 120,
            'total_transactions' => 60,
            'grand_total_amount' => 0,
        ]);

        $service = app(ExpressEventAllocationService::class);

        // Day 1 takes Rp 5.000.000
        $event = ExpressEvent::create([
            'event_name' => 'Multi-Day Event Exhibition',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->toDateString(),
        ]);

        $service->syncAllocations($event, [
            [
                'session_report_id' => $reportDay1->id,
                'allocated_sessions' => 50,
                'allocated_sheets' => 100,
                'allocated_revenue' => 5000000,
            ],
        ]);

        // Now Day 2 checks remaining revenue for the same invoice
        $remainingDay2 = $service->getRemainingTotals($reportDay2);

        // Remaining omzet for invoice should be 0 because full Rp 5.000.000 was allocated by Day 1!
        $this->assertSame(0, $remainingDay2['revenue']);
        $this->assertSame(60, $remainingDay2['sessions']);
        $this->assertSame(120, $remainingDay2['sheets']);
    }

    public function test_historical_session_report_without_invoice_loads_and_edits_safely(): void
    {
        $this->actingAs($this->superAdmin);

        // Old report created before invoice integration
        $oldReport = SessionReport::create([
            'report_number' => 'SR-20260901-001',
            'report_date' => now()->toDateString(),
            'cabang_id' => $this->cabangSewa->cabang_id,
            'invoice_id' => null,
            'event_note' => 'Event Historis Tanpa Invoice',
            'status' => SessionReport::STATUS_DRAFT,
            'wedding_total_sessions' => 30,
            'wedding_total_sheets' => 60,
            'total_sessions' => 60,
            'total_transactions' => 30,
            'grand_total_amount' => 0,
        ]);
        $oldReport->crews()->attach($this->crew->karyawan_id);

        // Can open edit form without error
        $test = Livewire::test(EditSessionReport::class, ['record' => $oldReport->id])
            ->assertSuccessful();

        // Connect it to an invoice
        $invoice = $this->makeInvoice([
            'cabang_id' => $this->cabangSewa->cabang_id,
            'event_name' => 'Invoice Untuk Rekap Historis',
            'total' => 2500000,
        ]);

        $test->fillForm([
            'invoice_id' => $invoice->id,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

        $oldReport->refresh();
        $this->assertSame($invoice->id, $oldReport->invoice_id);
    }

    public function test_express_event_name_dropdown_sources_from_approved_recaps_for_self_run_sewa_and_sharing_profit(): void
    {
        $this->actingAs($this->superAdmin);

        // 1. Self Run with invoice
        $invSelfRun = $this->makeInvoice([
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_name' => 'Wedding Sarah & John',
            'total' => 3000000,
        ]);
        $reportSelfRun = SessionReport::create([
            'report_number' => 'SR-EVENT-001',
            'report_date' => '2026-11-20',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'invoice_id' => $invSelfRun->id,
            'status' => SessionReport::STATUS_APPROVED,
            'wedding_total_sessions' => 40,
            'wedding_total_sheets' => 50,
            'total_sessions' => 50,
            'total_transactions' => 40,
            'grand_total_amount' => 0,
        ]);

        // 2. Sewa with invoice
        $invSewa = $this->makeInvoice([
            'cabang_id' => $this->cabangSewa->cabang_id,
            'event_name' => 'Corporate Gala PT ABC',
            'total' => 4500000,
        ]);
        $reportSewa = SessionReport::create([
            'report_number' => 'SR-EVENT-002',
            'report_date' => '2026-11-21',
            'cabang_id' => $this->cabangSewa->cabang_id,
            'invoice_id' => $invSewa->id,
            'status' => SessionReport::STATUS_APPROVED,
            'wedding_total_sessions' => 60,
            'wedding_total_sheets' => 70,
            'total_sessions' => 70,
            'total_transactions' => 60,
            'grand_total_amount' => 0,
        ]);

        // 3. Sharing Profit with manual event_note
        $reportSharing = SessionReport::create([
            'report_number' => 'SR-EVENT-003',
            'report_date' => '2026-11-22',
            'cabang_id' => $this->cabangSharingProfit->cabang_id,
            'invoice_id' => null,
            'event_note' => 'Birthday Party Maya',
            'status' => SessionReport::STATUS_APPROVED,
            'wedding_total_sessions' => 30,
            'wedding_total_sheets' => 35,
            'total_sessions' => 35,
            'total_transactions' => 30,
            'grand_total_amount' => 1500000,
        ]);

        // 4. Draft recap (must NOT appear)
        SessionReport::create([
            'report_number' => 'SR-EVENT-DRAFT',
            'report_date' => '2026-11-23',
            'cabang_id' => $this->cabangSharingProfit->cabang_id,
            'event_note' => 'Draft Gathering Unapproved',
            'status' => SessionReport::STATUS_DRAFT,
            'wedding_total_sessions' => 10,
            'wedding_total_sheets' => 10,
            'total_sessions' => 10,
            'total_transactions' => 10,
            'grand_total_amount' => 500000,
        ]);

        $service = app(ExpressEventAllocationService::class);
        $names = $service->getAvailableEventNames();

        $this->assertArrayHasKey('Wedding Sarah & John', $names);
        $this->assertArrayHasKey('Corporate Gala PT ABC', $names);
        $this->assertArrayHasKey('Birthday Party Maya', $names);
        $this->assertArrayNotHasKey('Draft Gathering Unapproved', $names);

        // Filter recaps for chosen event
        $eligibleSarah = $service->getEligibleRecapsForEventName('Wedding Sarah & John');
        $this->assertCount(1, $eligibleSarah);
        $this->assertSame($reportSelfRun->id, $eligibleSarah->first()['id']);
        $this->assertSame('Self Run', $eligibleSarah->first()['branch_type']);
        $this->assertStringContainsString('SR-EVENT-001', $eligibleSarah->first()['label']);
        $this->assertStringContainsString('Self Run', $eligibleSarah->first()['label']);
        $this->assertStringContainsString('40 sesi', $eligibleSarah->first()['label']);
        $this->assertStringContainsString('50 lbr', $eligibleSarah->first()['label']);

        // Check Sewa label
        $eligibleSewa = $service->getEligibleRecapsForEventName('Corporate Gala PT ABC');
        $this->assertCount(1, $eligibleSewa);
        $this->assertSame('Sewa', $eligibleSewa->first()['branch_type']);
        $this->assertStringContainsString('Sewa', $eligibleSewa->first()['label']);

        // Check Sharing Profit label
        $eligibleSharing = $service->getEligibleRecapsForEventName('Birthday Party Maya');
        $this->assertCount(1, $eligibleSharing);
        $this->assertSame('Sharing Profit', $eligibleSharing->first()['branch_type']);
        $this->assertStringContainsString('Sharing Profit', $eligibleSharing->first()['label']);
    }

    public function test_express_event_create_page_auto_populates_allocations_and_dates_when_event_name_selected(): void
    {
        $this->actingAs($this->superAdmin);

        $invoice = $this->makeInvoice([
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_name' => 'Annual Summit 2026',
            'total' => 5000000,
        ]);

        $report = SessionReport::create([
            'report_number' => 'SR-SUMMIT-01',
            'report_date' => '2026-11-25',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'invoice_id' => $invoice->id,
            'status' => SessionReport::STATUS_APPROVED,
            'wedding_total_sessions' => 50,
            'wedding_total_sheets' => 60,
            'total_sessions' => 60,
            'total_transactions' => 50,
            'grand_total_amount' => 5000000,
        ]);

        Livewire::test(CreateExpressEvent::class)
            ->set('data.event_name', 'Annual Summit 2026')
            ->assertSet('data.start_date', '2026-11-25')
            ->assertSet('data.end_date', '2026-11-25')
            ->assertSet('data.allocations_data.0.session_report_id', $report->id)
            ->assertSet('data.allocations_data.0.allocated_sessions', 50)
            ->assertSet('data.allocations_data.0.allocated_sheets', 60)
            ->assertSet('data.allocations_data.0.allocated_revenue', 5000000)
            ->set('data.hpp_rate_per_sheet', 5000)
            ->call('create')
            ->assertHasNoFormErrors();

        $event = ExpressEvent::where('event_name', 'Annual Summit 2026')->first();
        $this->assertNotNull($event);
        $this->assertSame(1, $event->recaps()->count());
        $this->assertSame(50, $event->total_allocated_sessions);
        $this->assertSame(60, $event->total_allocated_sheets);
        $this->assertSame(5000000, $event->total_allocated_revenue);
        // 60 sheets * 5000 = 300.000 HPP
        $this->assertSame(300000, $event->calculated_hpp);
    }

    public function test_express_event_rejects_recap_with_mismatched_event_name(): void
    {
        $this->actingAs($this->superAdmin);

        $invoice = $this->makeInvoice([
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_name' => 'Event Asli di Invoice',
            'total' => 2000000,
        ]);

        $report = SessionReport::create([
            'report_number' => 'SR-MISMATCH-01',
            'report_date' => '2026-11-25',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'invoice_id' => $invoice->id,
            'status' => SessionReport::STATUS_APPROVED,
            'wedding_total_sessions' => 20,
            'wedding_total_sheets' => 25,
            'total_sessions' => 25,
            'total_transactions' => 20,
            'grand_total_amount' => 2000000,
        ]);

        $event = ExpressEvent::create([
            'event_name' => 'Event Yang Berbeda di HPP',
            'start_date' => '2026-11-25',
            'end_date' => '2026-11-25',
            'created_by' => $this->superAdmin->karyawan_id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("tidak sesuai dengan Nama Event HPP");

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            [
                'session_report_id' => $report->id,
                'allocated_sessions' => 20,
                'allocated_sheets' => 25,
                'allocated_revenue' => 2000000,
            ],
        ]);
    }

    public function test_express_event_edit_page_preserves_existing_allocations_and_allows_editing(): void
    {
        $this->actingAs($this->superAdmin);

        $invoice = $this->makeInvoice([
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_name' => 'Festival Musik 2026',
            'total' => 4000000,
        ]);

        $report = SessionReport::create([
            'report_number' => 'SR-FEST-01',
            'report_date' => '2026-11-26',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'invoice_id' => $invoice->id,
            'status' => SessionReport::STATUS_APPROVED,
            'wedding_total_sessions' => 40,
            'wedding_total_sheets' => 45,
            'total_sessions' => 45,
            'total_transactions' => 40,
            'grand_total_amount' => 4000000,
        ]);

        $event = ExpressEvent::create([
            'event_name' => 'Festival Musik 2026',
            'start_date' => '2026-11-26',
            'end_date' => '2026-11-26',
            'created_by' => $this->superAdmin->karyawan_id,
            'hpp_rate_per_sheet' => 4000,
        ]);

        app(ExpressEventAllocationService::class)->syncAllocations($event, [
            [
                'session_report_id' => $report->id,
                'allocated_sessions' => 40,
                'allocated_sheets' => 45,
                'allocated_revenue' => 4000000,
            ],
        ]);

        // Edit page loads and preserves allocations
        $test = Livewire::test(EditExpressEvent::class, ['record' => $event->id])
            ->assertSuccessful()
            ->assertSet('data.event_name', 'Festival Musik 2026');

        $allocations = $test->get('data.allocations_data');
        $this->assertNotEmpty($allocations);
        $firstItem = reset($allocations);
        $this->assertEquals($report->id, $firstItem['session_report_id']);
        $this->assertEquals(45, $firstItem['allocated_sheets']);

        $test->set('data.notes', 'Catatan tambahan setelah diedit')
            ->call('save')
            ->assertHasNoFormErrors();

        $event->refresh();
        $this->assertSame('Catatan tambahan setelah diedit', $event->notes);
        $this->assertSame(45, $event->total_allocated_sheets);
    }

    public function test_already_created_express_event_name_does_not_appear_in_create_event_dropdown(): void
    {
        $this->actingAs($this->superAdmin);

        $invoice = $this->makeInvoice([
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'event_name' => 'Gala Dinner Akhir Tahun 2026',
            'total' => 6000000,
        ]);

        $report = SessionReport::create([
            'report_number' => 'SR-GALA-01',
            'report_date' => '2026-11-28',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'invoice_id' => $invoice->id,
            'status' => SessionReport::STATUS_APPROVED,
            'wedding_total_sessions' => 50,
            'wedding_total_sheets' => 50,
            'total_sessions' => 50,
            'total_transactions' => 50,
            'grand_total_amount' => 6000000,
        ]);

        $service = app(ExpressEventAllocationService::class);

        // Sebelum event dibuat, nama event harus muncul
        $namesBefore = $service->getAvailableEventNames();
        $this->assertArrayHasKey('Gala Dinner Akhir Tahun 2026', $namesBefore);

        // Buat Event HPP untuk event tersebut
        $event = ExpressEvent::create([
            'event_name' => 'Gala Dinner Akhir Tahun 2026',
            'start_date' => '2026-11-28',
            'end_date' => '2026-11-28',
            'created_by' => $this->superAdmin->karyawan_id,
        ]);

        // Setelah event dibuat, saat membuka form CREATE (currentEventId = null), nama event TIDAK BOLEH MUNCUL LAGI
        $namesAfter = $service->getAvailableEventNames();
        $this->assertArrayNotHasKey('Gala Dinner Akhir Tahun 2026', $namesAfter);

        // Saat membuka form EDIT (currentEventId = $event->id), nama event tetap ada untuk event tersebut
        $namesEdit = $service->getAvailableEventNames($event->id);
        $this->assertArrayHasKey('Gala Dinner Akhir Tahun 2026', $namesEdit);
    }
}
