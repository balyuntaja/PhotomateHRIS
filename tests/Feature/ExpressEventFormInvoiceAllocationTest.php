<?php

namespace Tests\Feature;

use App\Filament\Resources\ExpressEventResource\Pages\CreateExpressEvent;
use App\Filament\Resources\ExpressEventResource\Pages\EditExpressEvent;
use App\Models\Cabang;
use App\Models\ExpressEvent;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Role;
use App\Models\SessionReport;
use App\Services\ExpressEventHppService;
use App\Utils\RupiahHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpressEventFormInvoiceAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected Perusahaan $perusahaan;

    protected Cabang $cabangSelfRun;

    protected Cabang $cabangSewa;

    protected Cabang $cabangSharingProfit;

    protected Cabang $cabangGolio;

    protected Cabang $cabangJanus;

    protected Karyawan $admin;

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

        $this->cabangSelfRun = $this->makeBranch('C0010', 'Express Self Run');
        $this->cabangSewa = $this->makeBranch('C0011', 'Express Sewa');
        $this->cabangSharingProfit = $this->makeBranch('C0012', 'Express Sharing Profit');
        $this->cabangGolio = $this->makeBranch('C0013', 'Golio');
        $this->cabangJanus = $this->makeBranch('C0014', 'Newspaper Janus');

        $roleAdmin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web'], ['role_id' => 'R01']);
        $roleCrew = Role::firstOrCreate(['name' => 'Karyawan', 'guard_name' => 'web'], ['role_id' => 'R07']);

        $this->admin = Karyawan::create([
            'karyawan_id' => 'AD001',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'role_id' => $roleAdmin->role_id,
            'nik' => '9200000000000001',
            'nama_lengkap' => 'Admin HPP Express',
            'email' => 'admin.hpp@photomate.id',
            'tanggal_lahir' => '1992-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
            'password' => bcrypt('password'),
        ]);
        $this->admin->assignRole($roleAdmin);

        $this->crew = Karyawan::create([
            'karyawan_id' => 'CR001',
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'role_id' => $roleCrew->role_id,
            'nik' => '9200000000000002',
            'nama_lengkap' => 'Crew Event',
            'email' => 'crew.hpp@photomate.id',
            'tanggal_lahir' => '1998-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
            'password' => bcrypt('password'),
        ]);
        $this->crew->assignRole($roleCrew);

        $this->actingAs($this->admin);
    }

    public function test_create_event_self_run_links_multiple_invoices_via_modal_uses_contract_value_and_updates_realtime_summary(): void
    {
        $recap = $this->makeApprovedRecap(
            branch: $this->cabangSelfRun,
            reportNumber: 'SR-CR-SELFRUN-01',
            reportDate: '2026-11-20',
            eventName: 'Expo Kampus Brawijaya',
            sessions: 40,
            sheets: 100,
            grandTotal: 0,
        );

        // Invoice 1: DP Rp 1.500.000 dari nilai kontrak Rp 4.000.000 -> wajib dihitung Rp 4.000.000!
        $invoiceMain = $this->makeInvoice([
            'invoice_number' => 'INV-EXP-2026-101',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'client_name' => 'BEM Universitas Brawijaya',
            'event_name' => 'Expo Kampus Brawijaya',
            'invoice_date' => '2026-11-15',
            'total' => 4000000,
            'down_payment' => 1500000,
            'status' => 'Sebagian Dibayar',
        ]);

        // Invoice 2: Tambahan hari / addon senilai Rp 2.500.000 (Belum Dibayar) -> wajib dihitung Rp 2.500.000!
        $invoiceAddon = $this->makeInvoice([
            'invoice_number' => 'INV-EXP-2026-102',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'client_name' => 'BEM Universitas Brawijaya',
            'event_name' => 'Addon Booth Expo Brawijaya',
            'invoice_date' => '2026-11-18',
            'total' => 2500000,
            'down_payment' => 0,
            'status' => 'Belum Dibayar',
        ]);

        // Invoice cabang Golio & Janus tidak boleh muncul di modal Express
        $golioInvoice = $this->makeInvoice([
            'invoice_number' => 'INV-GOLIO-999',
            'cabang_id' => $this->cabangGolio->cabang_id,
            'client_name' => 'Klien Golio Non Express',
            'event_name' => 'Studio Golio',
            'total' => 1000000,
        ]);

        // Invoice yang sudah dipakai Event HPP lain tidak boleh dapat dipilih
        $otherEvent = ExpressEvent::create([
            'event_name' => 'Event Lain Terpakai',
            'operational_type' => ExpressEvent::TYPE_SEWA,
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-10',
            'created_by' => $this->admin->karyawan_id,
        ]);
        $usedInvoice = $this->makeInvoice([
            'invoice_number' => 'INV-USED-888',
            'cabang_id' => $this->cabangSewa->cabang_id,
            'client_name' => 'PT Sudah Terpakai',
            'event_name' => 'Event Lain Terpakai',
            'total' => 5000000,
            'status' => 'Lunas',
        ]);
        $otherEvent->invoices()->attach($usedInvoice->id, ['linked_by' => $this->admin->karyawan_id]);

        $financeTxCountBefore = FinancialTransaction::query()->count();

        $component = Livewire::test(CreateExpressEvent::class)
            ->assertSee('Alokasi Rekap Sesi Express')
            ->assertSee('Alokasi Invoice Event')
            ->assertSee('Hubungkan Invoice')
            ->set('data.event_name', 'Expo Kampus Brawijaya')
            ->set('data.hpp_rate_per_sheet', 5000)
            // Sebelum invoice dihubungkan: Total Omzet Rp 0, Total HPP 100 * 5.000 = Rp 500.000, Laba Kotor -Rp 500.000
            ->assertSee('Belum Ada Invoice Terhubung')
            ->call('openInvoiceModal')
            ->assertSet('isInvoiceModalOpen', true)
            ->assertSee('INV-EXP-2026-101')
            ->assertSee('INV-EXP-2026-102')
            ->assertSee('INV-USED-888')
            ->assertSee('Sudah terhubung: Event Lain Terpakai')
            ->assertDontSee('INV-GOLIO-999')
            // Uji fitur pencarian di modal berdasarkan nomor invoice / klien
            ->set('invoiceSearch', 'Addon Booth')
            ->assertSee('INV-EXP-2026-102')
            ->assertDontSee('INV-EXP-2026-101')
            ->set('invoiceSearch', '')
            // Mencoba memilih invoice milik event lain ditolak
            ->call('toggleModalInvoice', $usedInvoice->id)
            ->assertSet('modalSelectedInvoiceIds', [])
            // Pilih 2 invoice sekaligus (4.000.000 + 2.500.000 = 6.500.000) + duplikasi klik tidak mendobelkan
            ->call('toggleModalInvoice', $invoiceMain->id)
            ->call('toggleModalInvoice', $invoiceAddon->id)
            ->call('confirmAttachModalInvoices')
            ->assertSet('isInvoiceModalOpen', false)
            ->assertSet('data.linked_invoice_ids', [$invoiceMain->id, $invoiceAddon->id])
            // Verifikasi tabel Alokasi Invoice Event menampilkan detail lengkap & ringkasan real-time terupdate:
            // Total Omzet = Rp 6.500.000, Total HPP = Rp 500.000, Laba Kotor = Rp 6.000.000
            ->assertSee('INV-EXP-2026-101')
            ->assertSee('INV-EXP-2026-102')
            ->assertSee('BEM Universitas Brawijaya')
            ->assertSee('Express Self Run')
            ->assertSee('Sebagian Dibayar')
            ->assertSee('Belum Dibayar')
            ->assertSee(RupiahHelper::format(6500000))
            ->assertSee(RupiahHelper::format(500000))
            ->assertSee(RupiahHelper::format(6000000))
            ->call('create')
            ->assertHasNoFormErrors();

        $createdEvent = ExpressEvent::where('event_name', 'Expo Kampus Brawijaya')->firstOrFail();
        $this->assertCount(2, $createdEvent->invoices);
        $this->assertSame(6500000, $createdEvent->total_invoice_amount);
        $this->assertSame(6500000, $createdEvent->effective_revenue);
        $this->assertSame(500000, $createdEvent->calculated_hpp);
        $this->assertSame(6000000, $createdEvent->gross_profit);
        $this->assertSame($recap->id, $createdEvent->recaps()->sole()->session_report_id);

        // Tidak ada duplikasi invoice atau perubahan transaksi keuangan
        $this->assertSame(4, Invoice::query()->count());
        $this->assertSame($financeTxCountBefore, FinancialTransaction::query()->count());
    }

    public function test_edit_event_sewa_detaching_and_attaching_invoices_updates_hpp_automatically_and_preserves_invoice_source(): void
    {
        $recapDay1 = $this->makeApprovedRecap(
            branch: $this->cabangSewa,
            reportNumber: 'SR-ED-SEWA-01',
            reportDate: '2026-11-22',
            eventName: 'Wedding Royal Orchid',
            sessions: 50,
            sheets: 80,
            grandTotal: 0,
        );
        $recapDay2 = $this->makeApprovedRecap(
            branch: $this->cabangSewa,
            reportNumber: 'SR-ED-SEWA-02',
            reportDate: '2026-11-23',
            eventName: 'Wedding Royal Orchid',
            sessions: 30,
            sheets: 40,
            grandTotal: 0,
        );

        $inv1 = $this->makeInvoice([
            'invoice_number' => 'INV-SEWA-2026-201',
            'cabang_id' => $this->cabangSewa->cabang_id,
            'client_name' => 'Keluarga Wijaya',
            'event_name' => 'Wedding Royal Orchid',
            'invoice_date' => '2026-11-10',
            'total' => 5000000,
            'down_payment' => 2000000,
            'status' => 'Sebagian Dibayar',
        ]);

        $inv2 = $this->makeInvoice([
            'invoice_number' => 'INV-SEWA-2026-202',
            'cabang_id' => $this->cabangSewa->cabang_id,
            'client_name' => 'Keluarga Wijaya',
            'event_name' => 'Extra Jam Wedding Royal Orchid',
            'invoice_date' => '2026-11-23',
            'total' => 1500000,
            'down_payment' => 1500000,
            'status' => 'Lunas',
        ]);

        // Buat event melalui CreateExpressEvent dengan kedua invoice terhubung (Total 120 lbr * Rp 5.000 = Rp 600.000 HPP)
        Livewire::test(CreateExpressEvent::class)
            ->set('data.event_name', 'Wedding Royal Orchid')
            ->set('data.linked_invoice_ids', [$inv1->id, $inv2->id])
            ->set('data.hpp_rate_per_sheet', 5000)
            ->call('create')
            ->assertHasNoFormErrors();

        $event = ExpressEvent::where('event_name', 'Wedding Royal Orchid')->firstOrFail();
        // Konfirmasi HPP awal (Omzet 6.500.000 - HPP 600.000 = Laba Kotor 5.900.000)
        $snapshot = app(ExpressEventHppService::class)->confirmHpp($event, $this->admin);
        $this->assertSame(6500000, (int) $snapshot->total_revenue);
        $this->assertSame(600000, (int) $snapshot->total_hpp);
        $this->assertSame(5900000, (int) $snapshot->gross_profit);

        // Buka halaman Edit Event: pastikan kedua invoice tampil di section Alokasi Invoice Event
        $editComponent = Livewire::test(EditExpressEvent::class, ['record' => $event->id])
            ->assertSuccessful()
            ->assertSet('data.linked_invoice_ids', [$inv1->id, $inv2->id])
            ->assertSee('INV-SEWA-2026-201')
            ->assertSee('INV-SEWA-2026-202')
            ->assertSee('Express Sewa')
            ->assertSee(RupiahHelper::format(6500000))
            // Lepas invoice ke-2 (Rp 1.500.000) langsung pada halaman Edit
            ->call('detachFormInvoice', $inv2->id)
            ->assertSet('data.linked_invoice_ids', [$inv1->id])
            // Ringkasan real-time otomatis berubah ke Rp 5.000.000 omzet & Rp 4.400.000 laba kotor!
            ->assertSee(RupiahHelper::format(5000000))
            ->assertSee(RupiahHelper::format(4400000));

        // Verifikasi perhitungan HPP di database ikut diperbarui otomatis & data asli inv2 tetap utuh!
        $event->refresh();
        $this->assertCount(1, $event->invoices);
        $this->assertSame(5000000, $event->effective_revenue);
        $this->assertSame(5000000, $event->total_allocated_revenue);
        $this->assertSame(4400000, $event->gross_profit);
        $this->assertSame(5000000, (int) $event->latestHpp->total_revenue);
        $this->assertSame(4400000, (int) $event->latestHpp->gross_profit);

        // Data invoice yang dilepas sama sekali tidak terhapus atau berubah status/nominalnya
        $inv2Fresh = $inv2->fresh();
        $this->assertNotNull($inv2Fresh);
        $this->assertSame(1500000, $inv2Fresh->contract_amount);
        $this->assertSame('Lunas', $inv2Fresh->status);

        // Simpan form Edit dengan tarif HPP baru (Rp 4.000/lbr -> Total HPP 120 * 4.000 = Rp 480.000, Laba Kotor Rp 4.520.000)
        $editComponent
            ->set('data.hpp_rate_per_sheet', 4000)
            ->assertSee(RupiahHelper::format(480000))
            ->assertSee(RupiahHelper::format(4520000))
            ->call('save')
            ->assertHasNoFormErrors();

        $event->refresh();
        $this->assertSame(480000, $event->calculated_hpp);
        $this->assertSame(4520000, $event->gross_profit);
    }

    public function test_sharing_profit_event_keeps_recap_revenue_and_blocks_invoice_linking_on_form(): void
    {
        $this->makeApprovedRecap(
            branch: $this->cabangSharingProfit,
            reportNumber: 'SR-SHP-01',
            reportDate: '2026-11-24',
            eventName: 'Bazar Kuliner Malang',
            sessions: 35,
            sheets: 70,
            grandTotal: 2100000,
        );

        $anyInvoice = $this->makeInvoice([
            'invoice_number' => 'INV-ANY-301',
            'cabang_id' => $this->cabangSelfRun->cabang_id,
            'client_name' => 'Klien Umum',
            'event_name' => 'Event Umum',
            'total' => 9000000,
        ]);

        Livewire::test(CreateExpressEvent::class)
            ->set('data.event_name', 'Bazar Kuliner Malang')
            ->set('data.hpp_rate_per_sheet', 5000)
            ->assertSee('Express Sharing Profit')
            ->assertSee('Omzet Bersumber dari Rekap Sesi')
            // Omzet = Rp 2.100.000 (rekap), HPP = 70 * 5.000 = Rp 350.000, Laba Kotor = Rp 1.750.000
            ->assertSee(RupiahHelper::format(2100000))
            ->assertSee(RupiahHelper::format(350000))
            ->assertSee(RupiahHelper::format(1750000))
            // Modal invoice tidak dibuka untuk Sharing Profit
            ->call('openInvoiceModal')
            ->assertSet('isInvoiceModalOpen', false)
            // Bahkan bila linked_invoice_ids disuntikkan paksa, backend mengabaikannya untuk Sharing Profit
            ->set('data.linked_invoice_ids', [$anyInvoice->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $event = ExpressEvent::where('event_name', 'Bazar Kuliner Malang')->firstOrFail();
        $this->assertTrue($event->isSharingProfit());
        $this->assertCount(0, $event->invoices);
        $this->assertSame(2100000, $event->effective_revenue);
        $this->assertSame(350000, $event->calculated_hpp);
        $this->assertSame(1750000, $event->gross_profit);
    }

    private function makeBranch(string $id, string $name): Cabang
    {
        return Cabang::create([
            'cabang_id' => $id,
            'perusahaan_id' => $this->perusahaan->perusahaan_id,
            'nama_cabang' => $name,
            'alamat' => $name . ' Outlet',
        ]);
    }

    private function makeApprovedRecap(
        Cabang $branch,
        string $reportNumber,
        string $reportDate,
        string $eventName,
        int $sessions,
        int $sheets,
        int $grandTotal,
    ): SessionReport {
        return SessionReport::create([
            'report_number' => $reportNumber,
            'report_date' => $reportDate,
            'event_date' => $reportDate,
            'event_note' => $eventName,
            'cabang_id' => $branch->cabang_id,
            'status' => SessionReport::STATUS_APPROVED,
            'wedding_total_sessions' => $sessions,
            'wedding_total_sheets' => $sheets,
            'total_transactions' => $sessions,
            'total_sessions' => $sheets,
            'total_qris_amount' => $grandTotal,
            'grand_total_amount' => $grandTotal,
            'submitted_by' => $this->crew->karyawan_id,
            'submitted_at' => now(),
            'approved_by' => $this->admin->karyawan_id,
            'approved_at' => now(),
        ]);
    }

    private function makeInvoice(array $attributes = []): Invoice
    {
        static $seq = 1;
        $total = $attributes['total'] ?? 3000000;

        return Invoice::create(array_merge([
            'invoice_number' => 'INV-TEST-2026-' . str_pad((string) ($seq++), 3, '0', STR_PAD_LEFT),
            'client_name' => 'Klien Test',
            'event_name' => 'Event Test',
            'event_location' => 'Malang',
            'person_in_charge' => 'PIC Test',
            'event_date' => '2026-11-20',
            'event_end_date' => '2026-11-20',
            'invoice_date' => '2026-11-15',
            'due_date' => '2026-11-30',
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'down_payment' => 0,
            'status' => 'Belum Dibayar',
        ], $attributes));
    }
}
