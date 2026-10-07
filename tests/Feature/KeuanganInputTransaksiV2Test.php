<?php

namespace Tests\Feature;

use App\Filament\Pages\KeuanganInputTransaksi;
use App\Filament\Pages\KeuanganInputTransaksiV2;
use App\Filament\Pages\KeuanganReport;
use App\Filament\Resources\FinancialTransactionResource\Pages\ListFinancialTransactions;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialParty;
use App\Models\FinancialPaymentMethod;
use App\Models\FinancialTransaction;
use App\Models\Karyawan;
use App\Models\Perusahaan;
use App\Models\Permission;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class KeuanganInputTransaksiV2Test extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $managerFinance;

    protected FinancialCategory $incomeCategory;

    protected FinancialCategory $expenseCategory;

    protected FinancialPaymentMethod $cash;

    protected FinancialAccount $express;

    protected FinancialAccount $golio;

    protected FinancialAccount $janusEkspansi;

    protected FinancialParty $pihak;

    protected function setUp(): void
    {
        parent::setUp();

        Perusahaan::create([
            'perusahaan_id' => 'P0001',
            'nama_perusahaan' => 'Photomate Indonesia',
            'email' => 'info@photomate.id',
            'nomor_telepon' => '08123456789',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
        ]);

        $this->incomeCategory = FinancialCategory::where('name', 'Pelunasan')
            ->where('transaction_type', FinancialCategory::TYPE_INCOME)
            ->firstOrFail();

        $this->expenseCategory = FinancialCategory::where('name', 'Tinta')
            ->where('transaction_type', FinancialCategory::TYPE_EXPENSE)
            ->firstOrFail();

        $this->cash = FinancialPaymentMethod::where('name', 'Cash')->firstOrFail();

        $this->express = FinancialAccount::where('name', 'Photoamte Express')->firstOrFail();
        $this->golio = FinancialAccount::where('name', 'Golio')->firstOrFail();
        $this->janusEkspansi = FinancialAccount::where('name', 'Janus')->firstOrFail();

        $this->pihak = FinancialParty::where('name', 'Balyun')->firstOrFail();

        $this->managerFinance = $this->makeUser('K0001', 'Manager Finance', 'R04', 'manager.finance@photomate.id', [
            'view_any_keuangan_transaction',
            'view_keuangan_transaction',
            'create_keuangan_transaction',
            'update_keuangan_transaction',
            'delete_keuangan_transaction',
            'view_keuangan_report',
            'view_keuangan_dashboard',
        ]);
    }

    public function test_akun_keuangan_v2_tersedia_express_golio_dan_janus_ekspansi(): void
    {
        $accounts = FinancialAccount::query()->active()->orderBy('sort_order')->pluck('name')->all();

        $this->assertSame(['Photomate Express', 'Golio', 'Janus'], $accounts);
    }

    public function test_menu_dan_halaman_input_transaksi_v2_dapat_diakses_dengan_permission_create(): void
    {
        $this->actingAs($this->managerFinance);

        $this->assertTrue(KeuanganInputTransaksiV2::canAccess());
        $this->assertTrue(KeuanganInputTransaksiV2::shouldRegisterNavigation());

        $this->get('/admin/keuangan-input-transaksi-v2')
            ->assertOk()
            ->assertSee('Input Transaksi V2')
            ->assertSee('Catat transaksi keuangan dengan cepat dan sederhana.');
    }

    public function test_halaman_v2_menolak_user_tanpa_permission_create_transaksi(): void
    {
        $staff = $this->makeUser('K0005', 'Staff HRD', 'R02', 'staff.hrd@photomate.id', ['view_any_karyawan']);

        $this->actingAs($staff);

        $this->assertFalse(KeuanganInputTransaksiV2::canAccess());
        $this->assertFalse(KeuanganInputTransaksiV2::shouldRegisterNavigation());

        $this->get('/admin/keuangan-input-transaksi-v2')->assertForbidden();
    }

    public function test_field_nominal_halaman_v2_memakai_mask_ribuan_indonesia(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(KeuanganInputTransaksiV2::class)
            ->assertSuccessful()
            ->assertSeeHtml('x-mask:dynamic="$money($input, \',\', \'.\', 0)"');
    }

    public function test_input_v2_menyimpan_transaksi_beserta_akun_periode_dan_pihak(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(KeuanganInputTransaksiV2::class)
            ->fillForm([
                'account_id' => $this->express->id,
                'period' => '2026-10',
                'transaction_date' => '2026-10-07',
                'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
                'category_id' => $this->expenseCategory->id,
                'amount' => '500.000',
                'party_id' => $this->pihak->id,
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                'description' => 'Pembelian tinta printer Janus',
            ])
            ->call('simpan')
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertNoRedirect();

        $transaction = FinancialTransaction::firstOrFail();

        $this->assertSame($this->express->id, $transaction->account_id);
        $this->assertSame($this->expenseCategory->id, $transaction->category_id);
        $this->assertSame($this->pihak->id, $transaction->party_id);
        $this->assertSame(500000, $transaction->amount);
        $this->assertSame('2026-10-07', $transaction->transaction_date->toDateString());
        $this->assertSame(2026, $transaction->period_year);
        $this->assertSame(10, $transaction->period_month);
        $this->assertSame('Oktober 2026', $transaction->period_label);
        $this->assertSame(FinancialTransaction::PAYMENT_STATUS_LUNAS, $transaction->payment_status);
        $this->assertFalse($transaction->is_utang);
        $this->assertNull($transaction->due_date);
        $this->assertNull($transaction->counterparty);
        $this->assertSame('Pembelian tinta printer Janus', $transaction->description);
        $this->assertNull($transaction->evidence_path);
        $this->assertSame($this->managerFinance->karyawan_id, $transaction->created_by);
    }

    public function test_input_v2_menyimpan_transaksi_utang_beserta_jatuh_tempo_dan_pihak_kepada(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(KeuanganInputTransaksiV2::class)
            ->fillForm([
                'account_id' => $this->golio->id,
                'period' => '2026-10',
                'transaction_date' => '2026-10-07',
                'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
                'category_id' => $this->expenseCategory->id,
                'amount' => '500000',
                'party_id' => $this->pihak->id,
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_UTANG,
                'due_date' => '2026-10-15',
                'counterparty' => 'Supplier ABC',
            ])
            ->call('simpan')
            ->assertHasNoFormErrors();

        $transaction = FinancialTransaction::firstOrFail();

        $this->assertTrue($transaction->is_utang);
        $this->assertSame('Utang', $transaction->payment_status_label);
        $this->assertSame('2026-10-15', $transaction->due_date->toDateString());
        $this->assertSame('Supplier ABC', $transaction->counterparty);
    }

    public function test_input_v2_menolak_transaksi_tanpa_akun_kategori_dan_pihak(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(KeuanganInputTransaksiV2::class)
            ->fillForm([
                'amount' => '100000',
                'transaction_date' => '2026-10-07',
            ])
            ->call('simpan')
            ->assertHasFormErrors([
                'account_id' => 'required',
                'category_id' => 'required',
                'party_id' => 'required',
            ]);

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_input_v2_menolak_nominal_nol_dan_negatif(): void
    {
        $this->actingAs($this->managerFinance);

        foreach ([0, -5000] as $nominal) {
            Livewire::test(KeuanganInputTransaksiV2::class)
                ->fillForm([
                    'account_id' => $this->janusEkspansi->id,
                    'period' => '2026-10',
                    'transaction_date' => '2026-10-07',
                    'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
                    'category_id' => $this->expenseCategory->id,
                    'amount' => $nominal,
                    'party_id' => $this->pihak->id,
                    'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                ])
                ->call('simpan')
                ->assertHasFormErrors(['amount']);
        }

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_input_v2_mewajibkan_jatuh_tempo_dan_pihak_kepada_saat_status_utang(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(KeuanganInputTransaksiV2::class)
            ->fillForm([
                'account_id' => $this->express->id,
                'period' => '2026-10',
                'transaction_date' => '2026-10-07',
                'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
                'category_id' => $this->expenseCategory->id,
                'amount' => '100000',
                'party_id' => $this->pihak->id,
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_UTANG,
                'due_date' => null,
                'counterparty' => null,
            ])
            ->call('simpan')
            ->assertHasFormErrors([
                'due_date' => 'required',
                'counterparty' => 'required',
            ]);

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_input_v2_menolak_kategori_yang_tidak_sesuai_jenis_transaksi(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(KeuanganInputTransaksiV2::class)
            ->fillForm([
                'account_id' => $this->express->id,
                'period' => '2026-10',
                'transaction_date' => '2026-10-07',
                'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
                'category_id' => $this->incomeCategory->id,
                'amount' => '100000',
                'party_id' => $this->pihak->id,
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
            ])
            ->call('simpan')
            ->assertHasFormErrors(['category_id']);

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_input_v2_menolak_akun_jenis_status_dan_pihak_yang_tidak_valid(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(KeuanganInputTransaksiV2::class)
            ->fillForm([
                'account_id' => 99999,
                'period' => '2026-10',
                'transaction_date' => '2026-10-07',
                'transaction_type' => 'transfer',
                'category_id' => $this->expenseCategory->id,
                'amount' => '100000',
                'party_id' => 99999,
                'payment_status' => 'belum-bayar',
            ])
            ->call('simpan')
            ->assertHasFormErrors([
                'account_id' => 'exists',
                'transaction_type' => 'in',
                'party_id' => 'exists',
                'payment_status' => 'in',
            ]);

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_halaman_v2_menampilkan_pilihan_akun_dan_label_pihak_dinamis(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(KeuanganInputTransaksiV2::class)
            ->assertSuccessful()
            ->assertSee('Photomate Express')
            ->assertSee('Golio')
            ->assertSee('Janus')
            ->assertSee('Diterima Oleh')
            ->fillForm(['transaction_type' => FinancialTransaction::TYPE_EXPENSE])
            ->assertSee('Dibayarkan Oleh')
            ->assertDontSee('Diterima Oleh');
    }

    public function test_input_v2_mempertahankan_akun_periode_dan_jenis_setelah_simpan(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(KeuanganInputTransaksiV2::class)
            ->fillForm([
                'account_id' => $this->express->id,
                'period' => '2026-10',
                'transaction_date' => '2026-10-07',
                'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
                'category_id' => $this->expenseCategory->id,
                'amount' => '100000',
                'party_id' => $this->pihak->id,
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_UTANG,
                'due_date' => '2026-10-15',
                'counterparty' => 'Supplier ABC',
                'description' => 'Pembelian kertas',
            ])
            ->call('simpan')
            ->assertHasNoFormErrors()
            ->assertSet('data.account_id', $this->express->id)
            ->assertSet('data.period', '2026-10')
            ->assertSet('data.transaction_type', FinancialTransaction::TYPE_EXPENSE)
            ->assertSet('data.transaction_date', fn ($value): bool => Carbon::parse($value)->isToday())
            ->assertSet('data.amount', null)
            ->assertSet('data.category_id', null)
            ->assertSet('data.party_id', null)
            ->assertSet('data.payment_status', FinancialTransaction::PAYMENT_STATUS_LUNAS)
            ->assertSet('data.due_date', null)
            ->assertSet('data.counterparty', null)
            ->assertSet('data.description', null)
            ->assertSet('data.evidence_path', []);
    }

    public function test_transaksi_v2_muncul_di_halaman_transaksi_dan_laporan(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(KeuanganInputTransaksiV2::class)
            ->fillForm([
                'account_id' => $this->express->id,
                'period' => Carbon::today()->format('Y-m'),
                'transaction_date' => Carbon::today()->toDateString(),
                'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
                'category_id' => $this->expenseCategory->id,
                'amount' => '381.550',
                'party_id' => $this->pihak->id,
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_UTANG,
                'due_date' => Carbon::today()->addDays(7)->toDateString(),
                'counterparty' => 'Supplier ABC',
            ])
            ->call('simpan')
            ->assertHasNoFormErrors();

        $transaction = FinancialTransaction::firstOrFail();

        Livewire::test(ListFinancialTransactions::class)
            ->assertCanSeeTableRecords([$transaction])
            ->filterTable('account_id', ['value' => $this->express->id])
            ->assertCanSeeTableRecords([$transaction]);

        Livewire::test(KeuanganReport::class)
            ->assertCanSeeTableRecords([$transaction]);
    }

    public function test_periode_transaksi_lama_tanpa_kolom_periode_memakai_bulan_tanggal_transaksi(): void
    {
        $this->actingAs($this->managerFinance);

        $transaction = FinancialTransaction::create([
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => 150000,
            'category_id' => $this->expenseCategory->id,
            'payment_method_id' => $this->cash->id,
            'transaction_date' => '2026-09-05',
        ]);

        $this->assertNull($transaction->period_month);
        $this->assertNull($transaction->period_year);
        $this->assertSame('September 2026', $transaction->period_label);
    }

    public function test_halaman_input_transaksi_lama_tetap_berjalan_tanpa_field_v2(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(KeuanganInputTransaksi::class)
            ->assertSuccessful()
            ->fillForm([
                'transaction_type' => FinancialTransaction::TYPE_INCOME,
                'amount' => '2.000.000',
                'category_id' => $this->incomeCategory->id,
                'transaction_date' => Carbon::today()->toDateString(),
                'payment_method_id' => $this->cash->id,
                'description' => 'Pelunasan Event A',
            ])
            ->call('simpan')
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertNoRedirect();

        $transaction = FinancialTransaction::firstOrFail();

        $this->assertSame(2000000, $transaction->amount);
        $this->assertNull($transaction->account_id);
        $this->assertNull($transaction->party_id);
        $this->assertSame(FinancialTransaction::PAYMENT_STATUS_LUNAS, $transaction->payment_status);
    }

    public function test_halaman_input_transaksi_lama_tetap_tersedia_di_menu_keuangan(): void
    {
        $this->actingAs($this->managerFinance);

        $this->get('/admin/keuangan-input-transaksi')->assertOk()->assertSee('Input Transaksi');
        $this->get('/admin/keuangan-input-transaksi-v2')->assertOk()->assertSee('Input Transaksi V2');

        $this->assertTrue(KeuanganInputTransaksi::canAccess());
        $this->assertTrue(KeuanganInputTransaksi::shouldRegisterNavigation());
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function makeUser(
        string $karyawanId,
        string $roleName,
        string $roleId,
        string $email,
        array $permissions
    ): Karyawan {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'], ['role_id' => $roleId]);
        $role->syncPermissions($permissions);

        $user = Karyawan::create([
            'karyawan_id' => $karyawanId,
            'role_id' => $roleId,
            'perusahaan_id' => 'P0001',
            'nik' => str_pad((string) crc32($karyawanId), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => $roleName . ' Uji',
            'email' => $email,
            'password' => bcrypt('password'),
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jakarta',
        ]);

        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->refresh();
    }
}
