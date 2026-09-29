<?php

namespace Tests\Feature;

use App\Filament\Resources\FinancialTransactionResource;
use App\Filament\Resources\FinancialTransactionResource\Pages\ListFinancialTransactions;
use App\Models\Cabang;
use App\Models\FinancialAuditLog;
use App\Models\FinancialCategory;
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

class KeuanganTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected Karyawan $managerFinance;

    protected Karyawan $accountPayment;

    protected FinancialCategory $incomeCategory;

    protected FinancialCategory $expenseCategory;

    protected FinancialPaymentMethod $cash;

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

        Cabang::create([
            'cabang_id' => 'C0005',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Photomate Express',
        ]);

        Cabang::create([
            'cabang_id' => 'C0004',
            'perusahaan_id' => 'P0001',
            'nama_cabang' => 'Newspaper Janus',
        ]);

        $this->incomeCategory = FinancialCategory::where('name', 'Pelunasan')
            ->where('transaction_type', FinancialCategory::TYPE_INCOME)
            ->firstOrFail();

        $this->expenseCategory = FinancialCategory::where('name', 'Tinta')
            ->where('transaction_type', FinancialCategory::TYPE_EXPENSE)
            ->firstOrFail();

        $this->cash = FinancialPaymentMethod::where('name', 'Cash')->firstOrFail();

        $this->managerFinance = $this->makeUser('K0001', 'Manager Finance', 'R04', 'manager.finance@photomate.id', [
            'view_any_keuangan_transaction',
            'view_keuangan_transaction',
            'create_keuangan_transaction',
            'update_keuangan_transaction',
            'delete_keuangan_transaction',
            'restore_keuangan_transaction',
            'force_delete_keuangan_transaction',
            'export_keuangan_report',
        ]);

        $this->accountPayment = $this->makeUser('K0002', 'Account Payment', 'R05', 'account.payment@photomate.id', [
            'view_any_keuangan_transaction',
            'view_keuangan_transaction',
            'create_keuangan_transaction',
            'update_own_keuangan_transaction',
            'delete_own_keuangan_transaction',
        ]);
    }

    public function test_manager_finance_dapat_membuat_transaksi_pemasukan_via_modal(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(ListFinancialTransactions::class)
            ->callAction('create', [
                'transaction_type' => FinancialTransaction::TYPE_INCOME,
                'amount' => '2.000.000',
                'category_id' => $this->incomeCategory->id,
                'transaction_date' => Carbon::today()->toDateString(),
                'cabang_id' => 'C0005',
                'payment_method_id' => $this->cash->id,
                'description' => 'Pelunasan Event A',
            ])
            ->assertHasNoActionErrors();

        $transaction = FinancialTransaction::firstOrFail();

        $this->assertSame(FinancialTransaction::TYPE_INCOME, $transaction->transaction_type);
        $this->assertSame(2000000, $transaction->amount);
        $this->assertSame('C0005', $transaction->cabang_id);
        $this->assertSame('Pelunasan Event A', $transaction->description);
        $this->assertSame($this->managerFinance->karyawan_id, $transaction->created_by);

        $this->assertDatabaseHas('financial_audit_logs', [
            'financial_transaction_id' => $transaction->id,
            'user_id' => $this->managerFinance->karyawan_id,
            'action' => FinancialTransaction::ACTION_CREATED,
        ]);
    }

    public function test_nominal_masked_dari_browser_tersimpan_sebagai_integer(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(ListFinancialTransactions::class)
            ->callAction('create', [
                'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
                'amount' => '381.550',
                'category_id' => $this->expenseCategory->id,
                'transaction_date' => Carbon::today()->toDateString(),
                'payment_method_id' => $this->cash->id,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(381550, FinancialTransaction::firstOrFail()->amount);
        $this->assertSame('integer', FinancialTransaction::firstOrFail()->getCasts()['amount']);
    }

    public function test_nominal_harus_lebih_dari_nol(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(ListFinancialTransactions::class)
            ->callAction('create', [
                'transaction_type' => FinancialTransaction::TYPE_INCOME,
                'amount' => 0,
                'category_id' => $this->incomeCategory->id,
                'transaction_date' => Carbon::today()->toDateString(),
                'payment_method_id' => $this->cash->id,
            ])
            ->assertHasActionErrors(['amount']);

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_kategori_wajib_dipilih(): void
    {
        $this->actingAs($this->managerFinance);

        Livewire::test(ListFinancialTransactions::class)
            ->callAction('create', [
                'transaction_type' => FinancialTransaction::TYPE_INCOME,
                'amount' => 100000,
                'category_id' => null,
                'transaction_date' => Carbon::today()->toDateString(),
                'payment_method_id' => $this->cash->id,
            ])
            ->assertHasActionErrors(['category_id']);

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_transaction_date_berbeda_dari_created_at(): void
    {
        $this->actingAs($this->managerFinance);

        $tanggalTransaksi = Carbon::today()->subDays(4)->toDateString();

        Livewire::test(ListFinancialTransactions::class)
            ->callAction('create', [
                'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
                'amount' => 100000,
                'category_id' => $this->expenseCategory->id,
                'transaction_date' => $tanggalTransaksi,
                'payment_method_id' => $this->cash->id,
            ])
            ->assertHasNoActionErrors();

        $transaction = FinancialTransaction::firstOrFail();

        $this->assertSame($tanggalTransaksi, $transaction->transaction_date->toDateString());
        $this->assertNotSame($transaction->transaction_date->toDateString(), $transaction->created_at->toDateString());
    }

    public function test_edit_transaksi_mencatat_audit_log_perubahan(): void
    {
        $this->actingAs($this->managerFinance);

        $transaction = $this->makeTransaction(500000, $this->expenseCategory->id);

        Livewire::test(ListFinancialTransactions::class)
            ->callTableAction('edit', $transaction, ['amount' => '550.000'])
            ->assertHasNoTableActionErrors();

        $transaction->refresh();
        $this->assertSame(550000, $transaction->amount);
        $this->assertSame($this->managerFinance->karyawan_id, $transaction->updated_by);

        $log = FinancialAuditLog::where('financial_transaction_id', $transaction->id)
            ->where('action', FinancialTransaction::ACTION_UPDATED)
            ->firstOrFail();

        $this->assertSame(500000, $log->old_data['amount']);
        $this->assertSame(550000, $log->new_data['amount']);
    }

    public function test_hapus_transaksi_soft_delete_dan_tercatat_di_audit_log(): void
    {
        $this->actingAs($this->managerFinance);

        $transaction = $this->makeTransaction(381550, $this->expenseCategory->id);

        Livewire::test(ListFinancialTransactions::class)
            ->callTableAction('delete', $transaction)
            ->assertHasNoTableActionErrors();

        $this->assertSoftDeleted('financial_transactions', ['id' => $transaction->id]);

        $this->assertDatabaseHas('financial_audit_logs', [
            'financial_transaction_id' => $transaction->id,
            'action' => FinancialTransaction::ACTION_DELETED,
        ]);
    }

    public function test_user_tanpa_permission_tidak_dapat_mengakses_modul_keuangan(): void
    {
        $staff = $this->makeUser('K0003', 'Staff HRD', 'R02', 'staff.hrd@photomate.id', ['view_any_karyawan']);

        $this->actingAs($staff)->get('/admin/keuangan-transaksi')->assertForbidden();

        $this->assertFalse(FinancialTransactionResource::canViewAny());
        $this->assertFalse(FinancialTransactionResource::shouldRegisterNavigation());
    }

    public function test_tombol_tambah_transaksi_tersembunyi_tanpa_permission_create(): void
    {
        $viewer = $this->makeUser('K0004', 'CEO', 'R06', 'ceo@photomate.id', [
            'view_any_keuangan_transaction',
            'view_keuangan_transaction',
        ]);

        $this->actingAs($viewer);

        $transaction = $this->makeTransaction(100000, $this->expenseCategory->id, 'C0005');

        Livewire::test(ListFinancialTransactions::class)
            ->assertActionHidden('create')
            ->assertTableActionHidden('edit', $transaction)
            ->assertTableActionHidden('delete', $transaction);
    }

    public function test_account_payment_hanya_dapat_mengubah_transaksi_miliknya(): void
    {
        $milikSendiri = $this->makeTransaction(
            200000,
            $this->expenseCategory->id,
            'C0005',
            $this->accountPayment->karyawan_id
        );

        $milikOrangLain = $this->makeTransaction(
            300000,
            $this->expenseCategory->id,
            'C0005',
            $this->managerFinance->karyawan_id
        );

        $this->actingAs($this->accountPayment);

        Livewire::test(ListFinancialTransactions::class)
            ->assertTableActionVisible('edit', $milikSendiri)
            ->assertTableActionHidden('edit', $milikOrangLain)
            ->assertTableActionVisible('delete', $milikSendiri)
            ->assertTableActionHidden('delete', $milikOrangLain);
    }

    public function test_filter_periode_default_adalah_bulan_ini(): void
    {
        $this->actingAs($this->managerFinance);

        $bulanIni = $this->makeTransaction(
            1000000,
            $this->incomeCategory->id,
            'C0005',
            null,
            Carbon::today()->toDateString()
        );

        $bulanLalu = $this->makeTransaction(
            500000,
            $this->incomeCategory->id,
            'C0005',
            null,
            Carbon::today()->subMonthNoOverflow()->toDateString()
        );

        Livewire::test(ListFinancialTransactions::class)
            ->assertCanSeeTableRecords([$bulanIni])
            ->assertCanNotSeeTableRecords([$bulanLalu]);
    }

    public function test_filter_cabang_dan_jenis_transaksi(): void
    {
        $this->actingAs($this->managerFinance);

        $express = $this->makeTransaction(2000000, $this->incomeCategory->id, 'C0005');
        $janus = $this->makeTransaction(381550, $this->expenseCategory->id, 'C0004');

        Livewire::test(ListFinancialTransactions::class)
            ->filterTable('cabang_id', ['value' => 'C0005'])
            ->assertCanSeeTableRecords([$express])
            ->assertCanNotSeeTableRecords([$janus]);
    }

    protected function makeTransaction(
        int $amount,
        int $categoryId,
        ?string $cabangId = null,
        ?string $createdBy = null,
        ?string $date = null
    ): FinancialTransaction {
        return FinancialTransaction::create([
            'transaction_type' => $categoryId === $this->incomeCategory->id
                ? FinancialTransaction::TYPE_INCOME
                : FinancialTransaction::TYPE_EXPENSE,
            'amount' => $amount,
            'category_id' => $categoryId,
            'cabang_id' => $cabangId,
            'payment_method_id' => $this->cash->id,
            'transaction_date' => $date ?? Carbon::today()->toDateString(),
            'description' => 'Transaksi uji',
            'created_by' => $createdBy,
        ]);
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
