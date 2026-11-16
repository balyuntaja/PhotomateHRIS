<?php

namespace Tests\Feature;

use App\Filament\Resources\FinancialTransactionResource\Pages\ListFinancialTransactions;
use App\Filament\Resources\InvoiceResource;
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
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $superAdminRole = Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web'],
            ['role_id' => 'R99']
        );
        $superAdminRole->syncPermissions($permissions);

        $this->user = Karyawan::create([
            'karyawan_id' => 'K9999',
            'role_id' => 'R99',
            'perusahaan_id' => 'P0001',
            'nik' => '999999999999',
            'nama_lengkap' => 'Admin Tester',
            'email' => 'admin.rupiah@photomate.id',
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jakarta',
        ]);
        $this->user->assignRole($superAdminRole);
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
            ->assertSeeHtml('x-mask:dynamic="$money($input, \'.\', \',\', 0)"')
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
}
