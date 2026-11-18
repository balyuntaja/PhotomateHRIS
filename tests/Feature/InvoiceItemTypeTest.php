<?php

namespace Tests\Feature;

use App\Filament\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Karyawan;
use App\Models\Permission;
use App\Models\Perusahaan;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceItemTypeTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $user;

    protected function setUp(): void
    {
        parent::setUp();

        Perusahaan::firstOrCreate([
            'perusahaan_id' => 'P0001',
        ], [
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        $permissions = [
            'view_any_invoice',
            'view_invoice',
            'create_invoice',
            'update_invoice',
            'delete_invoice',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $adminRole = Role::firstOrCreate(
            ['name' => 'Admin', 'guard_name' => 'web'],
            ['role_id' => 'R01']
        );
        $adminRole->syncPermissions($permissions);

        $this->user = Karyawan::create([
            'karyawan_id' => 'K9999',
            'role_id' => 'R01',
            'perusahaan_id' => 'P0001',
            'nik' => '999999999999',
            'nama_lengkap' => 'Admin Tester',
            'email' => 'admin.invoice@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Malang',
        ]);
        $this->user->assignRole($adminRole);
    }

    /**
     * Skenario A:
     * 1. Buat invoice baru.
     * 2. Tambahkan Invoice Item.
     * 3. Pilih Type Jasa / Layanan.
     * 4. Isi deskripsi, harga, dan jumlah.
     * 5. Simpan invoice.
     * 6. Buka kembali invoice melalui Edit.
     * 7. Type harus tetap menampilkan Jasa / Layanan.
     */
    public function test_skenario_a_create_service_item_persists_and_reloads_as_service(): void
    {
        $this->actingAs($this->user);

        $test = Livewire::test(CreateInvoice::class)
            ->assertSuccessful();

        $items = $test->get('data.invoiceItems');
        $uuid = array_key_first($items);

        $test->fillForm([
            'client_name' => 'Klien Skenario A',
            'event_name' => 'Wedding Event Service',
            'event_location' => 'Malang',
            'person_in_charge' => 'PIC Service',
            'event_date' => '2026-11-20',
            'event_end_date' => '2026-11-20',
            'invoice_date' => '2026-11-18',
            'due_date' => '2026-11-25',
            'status' => 'Belum Dibayar',
            'invoiceItems' => [
                $uuid => [
                    'item_type' => 'service',
                    'service_description' => 'Sewa Photobooth Paket Gold',
                    'is_tba' => false,
                    'start_time' => '10:00',
                    'end_time' => '13:00',
                    'duration' => '3 Jam',
                    'price' => '2.199.000',
                    'device_count' => 1,
                    'amount' => '2.199.000',
                ],
            ],
            'subtotal' => '2.199.000',
            'discount' => '0',
            'total' => '2.199.000',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

        $invoice = Invoice::where('client_name', 'Klien Skenario A')->first();
        $this->assertNotNull($invoice);

        $item = $invoice->invoiceItems()->first();
        $this->assertNotNull($item);
        $this->assertSame('service', $item->item_type);

        // Buka kembali melalui Edit
        $editTest = Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->assertSuccessful();

        $editData = $editTest->get('data');
        $editItems = $editData['invoiceItems'];
        $firstItem = reset($editItems);

        $this->assertSame('service', $firstItem['item_type']);
        $this->assertSame('3 Jam', $firstItem['duration']);
        $this->assertSame('10:00', $firstItem['start_time']);
        $this->assertSame('13:00', $firstItem['end_time']);
    }

    /**
     * Skenario B:
     * 1. Buat invoice dengan Type Barang / Item.
     * 2. Simpan invoice.
     * 3. Buka kembali melalui Edit.
     * 4. Type harus tetap menampilkan Barang / Item.
     */
    public function test_skenario_b_create_product_item_persists_and_reloads_as_product(): void
    {
        $this->actingAs($this->user);

        $test = Livewire::test(CreateInvoice::class)
            ->assertSuccessful();

        $items = $test->get('data.invoiceItems');
        $uuid = array_key_first($items);

        $test->fillForm([
            'client_name' => 'Klien Skenario B',
            'event_name' => 'Corporate Gathering Product',
            'event_location' => 'Surabaya',
            'person_in_charge' => 'PIC Product',
            'event_date' => '2026-11-20',
            'event_end_date' => '2026-11-20',
            'invoice_date' => '2026-11-18',
            'due_date' => '2026-11-25',
            'status' => 'Belum Dibayar',
            'invoiceItems' => [
                $uuid => [
                    'item_type' => 'product',
                    'service_description' => 'Backdrop Corporate Gathering',
                    'is_tba' => false,
                    'start_time' => null,
                    'end_time' => null,
                    'duration' => '-',
                    'price' => '1.500.000',
                    'device_count' => 1,
                    'amount' => '1.500.000',
                ],
            ],
            'subtotal' => '1.500.000',
            'discount' => '0',
            'total' => '1.500.000',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

        $invoice = Invoice::where('client_name', 'Klien Skenario B')->first();
        $this->assertNotNull($invoice);

        $item = $invoice->invoiceItems()->first();
        $this->assertNotNull($item);
        $this->assertSame('product', $item->item_type);

        // Buka kembali melalui Edit
        $editTest = Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->assertSuccessful();

        $editData = $editTest->get('data');
        $editItems = $editData['invoiceItems'];
        $firstItem = reset($editItems);

        $this->assertSame('product', $firstItem['item_type']);
    }

    /**
     * Skenario C:
     * 1. Edit invoice yang sebelumnya menggunakan Barang / Item.
     * 2. Ubah menjadi Jasa / Layanan.
     * 3. Simpan kembali.
     * 4. Buka ulang invoice.
     * 5. Pastikan Type tetap Jasa / Layanan.
     */
    public function test_skenario_c_update_product_to_service_persists(): void
    {
        $this->actingAs($this->user);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-C-001',
            'client_name' => 'Klien Skenario C',
            'event_name' => 'Event C',
            'person_in_charge' => 'PIC C',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'event_date' => now()->toDateString(),
            'event_end_date' => now()->toDateString(),
            'event_location' => 'Malang',
            'subtotal' => 1000000,
            'discount' => 0,
            'total' => 1000000,
            'status' => 'Belum Dibayar',
        ]);

        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => 'product',
            'service_description' => 'Roll Film Keychain 100 pcs',
            'duration' => '-',
            'time_range' => '-',
            'price' => 1000000,
            'device_count' => 1,
            'amount' => 1000000,
        ]);

        $editTest = Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->assertSuccessful();

        $items = $editTest->get('data.invoiceItems');
        $uuid = array_key_first($items);

        // Ubah dari product menjadi service
        $editTest->set("data.invoiceItems.{$uuid}.item_type", 'service');
        $editTest->set("data.invoiceItems.{$uuid}.service_description", 'Sewa Photobooth Paket Silver');
        $editTest->set("data.invoiceItems.{$uuid}.start_time", '14:00');
        $editTest->set("data.invoiceItems.{$uuid}.end_time", '16:00');
        $editTest->set("data.invoiceItems.{$uuid}.duration", '2 Jam');
        $editTest->set("data.invoiceItems.{$uuid}.price", '1.599.000');
        $editTest->set("data.invoiceItems.{$uuid}.amount", '1.599.000');
        $editTest->set('data.subtotal', '1.599.000');
        $editTest->set('data.total', '1.599.000');

        $editTest->call('save')
            ->assertHasNoFormErrors();

        $item->refresh();
        $this->assertSame('service', $item->item_type);
        $this->assertSame('2 Jam', $item->duration);
        $this->assertSame('14:00 - 16:00', $item->time_range);

        // Buka ulang invoice
        $reopenTest = Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->assertSuccessful();

        $reopenData = $reopenTest->get('data');
        $reopenItems = $reopenData['invoiceItems'];
        $firstItem = reset($reopenItems);

        $this->assertSame('service', $firstItem['item_type']);
        $this->assertSame('2 Jam', $firstItem['duration']);
        $this->assertSame('14:00', $firstItem['start_time']);
        $this->assertSame('16:00', $firstItem['end_time']);
    }

    /**
     * Multiple items with different types in the same invoice.
     */
    public function test_multiple_items_with_different_types_in_one_invoice(): void
    {
        $this->actingAs($this->user);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-MIX-001',
            'client_name' => 'Klien Mixed Items',
            'event_name' => 'Mixed Event',
            'person_in_charge' => 'PIC Mixed',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'event_date' => now()->toDateString(),
            'event_end_date' => now()->toDateString(),
            'event_location' => 'Malang',
            'subtotal' => 3199000,
            'discount' => 0,
            'total' => 3199000,
            'status' => 'Belum Dibayar',
        ]);

        $item1 = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => 'service',
            'service_description' => 'Sewa Photobooth Paket Gold',
            'duration' => '3 Jam',
            'time_range' => '10:00 - 13:00',
            'price' => 2199000,
            'device_count' => 1,
            'amount' => 2199000,
        ]);

        $item2 = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => 'product',
            'service_description' => 'Roll Film Keychain 100 pcs',
            'duration' => '-',
            'time_range' => '-',
            'price' => 1000000,
            'device_count' => 1,
            'amount' => 1000000,
        ]);

        $editTest = Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->assertSuccessful();

        $editData = $editTest->get('data');
        $editItems = $editData['invoiceItems'];

        $this->assertCount(2, $editItems);

        // Find each item in form state
        $itemTypes = array_column($editItems, 'item_type');
        $this->assertContains('service', $itemTypes);
        $this->assertContains('product', $itemTypes);
    }

    /**
     * Skenario TBA Service item:
     * Service with is_tba true persists and reloads correctly.
     */
    public function test_service_with_tba_persists_and_reloads_as_service(): void
    {
        $this->actingAs($this->user);

        $test = Livewire::test(CreateInvoice::class)
            ->assertSuccessful();

        $items = $test->get('data.invoiceItems');
        $uuid = array_key_first($items);

        $test->fillForm([
            'client_name' => 'Klien TBA',
            'event_name' => 'Event TBA',
            'event_location' => 'Malang',
            'person_in_charge' => 'PIC TBA',
            'event_date' => '2026-11-20',
            'event_end_date' => '2026-11-20',
            'invoice_date' => '2026-11-18',
            'due_date' => '2026-11-25',
            'status' => 'Belum Dibayar',
            'invoiceItems' => [
                $uuid => [
                    'item_type' => 'service',
                    'service_description' => 'Sewa Photobooth Paket Platinum',
                    'is_tba' => true,
                    'start_time' => null,
                    'end_time' => null,
                    'duration' => '4 Jam',
                    'price' => '2.699.000',
                    'device_count' => 1,
                    'amount' => '2.699.000',
                ],
            ],
            'subtotal' => '2.699.000',
            'discount' => '0',
            'total' => '2.699.000',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

        $invoice = Invoice::where('client_name', 'Klien TBA')->first();
        $this->assertNotNull($invoice);

        $item = $invoice->invoiceItems()->first();
        $this->assertSame('service', $item->item_type);
        $this->assertSame('TBA', $item->time_range);

        $editTest = Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->assertSuccessful();

        $editData = $editTest->get('data');
        $editItems = $editData['invoiceItems'];
        $firstItem = reset($editItems);

        $this->assertSame('service', $firstItem['item_type']);
        $this->assertTrue($firstItem['is_tba']);
        $this->assertSame('4 Jam', $firstItem['duration']);
    }

    /**
     * Legacy records without item_type column value fallback safely.
     */
    public function test_legacy_item_without_item_type_hydrates_correctly(): void
    {
        $this->actingAs($this->user);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-LEGACY-001',
            'client_name' => 'Klien Legacy',
            'event_name' => 'Event Legacy',
            'person_in_charge' => 'PIC Legacy',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'event_date' => now()->toDateString(),
            'event_end_date' => now()->toDateString(),
            'event_location' => 'Malang',
            'subtotal' => 3199000,
            'discount' => 0,
            'total' => 3199000,
            'status' => 'Belum Dibayar',
        ]);

        // Simulasikan legacy item dengan item_type null
        $itemService = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => null,
            'service_description' => 'Sewa Photobooth Paket Gold',
            'duration' => '3 Jam',
            'time_range' => '10:00 - 13:00',
            'price' => 2199000,
            'device_count' => 1,
            'amount' => 2199000,
        ]);

        $itemProduct = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => null,
            'service_description' => 'Backdrop Corporate Gathering',
            'duration' => '-',
            'time_range' => '-',
            'price' => 1000000,
            'device_count' => 1,
            'amount' => 1000000,
        ]);

        $editTest = Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->assertSuccessful();

        $editData = $editTest->get('data');
        $editItems = $editData['invoiceItems'];

        $itemTypes = array_column($editItems, 'item_type');
        $this->assertContains('service', $itemTypes);
        $this->assertContains('product', $itemTypes);
    }
}
