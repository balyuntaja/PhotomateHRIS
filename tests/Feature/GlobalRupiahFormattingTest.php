<?php

namespace Tests\Feature;

use App\Filament\Resources\FinancialTransactionResource\Pages\ListFinancialTransactions;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\PricingSettingResource;
use App\Forms\Components\RupiahInput;
use App\Models\Cabang;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialParentCategory;
use App\Models\FinancialParty;
use App\Models\FinancialPaymentMethod;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Karyawan;
use App\Models\PricingSetting;
use App\Models\User;
use App\Utils\RupiahHelper;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use App\Models\Permission;
use App\Models\Role;
use Tests\TestCase;

class GlobalRupiahFormattingTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $user;

    protected function setUp(): void
    {
        parent::setUp();

        \App\Models\Perusahaan::firstOrCreate([
            'perusahaan_id' => 'P0001',
        ], [
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        $permissions = [
            'view_any_keuangan_transaction',
            'view_keuangan_transaction',
            'create_keuangan_transaction',
            'update_keuangan_transaction',
            'delete_keuangan_transaction',
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
            'email' => 'admin.rupiah@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jakarta',
        ]);
        $this->user->assignRole($adminRole);
    }

    public function test_rupiah_helper_formats_and_parses_consistently(): void
    {
        // Formatting with thousands separator dot
        $this->assertSame('1.000', RupiahHelper::number(1000));
        $this->assertSame('15.000', RupiahHelper::number(15000));
        $this->assertSame('100.000', RupiahHelper::number(100000));
        $this->assertSame('1.000.000', RupiahHelper::number(1000000));
        $this->assertSame('12.500.000', RupiahHelper::number(12500000));

        // Format with Rp prefix
        $this->assertSame('Rp 1.000.000', RupiahHelper::format(1000000));

        // Test cases from specification
        $this->assertSame(30000.0, RupiahHelper::toFloat('30.000'));
        $this->assertSame('Rp 30.000', RupiahHelper::format('30.000'));
        $this->assertSame('Rp 30.000', RupiahHelper::format(30000));
        $this->assertSame('Rp 30.000', RupiahHelper::format('30000.00'));

        $this->assertSame(150000.0, RupiahHelper::toFloat('150.000'));
        $this->assertSame('Rp 150.000', RupiahHelper::format('150.000'));
        $this->assertSame('Rp 150.000', RupiahHelper::format(150000));
        $this->assertSame('Rp 150.000', RupiahHelper::format('150000.00'));

        $this->assertSame(1000000.0, RupiahHelper::toFloat('1.000.000'));
        $this->assertSame('Rp 1.000.000', RupiahHelper::format('1.000.000'));
        $this->assertSame('Rp 1.000.000', RupiahHelper::format(1000000));
        $this->assertSame('Rp 1.000.000', RupiahHelper::format('1000000.00'));

        $this->assertSame(2199000.0, RupiahHelper::toFloat('2.199.000'));
        $this->assertSame('Rp 2.199.000', RupiahHelper::format('2.199.000'));
        $this->assertSame('Rp 2.199.000', RupiahHelper::format(2199000));
        $this->assertSame('Rp 2.199.000', RupiahHelper::format('2199000.00'));

        $this->assertSame(15000000.0, RupiahHelper::toFloat('15.000.000'));
        $this->assertSame('Rp 15.000.000', RupiahHelper::format('15.000.000'));
        $this->assertSame('Rp 15.000.000', RupiahHelper::format(15000000));
        $this->assertSame('Rp 15.000.000', RupiahHelper::format('15000000.00'));

        // Parsing from Indonesian dot-separated format into float/int
        $this->assertSame(1000.0, RupiahHelper::toFloat('1.000'));
        $this->assertSame(15000.0, RupiahHelper::toFloat('15.000'));
        $this->assertSame(100000.0, RupiahHelper::toFloat('100.000'));
        $this->assertSame(1000000.0, RupiahHelper::toFloat('1.000.000'));
        $this->assertSame(12500000.0, RupiahHelper::toFloat('12.500.000'));
        $this->assertSame(1000000, RupiahHelper::toInt('1.000.000'));

        // Edge cases
        $this->assertSame(0.0, RupiahHelper::toFloat(null));
        $this->assertSame(0.0, RupiahHelper::toFloat(''));
        $this->assertSame(0, RupiahHelper::toInt(''));
        $this->assertSame(50000.0, RupiahHelper::toFloat('Rp 50.000'));
        $this->assertSame(-50000.0, RupiahHelper::toFloat('-50.000'));
        $this->assertSame(-50000, RupiahHelper::toInt('-50.000'));
    }

    public function test_rupiah_macro_and_component_configuration(): void
    {
        $input = TextInput::make('nominal')->rupiah();
        $this->assertSame('Rp', $input->getPrefixLabel());

        $rupiahInput = RupiahInput::make('nominal_class');
        $this->assertSame('Rp', $rupiahInput->getPrefixLabel());
    }

    public function test_invoice_calculations_work_with_rupiah_formatted_inputs(): void
    {
        $this->actingAs($this->user);

        // Test updateTotals calculation with dot separated numbers
        $get = function (string $key) {
            return match ($key) {
                'invoiceItems' => [
                    ['price' => '2.699.000', 'device_count' => 1, 'amount' => '2.699.000'],
                    ['price' => '500.000', 'device_count' => 2, 'amount' => '1.000.000'],
                ],
                'discount' => '200.000',
                default => null,
            };
        };

        $result = [];
        $set = function (string $key, $value) use (&$result) {
            $result[$key] = $value;
        };

        InvoiceResource::updateTotals($get, $set);

        // Subtotal: 2.699.000 + (500.000 * 2) = 3.699.000
        $this->assertEquals(3699000, $result['subtotal']);
        // Total: 3.699.000 - 200.000 = 3.499.000
        $this->assertEquals(3499000, $result['total']);
    }

    public function test_pricing_setting_saves_numeric_value_from_rupiah_input(): void
    {
        $this->actingAs($this->user);

        $record = PricingSetting::create([
            'name' => 'Standar Booth Test',
            'base_price' => 30000,
            'additional_session_price' => 15000,
            'is_active' => true,
        ]);

        $this->assertSame(30000, (int) $record->base_price);
        $this->assertSame(15000, (int) $record->additional_session_price);
    }

    public function test_financial_transaction_modal_renders_rupiah_mask_and_saves_clean_integer(): void
    {
        $this->actingAs($this->user);

        $account = FinancialAccount::create([
            'name' => 'Express Test',
            'is_active' => true,
            'order' => 1,
        ]);

        $parent = FinancialParentCategory::create([
            'name' => 'Operasional Test',
            'type' => FinancialTransaction::TYPE_EXPENSE,
            'is_active' => true,
        ]);

        $category = FinancialCategory::create([
            'parent_category_id' => $parent->id,
            'name' => 'Listrik Test',
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'is_active' => true,
        ]);

        $paymentMethod = FinancialPaymentMethod::create([
            'name' => 'Transfer Bank Test',
            'is_active' => true,
        ]);

        $transaction = FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'account_id' => $account->id,
            'parent_category_id' => $parent->id,
            'category_id' => $category->id,
            'amount' => 1000000,
            'payment_method_id' => $paymentMethod->id,
            'transaction_date' => now()->toDateString(),
            'period' => now()->format('Y-m'),
            'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            'created_by' => 'K9999',
            'updated_by' => 'K9999',
        ]);

        Livewire::test(ListFinancialTransactions::class)
            ->mountTableAction('edit', $transaction)
            ->assertTableActionDataSet(['amount' => '1.000.000'])
            ->assertSeeHtml('x-mask:dynamic="$money($input, \',\', \'.\', 0)"')
            ->callTableAction('edit', $transaction, ['amount' => '2.500.000'])
            ->assertHasNoTableActionErrors();

        $transaction->refresh();
        $this->assertSame(2500000, $transaction->amount);
    }

    public function test_rupiah_macro_allow_negative_support(): void
    {
        $input = TextInput::make('penyesuaian')->rupiah(allowNegative: true);
        $this->assertSame('Rp', $input->getPrefixLabel());

        // RupiahHelper cleanly parses positive and negative currency strings
        $this->assertSame(-50000, RupiahHelper::toInt('-50.000'));
        $this->assertSame(75000, RupiahHelper::toInt('75.000'));
        $this->assertSame(0, RupiahHelper::toInt(''));
    }

    public function test_invoice_creation_saves_raw_numeric_values(): void
    {
        $this->actingAs($this->user);

        Cabang::create([
            'cabang_id' => 'C0005',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Photomate Express',
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-001',
            'client_name' => 'John Doe',
            'event_name' => 'Wedding Event',
            'person_in_charge' => 'Jane',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'event_date' => now()->toDateString(),
            'event_end_date' => now()->toDateString(),
            'event_location' => 'Ballroom A',
            'subtotal' => 2699000,
            'discount' => 199000,
            'total' => 2500000,
            'down_payment' => 1000000,
            'status' => 'Sebagian Dibayar',
            'dp_date' => now()->toDateString(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_description' => 'Sewa Photobooth Paket Platinum',
            'duration' => '3 Jam',
            'time_range' => '10:00 - 13:00',
            'amount' => 2699000,
        ]);

        $invoice->refresh();

        $this->assertEquals(2699000, $invoice->subtotal);
        $this->assertEquals(199000, $invoice->discount);
        $this->assertEquals(2500000, $invoice->total);
        $this->assertEquals(1000000, $invoice->down_payment);
        $this->assertIsNumeric($invoice->total);
    }

    public function test_pricing_service_and_session_report_with_rupiah_amount(): void
    {
        $pricingService = app(\App\Services\PricingService::class);

        // Standard 30.000 = 1 session, 45.000 = 2 sessions, etc.
        $this->assertSame(1, $pricingService->calculateSessionsFromPrice(RupiahHelper::toFloat('30.000')));
        $this->assertSame(2, $pricingService->calculateSessionsFromPrice(RupiahHelper::toFloat('45.000')));
        $this->assertSame(3, $pricingService->calculateSessionsFromPrice(RupiahHelper::toFloat('60.000')));
    }

    public function test_create_invoice_page_saves_correct_amounts(): void
    {
        $this->actingAs($this->user);

        $test = Livewire::test(CreateInvoice::class)
            ->assertSuccessful();

        $items = $test->get('data.invoiceItems');
        $uuid = array_key_first($items);

        $test->fillForm([
            'client_name' => 'Klien Baru Gold',
            'event_name' => 'Wedding Gold 2026',
            'event_location' => 'Malang',
            'person_in_charge' => 'PIC Gold',
            'event_date' => '2026-11-20',
            'event_end_date' => '2026-11-20',
            'invoice_date' => '2026-11-18',
            'due_date' => '2026-11-25',
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
            'status' => 'Belum Dibayar',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

        $invoice = Invoice::where('client_name', 'Klien Baru Gold')->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(2199000, $invoice->subtotal);
        $this->assertEquals(2199000, $invoice->total);
        $this->assertEquals(2199000, $invoice->invoiceItems()->first()->price);
        $this->assertEquals(2199000, $invoice->invoiceItems()->first()->amount);
    }

    public function test_invoice_edit_page_does_not_inflate_amounts(): void
    {
        $this->actingAs($this->user);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-GOLD-001',
            'client_name' => 'Rayz UMM Hotel',
            'event_name' => 'Wedding Gold',
            'person_in_charge' => 'Manager',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'event_date' => now()->toDateString(),
            'event_end_date' => now()->toDateString(),
            'event_location' => 'Ballroom Rayz',
            'subtotal' => 2199000,
            'discount' => 0,
            'total' => 2199000,
            'down_payment' => 0,
            'status' => 'Belum Dibayar',
        ]);

        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_description' => 'Sewa Photobooth Paket Gold',
            'duration' => '3 Jam',
            'time_range' => '10:00 - 13:00',
            'price' => 2199000,
            'device_count' => 1,
            'amount' => 2199000,
        ]);

        $component = Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->assertSuccessful();

        $formData = $component->get('data');
        // Total and subtotal in form data must be formatted as Rupiah string
        $this->assertEquals('2.199.000', $formData['subtotal']);
        $this->assertEquals('2.199.000', $formData['total']);

        $component->call('save')
            ->assertHasNoFormErrors();

        $invoice->refresh();

        // Nominal MUST stay 2.199.000, NOT 219.900.000
        $this->assertEquals(2199000, $invoice->subtotal);
        $this->assertEquals(2199000, $invoice->total);
        $this->assertEquals(2199000, $invoice->invoiceItems()->first()->price);
        $this->assertEquals(2199000, $invoice->invoiceItems()->first()->amount);
    }

    public function test_invoice_edit_with_down_payment_and_remaining_calculation(): void
    {
        $this->actingAs($this->user);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-DP-001',
            'client_name' => 'Klien DP',
            'event_name' => 'Acara DP',
            'person_in_charge' => 'PIC',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'event_date' => now()->toDateString(),
            'event_end_date' => now()->toDateString(),
            'event_location' => 'Malang',
            'subtotal' => 2199000,
            'discount' => 0,
            'total' => 2199000,
            'down_payment' => 1000000,
            'status' => 'Sebagian Dibayar',
            'dp_date' => now()->toDateString(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_description' => 'Sewa Photobooth Paket Gold',
            'duration' => '3 Jam',
            'time_range' => '10:00 - 13:00',
            'price' => 2199000,
            'device_count' => 1,
            'amount' => 2199000,
        ]);

        $component = Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->assertSuccessful();

        $formData = $component->get('data');
        $this->assertEquals('1.000.000', $formData['down_payment']);
        $this->assertEquals('2.199.000', $formData['total']);

        $component->call('save')
            ->assertHasNoFormErrors();

        $invoice->refresh();

        $this->assertEquals(2199000, $invoice->total);
        $this->assertEquals(1000000, $invoice->down_payment);
        $this->assertEquals(1199000, $invoice->total - $invoice->down_payment);
    }

    public function test_invoice_pdf_view_renders_correct_amounts(): void
    {
        $this->actingAs($this->user);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-PDF-001',
            'client_name' => 'Rayz UMM Hotel',
            'event_name' => 'Wedding Gold',
            'person_in_charge' => 'Manager',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'event_date' => now()->toDateString(),
            'event_end_date' => now()->toDateString(),
            'event_location' => 'Ballroom Rayz',
            'subtotal' => 2199000,
            'discount' => 0,
            'total' => 2199000,
            'down_payment' => 1099500,
            'status' => 'Sebagian Dibayar',
            'dp_date' => now()->toDateString(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_description' => 'Sewa Photobooth Paket Gold',
            'duration' => '3 Jam',
            'time_range' => '10:00 - 13:00',
            'price' => 2199000,
            'device_count' => 1,
            'amount' => 2199000,
        ]);

        $rendered = view('pdf.invoice', ['invoice' => $invoice->load('invoiceItems')])->render();

        // Must contain 2.199.000
        $this->assertStringContainsString('2.199.000', $rendered);
        $this->assertStringContainsString('Rp 2.199.000', $rendered);

        // Must NOT contain 219.900.000
        $this->assertStringNotContainsString('219.900.000', $rendered);
    }

    public function test_invoice_repeater_realtime_calculation(): void
    {
        $this->actingAs($this->user);

        $test = Livewire::test(CreateInvoice::class)
            ->assertSuccessful();

        $items = $test->get('data.invoiceItems');
        $uuid = array_key_first($items);

        $test->set("data.invoiceItems.{$uuid}.service_description", 'Sewa Photobooth Paket Gold');
        $this->assertEquals(2199000, $test->get("data.invoiceItems.{$uuid}.price"));
        $this->assertEquals(2199000, $test->get("data.invoiceItems.{$uuid}.amount"));
        $this->assertEquals(2199000, $test->get('data.subtotal'));
        $this->assertEquals(2199000, $test->get('data.total'));

        // Scenario 1: Price 3.000.000 with quantity 1 -> Total Rp 3.000.000
        $test->set("data.invoiceItems.{$uuid}.price", '3.000.000');
        $this->assertEquals('3.000.000', $test->get("data.invoiceItems.{$uuid}.price"));
        $this->assertEquals(3000000, $test->get("data.invoiceItems.{$uuid}.amount"));
        $this->assertEquals(3000000, $test->get('data.subtotal'));
        $this->assertEquals(3000000, $test->get('data.total'));

        // Scenario 2: Price 1.000.000 with quantity 1 -> Total Rp 1.000.000
        $test->set("data.invoiceItems.{$uuid}.price", '1.000.000');
        $this->assertEquals('1.000.000', $test->get("data.invoiceItems.{$uuid}.price"));
        $this->assertEquals(1000000, $test->get("data.invoiceItems.{$uuid}.amount"));
        $this->assertEquals(1000000, $test->get('data.subtotal'));
        $this->assertEquals(1000000, $test->get('data.total'));

        // Scenario 3: Price 2.199.000 with quantity 2 -> Total Rp 4.398.000
        $test->set("data.invoiceItems.{$uuid}.price", '2.199.000');
        $test->set("data.invoiceItems.{$uuid}.device_count", 2);
        $this->assertEquals(4398000, $test->get("data.invoiceItems.{$uuid}.amount"));
        $this->assertEquals(4398000, $test->get('data.subtotal'));
        $this->assertEquals(4398000, $test->get('data.total'));

        // Scenario 4 & 5: Save invoice and open again
        $test->fillForm([
            'client_name' => 'Klien Multi Item',
            'event_name' => 'Acara Multi Item',
            'event_location' => 'Malang',
            'person_in_charge' => 'PIC Multi',
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
                    'device_count' => 2,
                    'amount' => '4.398.000',
                ],
            ],
            'subtotal' => '4.398.000',
            'discount' => '0',
            'total' => '4.398.000',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

        $savedInvoice = Invoice::where('client_name', 'Klien Multi Item')->first();
        $this->assertNotNull($savedInvoice);
        $this->assertEquals(4398000, $savedInvoice->subtotal);
        $this->assertEquals(4398000, $savedInvoice->total);

        $savedItem = $savedInvoice->invoiceItems()->first();
        $this->assertEquals(2199000, $savedItem->price);
        $this->assertEquals(2, $savedItem->device_count);
        $this->assertEquals(4398000, $savedItem->amount);

        // Open again via EditInvoice
        $editComponent = Livewire::test(EditInvoice::class, ['record' => $savedInvoice->getKey()])
            ->assertSuccessful();

        $editData = $editComponent->get('data');
        $this->assertEquals('4.398.000', $editData['subtotal']);
        $this->assertEquals('4.398.000', $editData['total']);

        $editItems = $editData['invoiceItems'];
        $itemUuid = array_key_first($editItems);
        $this->assertEquals('2.199.000', $editItems[$itemUuid]['price']);
        $this->assertEquals(2, $editItems[$itemUuid]['device_count']);
        $this->assertEquals('4.398.000', $editItems[$itemUuid]['amount']);
    }
}
